<?php
if (!defined('ABSPATH')) exit;

/**
 * AI transcription and content generation pipeline.
 *
 * Two APIs, clean separation:
 *   AssemblyAI  — audio → transcript (async, polled via WP Cron)
 *   Claude Haiku — transcript → summary (post_content) + key points
 *                  (main_points repeater) + tags (post tags)
 *
 * Four episode states:
 *   complete   — has transcript + post_content + main_points — skip
 *   ai_only    — has transcript, missing content — Claude only (no AAI cost)
 *   transcribe — has Dropbox/SC audio, no transcript — full pipeline
 *   no_source  — no audio, no transcript — flag for manual review
 *
 * Credits:
 *   Legacy presenters (field_legacy_presenter = 1) — always free
 *   Others — 1 credit per episode processed (transcribe or ai_only)
 *   Credits stored in field_ai_credits on the presenter post
 */
class Aipex_Podcast_Transcription {

    const CRON_HOOK    = 'aipex_transcription_poll';
    const SCAN_OPTION  = 'aipex_transcription_scan';

    // -------------------------------------------------------------------------
    // cURL helper — bypasses WordPress HTTP which strips Authorization headers
    // -------------------------------------------------------------------------

    private static function curl_request($method, $url, $headers = [], $body = null){
        $ch = curl_init($url);
        $header_lines = [];
        foreach ($headers as $k => $v) $header_lines[] = $k.': '.$v;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_TIMEOUT           => 30,
            CURLOPT_HTTPHEADER        => $header_lines,
            CURLOPT_FOLLOWLOCATION    => true,
            CURLOPT_UNRESTRICTED_AUTH => true,
            CURLOPT_SSL_VERIFYPEER    => true,
            CURLOPT_CUSTOMREQUEST     => strtoupper($method),
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $response = (string)curl_exec($ch);
        $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);
        return ['code'=>$code,'body'=>$response,'data'=>json_decode($response,true),'error'=>$err];
    }

    // -------------------------------------------------------------------------
    // API credentials
    // -------------------------------------------------------------------------

    public static function assemblyai_key(){
        return trim(Aipex_Podcast_Crypto::decrypt(get_option('aipex_assemblyai_key','')));
    }

    public static function anthropic_key(){
        return trim(Aipex_Podcast_Crypto::decrypt(get_option('aipex_anthropic_key','')));
    }

    // -------------------------------------------------------------------------
    // Episode state detection
    // -------------------------------------------------------------------------

    public static function episode_state($post_id){
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'aipex_podcast') return 'invalid';

        $has_transcript  = !empty(Aipex_Podcast_Fields::get('transcript', $post_id));
        $has_content     = !empty(trim(strip_tags($post->post_content)));
        $has_main_points = !empty(get_field('main_points', $post_id));
        $has_audio       = !empty(Aipex_Podcast_Fields::get('dropbox_url', $post_id))
                        || !empty(Aipex_Podcast_Fields::get('soundcloud_url', $post_id))
                        || !empty(Aipex_Podcast_Fields::audio_url($post_id));

        if ($has_transcript && $has_content && $has_main_points) return 'complete';
        if ($has_transcript) return 'ai_only';      // has transcript, needs AI content
        if ($has_audio)      return 'transcribe';   // needs transcription first
        return 'no_source';
    }

    /**
     * Scan all episodes and return counts per state.
     * Stores results in an option so the Tools page can display
     * a cost estimate before anything is processed.
     */
    public static function scan_all(){
        $states = ['complete'=>[],'ai_only'=>[],'transcribe'=>[],'no_source'=>[]];
        $ids = get_posts(['post_type'=>'aipex_podcast','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids']);
        foreach ($ids as $id) $states[self::episode_state($id)][] = $id;

        // Cost estimate: AssemblyAI $0.15/hr + $0.03/hr summarisation add-on
        // Average 25 min episode = ~$0.075 per transcription
        // Claude Haiku for AI content: ~$0.001 per episode (negligible)
        $transcribe_cost_usd = count($states['transcribe']) * 0.075;
        update_option(self::SCAN_OPTION, array_merge(
            array_map('count', $states),
            ['transcribe_ids' => $states['transcribe'], 'ai_only_ids' => $states['ai_only'], 'scanned_at' => current_time('mysql'), 'est_cost_usd' => round($transcribe_cost_usd, 2)]
        ), false);
        return $states;
    }

    // -------------------------------------------------------------------------
    // Audio source resolution
    // -------------------------------------------------------------------------

    public static function get_audio_url($post_id){
        // Priority: WordPress upload → Dropbox (direct download) → SoundCloud stream
        $audio_file = Aipex_Podcast_Fields::get('audio_file', $post_id);
        if ($audio_file) return is_numeric($audio_file) ? wp_get_attachment_url($audio_file) : $audio_file;

        $dropbox = Aipex_Podcast_Fields::get('dropbox_url', $post_id);
        if ($dropbox) {
            // Ensure Dropbox URL is a direct download link
            $dropbox = preg_replace('/[?&]dl=0/', '', $dropbox);
            $dropbox .= (strpos($dropbox,'?') !== false ? '&' : '?').'dl=1';
            return $dropbox;
        }

        // SoundCloud stream URL requires OAuth token
        $sc_url = Aipex_Podcast_Fields::get('soundcloud_url', $post_id);
        if ($sc_url && class_exists('Aipex_Podcast_Soundcloud') && Aipex_Podcast_Soundcloud::is_connected()) {
            return self::resolve_sc_stream_url($sc_url);
        }

        return '';
    }

    private static function resolve_sc_stream_url($permalink_url){
        // Resolve SoundCloud page URL → track ID → stream URL via OAuth API
        $cache_key = 'aipex_sc_stream_'.md5($permalink_url);
        $cached = get_transient($cache_key);
        if ($cached) return $cached;

        $token = Aipex_Podcast_Soundcloud::access_token();
        if (!$token) return '';

        $r = wp_remote_get('https://api.soundcloud.com/resolve.json?url='.rawurlencode($permalink_url), [
            'timeout' => 10, 'headers' => ['Authorization' => 'OAuth '.$token],
        ]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) return '';
        $data = json_decode(wp_remote_retrieve_body($r), true);

        $stream = $data['stream_url'] ?? '';
        if ($stream) {
            $stream .= (strpos($stream,'?') !== false ? '&' : '?').'client_id='.Aipex_Podcast_Soundcloud::client_id();
            set_transient($cache_key, $stream, DAY_IN_SECONDS);
        }
        return $stream;
    }

    // -------------------------------------------------------------------------
    // AssemblyAI — submit transcription job
    // -------------------------------------------------------------------------

    public static function submit_transcription($post_id){
        $key = self::assemblyai_key();
        if (!$key) return ['error' => 'AssemblyAI key not set in Settings.'];

        $audio_url = self::get_audio_url($post_id);
        if (!$audio_url) return ['error' => 'No audio source found for this episode.'];

        $r = self::curl_request('POST', 'https://api.assemblyai.com/v2/transcript',
            ['Authorization' => $key, 'Content-Type' => 'application/json'],
            wp_json_encode(['audio_url' => $audio_url, 'language_detection' => true])
        );
        if ($r['code'] !== 200 || empty($r['data']['id'])) return ['error' => 'AssemblyAI error HTTP '.$r['code'].': '.($r['data']['error'] ?? $r['error'] ?: 'Unknown').' audio_url='.substr($audio_url,0,80)];
        $data = $r['data'];

        // Store job ID and mark as processing
        Aipex_Podcast_Fields::update('ai_job_id', $data['id'], $post_id);
        Aipex_Podcast_Fields::update('ai_status', 'processing', $post_id);

        // Ensure cron is scheduled for polling
        if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time() + 60, 'aipex_three_minutes', self::CRON_HOOK);

        return ['job_id' => $data['id'], 'status' => 'processing'];
    }

    // -------------------------------------------------------------------------
    // AssemblyAI — poll pending jobs via WP Cron
    // -------------------------------------------------------------------------

    public static function poll_pending_jobs(){
        $key = self::assemblyai_key();
        if (!$key) return;

        $jobs = get_posts([
            'post_type' => 'aipex_podcast', 'post_status' => 'any',
            'posts_per_page' => 20, 'fields' => 'ids',
            'meta_query' => [['key'=>'ai_status','value'=>'processing']],
        ]);

        foreach ($jobs as $post_id) {
            $job_id = Aipex_Podcast_Fields::get('ai_job_id', $post_id);
            if (!$job_id) continue;

            $r = self::curl_request('GET', 'https://api.assemblyai.com/v2/transcript/'.rawurlencode($job_id),
                ['Authorization' => $key]
            );
            if ($r['code'] !== 200 || !is_array($r['data'])) continue;
            $data = $r['data'];

            if ($data['status'] === 'completed') {
                $transcript = $data['text'] ?? '';
                if ($transcript) {
                    Aipex_Podcast_Fields::update('transcript', sanitize_textarea_field($transcript), $post_id);
                    Aipex_Podcast_Fields::update('ai_status', 'pending', $post_id); // pending Claude processing
                    self::generate_ai_content($post_id); // run Claude immediately
                }
            } elseif ($data['status'] === 'error') {
                Aipex_Podcast_Fields::update('ai_status', 'failed', $post_id);
                update_post_meta($post_id, 'ai_error', sanitize_text_field($data['error'] ?? 'Unknown error'));
            }
        }

        // Unschedule cron if no more pending jobs
        $still_pending = get_posts(['post_type'=>'aipex_podcast','post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','meta_query'=>[['key'=>'ai_status','value'=>'processing']]]);
        if (empty($still_pending)) wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    // -------------------------------------------------------------------------
    // Claude — generate summary, key points, tags from transcript
    // -------------------------------------------------------------------------

    public static function generate_ai_content($post_id){
        $key = self::anthropic_key();
        if (!$key) return ['error' => 'Anthropic key not set in Settings.'];

        $transcript = Aipex_Podcast_Fields::get('transcript', $post_id);
        if (!$transcript) return ['error' => 'No transcript found.'];

        $post = get_post($post_id);
        $title = $post ? $post->post_title : '';
        $series_ids = Aipex_Podcast_Relationships::shows_for(Aipex_Podcast_Relationships::TYPE_EPISODE, $post_id);
        $series_name = $series_ids ? get_the_title($series_ids[0]) : '';

        $prompt = "You are writing content for a women's radio station podcast episode page.\n\n"
            ."Episode title: ".($title ?: 'Unknown')."\n"
            .($series_name ? "Show: $series_name\n" : '')
            ."Transcript:\n"
            .mb_substr($transcript, 0, 12000)."\n\n"  // Haiku context limit safety
            ."Return ONLY a JSON object with exactly these three keys:\n"
            ."{\n"
            ."  \"summary\": \"2-3 paragraph show notes written in an engaging style for listeners. No headings, just paragraphs.\",\n"
            ."  \"key_points\": [\"First key point\", \"Second key point\"],\n"
            ."  \"tags\": [\"tag one\", \"tag two\"]\n"
            ."}\n\n"
            ."key_points: 5-7 bullet points of the main topics discussed.\n"
            ."tags: 10-15 relevant lowercase tags suitable for WordPress post tags (no # symbol, no duplicates).";

        $r = self::curl_request('POST', 'https://api.anthropic.com/v1/messages',
            ['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'Content-Type' => 'application/json'],
            wp_json_encode(['model'=>'claude-haiku-4-5','max_tokens'=>1500,'messages'=>[['role'=>'user','content'=>$prompt]]])
        );
        if ($r['code'] !== 200) return ['error' => 'Anthropic error HTTP '.$r['code'].': '.($r['data']['error']['message'] ?? $r['error'] ?: 'Unknown')];

        $text = $r['data']['content'][0]['text'] ?? '';
        // Strip any markdown fences
        $text = preg_replace('/^```json\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/', '', $text);
        $data = json_decode(trim($text), true);

        if (!is_array($data)) return ['error' => 'Claude returned invalid JSON: '.substr($text,0,200)];

        // --- Write results ---

        // Summary → post_content (only if currently empty)
        if (!empty($data['summary']) && empty(trim(strip_tags($post->post_content)))) {
            wp_update_post(['ID'=>$post_id, 'post_content'=>wp_kses_post($data['summary'])]);
        }

        // Key points → main_points repeater (only if no rows exist)
        $existing = get_field('main_points', $post_id);
        if (!empty($data['key_points']) && empty($existing)) {
            $rows = [];
            foreach ((array)$data['key_points'] as $point) {
                if (trim($point)) $rows[] = ['point' => sanitize_text_field(trim($point)), 'description' => ''];
            }
            if ($rows) update_field('field_main_points', $rows, $post_id);
        }

        // Tags → post tags (only if post has none)
        if (!empty($data['tags']) && !wp_get_post_tags($post_id)) {
            $tags = array_map('sanitize_text_field', (array)$data['tags']);
            $tags = array_filter($tags);
            if ($tags) wp_set_post_tags($post_id, $tags, false);
        }

        Aipex_Podcast_Fields::update('ai_status', 'complete', $post_id);
        delete_post_meta($post_id, 'ai_error');

        return ['status' => 'complete', 'summary_written' => !empty($data['summary']), 'points' => count($data['key_points'] ?? []), 'tags' => count($data['tags'] ?? [])];
    }

    // -------------------------------------------------------------------------
    // Credits system
    // -------------------------------------------------------------------------

    public static function presenter_for_episode($post_id){
        $host_ids = Aipex_Podcast_Relationships::hosts_for(Aipex_Podcast_Relationships::TYPE_EPISODE, $post_id);
        return $host_ids ? (int)$host_ids[0] : 0;
    }

    public static function is_legacy($presenter_id){
        if (!$presenter_id) return false;
        return (bool)get_field('legacy_presenter', $presenter_id);
    }

    public static function get_credits($presenter_id){
        return (int)get_field('ai_credits', $presenter_id);
    }

    public static function deduct_credit($presenter_id){
        $current = self::get_credits($presenter_id);
        update_field('field_ai_credits', max(0, $current - 1), $presenter_id);
    }

    public static function add_credits($presenter_id, $amount){
        $current = self::get_credits($presenter_id);
        update_field('field_ai_credits', $current + (int)$amount, $presenter_id);
    }

    public static function check_and_deduct($post_id){
        $presenter_id = self::presenter_for_episode($post_id);
        if (!$presenter_id) return true;  // no presenter — allow (batch/admin use)
        if (self::is_legacy($presenter_id)) return true; // legacy — always free
        $credits = self::get_credits($presenter_id);
        if ($credits <= 0) return false; // no credits
        self::deduct_credit($presenter_id);
        return true;
    }

    // -------------------------------------------------------------------------
    // WooCommerce credit top-up on order complete
    // -------------------------------------------------------------------------

    public static function woo_order_complete($order_id){
        $order = wc_get_order($order_id);
        if (!$order) return;
        $user_id = $order->get_user_id();
        if (!$user_id) return;

        // Find presenter post linked to this WP user
        $presenters = get_posts(['post_type'=>'aipex_presenter','post_status'=>'any','posts_per_page'=>1,'meta_query'=>[['key'=>'linked_user','value'=>$user_id]],'fields'=>'ids']);
        if (!$presenters) return;
        $presenter_id = $presenters[0];

        // Look for AI credit product items in the order
        foreach ($order->get_items() as $item) {
            $qty      = (int)$item->get_quantity();
            $product  = $item->get_product();
            if (!$product) continue;
            $credits_per_unit = (int)get_post_meta($product->get_id(), 'aipex_credits_per_unit', true);
            if ($credits_per_unit > 0) self::add_credits($presenter_id, $qty * $credits_per_unit);
        }
    }

    // -------------------------------------------------------------------------
    // AJAX — single episode process (admin button on edit screen)
    // -------------------------------------------------------------------------

    public static function ajax_process_episode(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'],403);
        check_ajax_referer('aipex_transcription','nonce');
        $post_id = (int)($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error(['message'=>'No post ID.']);

        if (!self::check_and_deduct($post_id)) wp_send_json_error(['message'=>'No AI credits remaining. Purchase credits to continue.']);

        $state = self::episode_state($post_id);
        if ($state === 'complete') wp_send_json_success(['message'=>'Already complete — nothing to do.','state'=>$state]);
        if ($state === 'no_source') wp_send_json_error(['message'=>'No audio source or transcript found for this episode.']);

        if ($state === 'ai_only') {
            $result = self::generate_ai_content($post_id);
            if (isset($result['error'])) wp_send_json_error(['message'=>$result['error']]);
            wp_send_json_success(array_merge($result, ['state'=>'complete','message'=>'AI content generated.']));
        }

        // state === transcribe
        $result = self::submit_transcription($post_id);
        if (isset($result['error'])) wp_send_json_error(['message'=>$result['error']]);
        wp_send_json_success(['message'=>'Transcription submitted. Check back in a few minutes — the page will update automatically.','state'=>'processing','job_id'=>$result['job_id']]);
    }

    // -------------------------------------------------------------------------
    // AJAX — batch scanner (Tools & Scanners)
    // -------------------------------------------------------------------------

    public static function ajax_scan(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'],403);
        check_ajax_referer('aipex_transcription','nonce');
        $states = self::scan_all();
        $scan = get_option(self::SCAN_OPTION,[]);
        wp_send_json_success([
            'complete'    => count($states['complete']),
            'ai_only'     => count($states['ai_only']),
            'transcribe'  => count($states['transcribe']),
            'no_source'   => count($states['no_source']),
            'est_cost_usd'=> $scan['est_cost_usd'] ?? 0,
            'est_cost_gbp'=> round(($scan['est_cost_usd'] ?? 0) * 0.79, 2),
        ]);
    }

    public static function ajax_batch_start(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'],403);
        check_ajax_referer('aipex_transcription','nonce');
        $type  = sanitize_key($_POST['batch_type'] ?? 'ai_only');
        $limit = max(1, min(500, (int)($_POST['batch_limit'] ?? 0))); // 0 = no limit

        $scan = get_option(self::SCAN_OPTION,[]);
        $ids  = $scan[$type.'_ids'] ?? [];
        if (!$ids) wp_send_json_error(['message'=>'No episodes in this category. Run the scanner first.']);

        // Cap to limit if set (e.g. 50 for a test run)
        if ($limit > 0) $ids = array_slice($ids, 0, $limit);

        set_transient('aipex_batch_'.$type, ['ids'=>$ids,'offset'=>0,'done'=>0,'failed'=>0,'total'=>count($ids)], 2*HOUR_IN_SECONDS);
        wp_send_json_success(self::run_batch_step($type));
    }

    public static function ajax_batch_continue(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'],403);
        check_ajax_referer('aipex_transcription','nonce');
        $type = sanitize_key($_POST['batch_type'] ?? 'ai_only');
        $state = get_transient('aipex_batch_'.$type);
        if (!$state) wp_send_json_error(['message'=>'No batch in progress.']);
        wp_send_json_success(self::run_batch_step($type));
    }

    private static function run_batch_step($type, $batch_size = 5){
        $state = get_transient('aipex_batch_'.$type);
        if (!$state) return ['finished'=>true,'done'=>0,'total'=>0,'failed'=>0,'log'=>[]];

        $slice = array_slice($state['ids'], $state['offset'], $batch_size);
        $log   = [];

        foreach ($slice as $post_id) {
            $title = get_the_title($post_id);
            if ($type === 'ai_only') {
                $result = self::generate_ai_content($post_id);
                if (isset($result['error'])) { $state['failed']++; $log[] = 'FAIL: '.mb_substr($title,0,50).' — '.$result['error']; }
                else { $state['done']++; $log[] = 'DONE: '.mb_substr($title,0,50).' ('.$result['points'].' points, '.$result['tags'].' tags)'; }
            } else {
                $result = self::submit_transcription($post_id);
                if (isset($result['error'])) {
                    $state['failed']++;
                    $log[] = 'FAIL: '.mb_substr($title,0,50).' — '.$result['error'];
                } else {
                    $state['done']++;
                    $log[] = 'SUBMITTED: '.mb_substr($title,0,50).' (job: '.$result['job_id'].')';
                }
                usleep(300000); // 300ms pause between AssemblyAI submissions
            }
        }

        $state['offset'] += $batch_size;
        $finished = $state['offset'] >= $state['total'];
        $pct = min(100, (int)round(100 * $state['offset'] / max(1,$state['total'])));

        if ($finished) delete_transient('aipex_batch_'.$type);
        else set_transient('aipex_batch_'.$type, $state, 2*HOUR_IN_SECONDS);

        return ['done'=>$state['done'],'failed'=>$state['failed'],'total'=>$state['total'],'pct'=>$pct,'finished'=>$finished,'log'=>$log];
    }

    // -------------------------------------------------------------------------
    // AJAX — test AssemblyAI key and manual poll trigger
    // -------------------------------------------------------------------------

    public static function ajax_test_assemblyai(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'],403);
        check_ajax_referer('aipex_transcription','nonce');
        $key = self::assemblyai_key();
        if (!$key) wp_send_json_error(['message'=>'No AssemblyAI key set. Go to Settings → AI & Transcription.']);

        // Hit their /v2/transcript endpoint with an empty list request — safe way to verify auth
        $r = self::curl_request('GET', 'https://api.assemblyai.com/v2/transcript?limit=1', ['Authorization'=>$key]);
        if ($r['code'] === 200) {
            $count = count($r['data']['transcripts'] ?? []);
            wp_send_json_success(['message'=>'AssemblyAI key valid ✓ (HTTP 200, '.$count.' recent transcripts in account)']);
        } elseif ($r['code'] === 401) {
            wp_send_json_error(['message'=>'Invalid API key — AssemblyAI returned 401. Check the key in Settings.']);
        } else {
            wp_send_json_error(['message'=>'Unexpected response HTTP '.$r['code'].': '.substr($r['body'],0,200)]);
        }
    }

    public static function ajax_poll_now(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'],403);
        check_ajax_referer('aipex_transcription','nonce');
        $pending = get_posts(['post_type'=>'aipex_podcast','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','meta_query'=>[['key'=>'ai_status','value'=>'processing']]]);
        if (!$pending) wp_send_json_error(['message'=>'No episodes currently processing. Submit a transcription batch first.']);
        self::poll_pending_jobs();
        $still = get_posts(['post_type'=>'aipex_podcast','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','meta_query'=>[['key'=>'ai_status','value'=>'processing']]]);
        wp_send_json_success(['message'=>'Poll complete. '.count($still).' jobs still processing. Refresh to see updated statuses.','pending_before'=>count($pending),'pending_after'=>count($still)]);
    }

    // -------------------------------------------------------------------------
    // Register WP Cron interval
    // -------------------------------------------------------------------------

    public static function register_cron_interval($schedules){
        $schedules['aipex_three_minutes'] = ['interval'=>180,'display'=>'Every 3 Minutes'];
        return $schedules;
    }

    // -------------------------------------------------------------------------
    // Summary migration tool
    // -------------------------------------------------------------------------

    public static function migrate_summaries_batch($offset=0, $batch_size=50){
        $post_types = [
            'aipex_podcast'   => 'episode_summary',
            'aipex_series'    => 'series_overview',
            'aipex_presenter' => 'presenter_about',
            'aipex_sponsor'   => 'sponsor_description',
            'aipex_guest'     => 'guest_bio',
        ];
        $posts = get_posts(['post_type'=>array_keys($post_types),'post_status'=>'any','posts_per_page'=>$batch_size,'offset'=>$offset,'fields'=>'all']);
        $migrated=0; $skipped=0; $log=[];

        foreach ($posts as $post) {
            $field_name = $post_types[$post->post_type] ?? '';
            $acf_value  = $field_name ? get_field($field_name, $post->ID) : '';
            if (!$acf_value) { $skipped++; continue; }
            if (!empty(trim(strip_tags($post->post_content)))) {
                $skipped++;
                $log[] = 'SKIP (has content): '.mb_substr($post->post_title,0,50);
                continue;
            }
            wp_update_post(['ID'=>$post->ID,'post_content'=>wp_kses_post($acf_value)]);
            $migrated++;
            $log[] = 'MIGRATED: '.mb_substr($post->post_title,0,50);
        }

        $total = array_sum(array_map(function($pt){ return wp_count_posts($pt)->publish ?? 0; }, array_keys($post_types)));
        $finished = ($offset + $batch_size) >= $total;
        return ['migrated'=>$migrated,'skipped'=>$skipped,'offset'=>$offset+$batch_size,'total'=>$total,'finished'=>$finished,'pct'=>min(100,(int)round(100*($offset+$batch_size)/max(1,$total))),'log'=>$log];
    }

    public static function ajax_migrate_summaries(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'],403);
        check_ajax_referer('aipex_transcription','nonce');
        $offset = (int)($_POST['offset'] ?? 0);
        wp_send_json_success(self::migrate_summaries_batch($offset));
    }
}
