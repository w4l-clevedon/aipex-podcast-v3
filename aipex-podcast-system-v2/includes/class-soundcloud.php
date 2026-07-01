<?php
if (!defined('ABSPATH')) exit;

/**
 * SoundCloud integration — OAuth authenticated API, show-filtered batch
 * importer, and new episode draft creation.
 *
 * Credentials: Client ID + Client Secret are stored encrypted in wp_options
 * via the Settings page. The client credentials OAuth flow exchanges these for
 * a short-lived access token (cached as a transient) used for all API calls.
 * Credentials are NEVER committed to the repository.
 */
class Aipex_Podcast_Soundcloud {

    const REVIEW_OPTION      = 'aipex_sc_review';        // uncertain/unmatched tracks
    const NEW_EP_OPTION      = 'aipex_sc_new_episodes';  // matched to show, no existing episode
    const STATE_KEY          = 'aipex_sc_import_state';
    const INDEX_OPTION       = 'aipex_sc_track_index';

    // -------------------------------------------------------------------------
    // Credentials
    // -------------------------------------------------------------------------

    public static function client_id(){
        return trim(Aipex_Podcast_Crypto::decrypt(get_option('aipex_sc_client_id', '')));
    }

    public static function client_secret(){
        return trim(Aipex_Podcast_Crypto::decrypt(get_option('aipex_sc_client_secret', '')));
    }

    public static function username(){
        return trim(get_option('aipex_sc_username', ''));
    }

    /**
     * Gets an OAuth access token via client credentials flow.
     * Cached in a transient until 60 seconds before expiry.
     */
    private static function get_access_token(){
        $cached = get_transient('aipex_sc_access_token');
        if ($cached) return $cached;

        $client_id     = self::client_id();
        $client_secret = self::client_secret();
        if (!$client_id || !$client_secret) return null;

        $response = wp_remote_post('https://secure.soundcloud.com/oauth/token', [
            'timeout' => 15,
            'body'    => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
            ],
            'user-agent' => 'Aipex Podcast System/1.0',
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;
        $data  = json_decode(wp_remote_retrieve_body($response), true);
        $token = $data['access_token'] ?? null;
        if ($token) set_transient('aipex_sc_access_token', $token, max(60, (int)($data['expires_in'] ?? 3600) - 60));
        return $token;
    }

    // -------------------------------------------------------------------------
    // API
    // -------------------------------------------------------------------------

    private static function api_get($url){
        $client_id = self::client_id();
        $token     = self::get_access_token();

        // Append client_id to every URL as a fallback
        if ($client_id && !(strpos($url, 'client_id=') !== false)) {
            $url .= ((strpos($url, '?') !== false) ? '&' : '?').'client_id='.rawurlencode($client_id);
        }

        $headers = $token ? ['Authorization' => 'OAuth '.$token] : [];

        $response = wp_remote_get($url, [
            'timeout'    => 15,
            'headers'    => $headers,
            'user-agent' => 'Aipex Podcast System/1.0',
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) ? $body : null;
    }

    public static function resolve_user_id(){
        $cached = get_transient('aipex_sc_user_id');
        if ($cached) return (int)$cached;
        $username = self::username();
        if (!$username) return 0;

        // Try v2 first, fall back to v1 if it fails
        $profile_url = 'https://soundcloud.com/'.rawurlencode($username);

        $data = self::api_get('https://api-v2.soundcloud.com/resolve?url='.rawurlencode($profile_url));
        if (!$data || empty($data['id'])) {
            // v1 fallback — works with client_id on older app registrations
            $data = self::api_get('https://api.soundcloud.com/resolve.json?url='.rawurlencode($profile_url));
        }

        if (!$data || empty($data['id'])) return 0;
        $id = (int)$data['id'];
        set_transient('aipex_sc_user_id', $id, 7 * DAY_IN_SECONDS);
        return $id;
    }

    public static function ajax_test_connection(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_sc_test','nonce');

        $client_id     = self::client_id();
        $client_secret = self::client_secret();
        $username      = self::username();
        $log           = [];

        if (!$client_id) wp_send_json_error(['message'=>'No Client ID saved. Go to Settings → SoundCloud.']);
        if (!$username)  wp_send_json_error(['message'=>'No Username saved. Go to Settings → SoundCloud.']);

        $log[] = 'Client ID: '.substr($client_id,0,6).'… ('.strlen($client_id).' chars)';
        $log[] = 'Client Secret: '.($client_secret ? substr($client_secret,0,4).'… ('.strlen($client_secret).' chars)' : 'NOT SET — enter it in Settings → SoundCloud');
        $log[] = 'Username: '.$username;

        // Test OAuth if secret is present
        $token = null;
        if ($client_secret) {
            delete_transient('aipex_sc_access_token');
            $oauth_resp = wp_remote_post('https://secure.soundcloud.com/oauth/token', [
                'timeout' => 15,
                'body'    => ['grant_type'=>'client_credentials','client_id'=>$client_id,'client_secret'=>$client_secret],
                'user-agent' => 'Aipex Podcast System/1.0',
            ]);
            $oauth_code = is_wp_error($oauth_resp) ? 'WP_Error: '.$oauth_resp->get_error_message() : wp_remote_retrieve_response_code($oauth_resp);
            $log[] = 'OAuth token request → HTTP '.$oauth_code;
            if (!is_wp_error($oauth_resp) && $oauth_code === 200) {
                $oauth_data = json_decode(wp_remote_retrieve_body($oauth_resp), true);
                $token = $oauth_data['access_token'] ?? null;
                $log[] = 'OAuth: '.($token ? 'token obtained ('.strlen($token).' chars)' : 'no access_token in response — body: '.substr(wp_remote_retrieve_body($oauth_resp),0,200));
                if ($token) set_transient('aipex_sc_access_token', $token, 3540);
            } else if (!is_wp_error($oauth_resp)) {
                $log[] = 'OAuth body: '.substr(wp_remote_retrieve_body($oauth_resp),0,200);
            }
        }

        // Test resolve — v2
        delete_transient('aipex_sc_user_id');
        $profile_url = 'https://soundcloud.com/'.rawurlencode($username);
        $v2_url = 'https://api-v2.soundcloud.com/resolve?url='.rawurlencode($profile_url).'&client_id='.rawurlencode($client_id);
        $headers = $token ? ['Authorization'=>'OAuth '.$token] : [];
        $v2_resp = wp_remote_get($v2_url, ['timeout'=>15,'headers'=>$headers,'user-agent'=>'Aipex Podcast System/1.0']);
        $v2_code = is_wp_error($v2_resp) ? 'WP_Error: '.$v2_resp->get_error_message() : wp_remote_retrieve_response_code($v2_resp);
        $log[] = 'Resolve v2 → HTTP '.$v2_code;
        $user_id = 0;
        if (!is_wp_error($v2_resp) && $v2_code === 200) {
            $v2_data = json_decode(wp_remote_retrieve_body($v2_resp), true);
            $user_id = (int)($v2_data['id'] ?? 0);
            $log[] = 'v2 user ID: '.($user_id ?: 'not found in response — body: '.substr(wp_remote_retrieve_body($v2_resp),0,200));
        } else if (!is_wp_error($v2_resp)) {
            $log[] = 'v2 body: '.substr(wp_remote_retrieve_body($v2_resp),0,200);
        }

        // Test resolve — v1 fallback
        if (!$user_id) {
            $v1_url = 'https://api.soundcloud.com/resolve.json?url='.rawurlencode($profile_url).'&client_id='.rawurlencode($client_id);
            $v1_resp = wp_remote_get($v1_url, ['timeout'=>15,'user-agent'=>'Aipex Podcast System/1.0']);
            $v1_code = is_wp_error($v1_resp) ? 'WP_Error: '.$v1_resp->get_error_message() : wp_remote_retrieve_response_code($v1_resp);
            $log[] = 'Resolve v1 → HTTP '.$v1_code;
            if (!is_wp_error($v1_resp) && $v1_code === 200) {
                $v1_data = json_decode(wp_remote_retrieve_body($v1_resp), true);
                $user_id = (int)($v1_data['id'] ?? 0);
                $log[] = 'v1 user ID: '.($user_id ?: 'not found — body: '.substr(wp_remote_retrieve_body($v1_resp),0,200));
            } else if (!is_wp_error($v1_resp)) {
                $log[] = 'v1 body: '.substr(wp_remote_retrieve_body($v1_resp),0,200);
            }
        }

        if ($user_id) {
            set_transient('aipex_sc_user_id', $user_id, 7 * DAY_IN_SECONDS);
            wp_send_json_success(['user_id'=>$user_id,'username'=>$username,'auth'=>($token?'OAuth token':'client_id only'),'log'=>$log]);
        }
        wp_send_json_error(['message'=>'Resolve failed. See log for details.','log'=>$log]);
    }

    public static function fetch_tracks_page($user_id, $next_href = null){
        $url = $next_href ?: 'https://api-v2.soundcloud.com/users/'.rawurlencode((string)$user_id).'/tracks?limit=200';
        // Strip client_id from next_href so api_get() re-appends correctly
        $url = preg_replace('/[?&]client_id=[^&]+/', '', $url);
        $url = rtrim($url, '?&');
        $data = self::api_get($url);
        if (!$data) return null;
        $tracks = $data['collection'] ?? $data;
        if (!is_array($tracks)) return null;
        return ['tracks' => $tracks, 'next_href' => $data['next_href'] ?? null];
    }

    // -------------------------------------------------------------------------
    // Embed URL helper
    // -------------------------------------------------------------------------

    public static function embed_url($permalink_url, $auto_play = false){
        $color = ltrim(sanitize_hex_color(Aipex_Podcast_Settings::get('brand_color')) ?: '#e4005a', '#');
        return 'https://w.soundcloud.com/player/?url='.rawurlencode($permalink_url)
            .'&color=%23'.rawurlencode($color)
            .'&auto_play='.($auto_play ? 'true' : 'false')
            .'&hide_related=true&show_comments=false&show_user=true&show_reposts=false&show_teaser=false';
    }

    // -------------------------------------------------------------------------
    // Series lookup — used as the show filter during matching
    // -------------------------------------------------------------------------

    private static function get_series_lookup(){
        static $lookup = null;
        if ($lookup !== null) return $lookup;
        $lookup = [];
        foreach (get_posts(['post_type'=>'aipex_series','post_status'=>'any','posts_per_page'=>-1]) as $s) {
            $lookup[$s->ID] = $s->post_title;
        }
        return $lookup;
    }

    private static function best_series_match($track_title, $min_score = 80){
        $series = self::get_series_lookup();
        $best_id = 0; $best_score = 0; $best_title = '';
        foreach ($series as $sid => $stitle) {
            $score = Aipex_Podcast_Fields::match_score($track_title, $stitle);
            if ($score > $best_score) { $best_score = $score; $best_id = $sid; $best_title = $stitle; }
        }
        return $best_score >= $min_score ? ['id'=>$best_id,'title'=>$best_title,'score'=>$best_score] : null;
    }

    private static function best_episode_match($track_title, $series_id){
        $episodes = get_posts([
            'post_type'=>'aipex_podcast','post_status'=>'any','posts_per_page'=>-1,'fields'=>'all',
            'meta_query'=>[['key'=>'soundcloud_url','compare'=>'NOT EXISTS']],
            'post__in' => Aipex_Podcast_Relationships::episodes_for(Aipex_Podcast_Relationships::TYPE_SHOW, $series_id) ?: [0],
        ]);
        $best = null; $best_score = 0;
        foreach ($episodes as $ep) {
            $score = Aipex_Podcast_Fields::match_score($track_title, $ep->post_title);
            if ($score > $best_score) { $best_score = $score; $best = $ep; }
        }
        return $best ? ['post'=>$best,'score'=>$best_score] : null;
    }

    // -------------------------------------------------------------------------
    // AJAX — batch import
    // -------------------------------------------------------------------------

    public static function ajax_import_start(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_sc_import','nonce');

        if (!self::client_id() || !self::username())
            wp_send_json_error(['message'=>'SoundCloud credentials not set. Go to Settings → SoundCloud.']);

        $user_id = self::resolve_user_id();
        if (!$user_id) wp_send_json_error(['message'=>'Could not resolve SoundCloud user. Check the username in Settings.']);

        delete_option(self::REVIEW_OPTION);
        delete_option(self::NEW_EP_OPTION);
        delete_option(self::INDEX_OPTION);
        delete_transient(self::STATE_KEY);

        $state = ['user_id'=>$user_id,'next_href'=>null,'fetched'=>0,'done'=>0,'linked'=>0,'review'=>0,'new_ep'=>0,'skipped'=>0,'phase'=>'fetch'];
        set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
        wp_send_json_success(array_merge(self::run_fetch_batch($state), ['log'=>['Authenticated. Fetching tracks from @'.self::username().'…']]));
    }

    public static function ajax_import_batch(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_sc_import','nonce');
        $state = get_transient(self::STATE_KEY);
        if (!$state || !is_array($state)) wp_send_json_error(['message'=>'No import in progress. Click Start.']);
        wp_send_json_success($state['phase'] === 'fetch' ? self::run_fetch_batch($state) : self::run_match_batch($state));
    }

    private static function run_fetch_batch($state){
        $page = self::fetch_tracks_page($state['user_id'], $state['next_href']);
        $log  = [];
        if (!$page) {
            $state['phase'] = 'match'; $state['match_offset'] = 0;
            $index = get_option(self::INDEX_OPTION, []);
            $state['match_total'] = count($index);
            set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
            $log[] = 'Fetch page failed. Proceeding to match with '.count($index).' tracks.';
            return ['phase'=>'fetch_error','fetched'=>$state['fetched'],'log'=>$log,'pct'=>50,'done'=>0,'total'=>0,'linked'=>0,'review'=>0,'new_ep'=>0,'skipped'=>0,'finished'=>false];
        }
        $index = get_option(self::INDEX_OPTION, []);
        if (!is_array($index)) $index = [];
        foreach ($page['tracks'] as $t) {
            if (!empty($t['title']) && !empty($t['permalink_url']))
                $index[] = ['title'=>$t['title'],'url'=>$t['permalink_url'],'created'=>$t['created_at']??''];
        }
        update_option(self::INDEX_OPTION, $index, false);
        $state['fetched']   = count($index);
        $state['next_href'] = $page['next_href'];
        $log[] = 'Fetched page — '.count($page['tracks']).' tracks, '.$state['fetched'].' total so far.';
        if (!$page['next_href']) {
            $state['phase'] = 'match'; $state['match_offset'] = 0; $state['match_total'] = count($index);
            $log[] = 'All tracks fetched ('.$state['fetched'].'). Starting show-filtered matching…';
        }
        set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
        return ['phase'=>'fetching','fetched'=>$state['fetched'],'log'=>$log,'pct'=>30,'done'=>0,'total'=>0,'linked'=>0,'review'=>0,'new_ep'=>0,'skipped'=>0,'finished'=>false];
    }

    private static function run_match_batch($state, $batch_size = 25){
        $index    = get_option(self::INDEX_OPTION, []);
        $review   = get_option(self::REVIEW_OPTION, []); if (!is_array($review)) $review = [];
        $new_eps  = get_option(self::NEW_EP_OPTION, []); if (!is_array($new_eps)) $new_eps = [];
        $log      = [];

        $slice = array_slice($index, $state['match_offset'] ?? 0, $batch_size);

        foreach ($slice as $track) {
            $state['done']++;
            $title = $track['title'];

            // ── Step 1: does this track belong to a show on the website? ──
            $series_match = self::best_series_match($title, 80);
            if (!$series_match) {
                $state['skipped']++;
                $log[] = 'SKIP (no show): '.mb_substr($title, 0, 60);
                continue;
            }

            // ── Step 2: is there an existing episode to link it to? ──
            $ep_match = self::best_episode_match($title, $series_match['id']);

            if ($ep_match && $ep_match['score'] >= 90) {
                update_post_meta($ep_match['post']->ID, 'soundcloud_url', esc_url_raw($track['url']));
                Aipex_Podcast_Relationships::sync_post($ep_match['post']->ID);
                $state['linked']++;
                $log[] = 'LINKED '.$ep_match['score'].'%: '.mb_substr($title,0,50).' → '.mb_substr($ep_match['post']->post_title,0,40);
            } elseif ($ep_match && $ep_match['score'] >= 60) {
                $review[] = ['episode_id'=>$ep_match['post']->ID,'episode_title'=>$ep_match['post']->post_title,'track_url'=>$track['url'],'track_title'=>$title,'score'=>$ep_match['score'],'series_id'=>$series_match['id'],'series_title'=>$series_match['title']];
                $state['review']++;
                $log[] = 'REVIEW '.$ep_match['score'].'%: '.mb_substr($title,0,50).' → '.$ep_match['post']->post_title.'?';
            } else {
                // Track matches a known show but no existing episode — candidate for draft creation
                $new_eps[] = ['track_title'=>$title,'track_url'=>$track['url'],'track_created'=>$track['created']??'','series_id'=>$series_match['id'],'series_title'=>$series_match['title'],'series_score'=>$series_match['score'],'presenter_id'=>0];
                $state['new_ep']++;
                $log[] = 'NEW EP: '.mb_substr($title,0,60).' (show: '.$series_match['title'].')';
            }
        }

        update_option(self::REVIEW_OPTION, $review, false);
        update_option(self::NEW_EP_OPTION, $new_eps, false);

        $state['match_offset'] = ($state['match_offset'] ?? 0) + $batch_size;
        $finished = $state['match_offset'] >= count($index);
        $pct      = min(100, (int)round(30 + 70 * $state['match_offset'] / max(1, count($index))));

        if ($finished) {
            delete_transient(self::STATE_KEY);
            $log[] = 'Done. Linked: '.$state['linked'].'. Review: '.$state['review'].'. New episode candidates: '.$state['new_ep'].'. Not on site: '.$state['skipped'].'.';
        } else {
            set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
        }

        return ['phase'=>'matching','fetched'=>$state['fetched'],'done'=>$state['done'],'total'=>count($index),'linked'=>$state['linked'],'review'=>$state['review'],'new_ep'=>$state['new_ep'],'skipped'=>$state['skipped'],'pct'=>$pct,'finished'=>$finished,'log'=>$log];
    }

    // -------------------------------------------------------------------------
    // AJAX — create draft episode from a new-episode candidate
    // -------------------------------------------------------------------------

    public static function ajax_create_draft(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_sc_draft','nonce');

        $index      = (int)($_POST['index'] ?? -1);
        $series_id  = (int)($_POST['series_id'] ?? 0);
        $presenter_id = (int)($_POST['presenter_id'] ?? 0);

        $new_eps = get_option(self::NEW_EP_OPTION, []);
        if (!isset($new_eps[$index])) wp_send_json_error(['message'=>'Item not found.']);

        $item = $new_eps[$index];
        $post_id = wp_insert_post([
            'post_type'   => 'aipex_podcast',
            'post_status' => 'draft',
            'post_title'  => sanitize_text_field($item['track_title']),
            'post_date'   => !empty($item['track_created']) ? date('Y-m-d H:i:s', strtotime($item['track_created'])) : current_time('mysql'),
        ]);
        if (is_wp_error($post_id)) wp_send_json_error(['message'=>$post_id->get_error_message()]);

        // Store SC URL
        update_post_meta($post_id, 'soundcloud_url', esc_url_raw($item['track_url']));

        // Assign show
        $show_id = $series_id ?: $item['series_id'];
        if ($show_id) {
            Aipex_Podcast_Fields::update('series', [$show_id], $post_id);
            Aipex_Podcast_Relationships::add(Aipex_Podcast_Relationships::TYPE_EPISODE, $post_id, Aipex_Podcast_Relationships::TYPE_SHOW, $show_id);
        }

        // Assign presenter if selected
        if ($presenter_id) {
            Aipex_Podcast_Fields::update('presenters', [$presenter_id], $post_id);
            Aipex_Podcast_Relationships::add(Aipex_Podcast_Relationships::TYPE_EPISODE, $post_id, Aipex_Podcast_Relationships::TYPE_HOST, $presenter_id);
        }

        // Remove from queue
        unset($new_eps[$index]);
        update_option(self::NEW_EP_OPTION, array_values($new_eps), false);

        wp_send_json_success(['post_id'=>$post_id,'edit_url'=>get_edit_post_link($post_id, 'raw'),'title'=>$item['track_title']]);
    }

    // -------------------------------------------------------------------------
    // Admin UI
    // -------------------------------------------------------------------------

    public static function render_ui(){
        $nonce        = wp_create_nonce('aipex_sc_import');
        $review_count = count(get_option(self::REVIEW_OPTION, []));
        $new_ep_count = count(get_option(self::NEW_EP_OPTION, []));
        if (!self::client_id() || !self::username()) {
            echo '<div class="notice notice-warning inline"><p>SoundCloud credentials not configured. Go to <a href="'.esc_url(admin_url('edit.php?post_type=aipex_podcast&page=aipex-podcast-settings')).'">Settings</a> and enter your Client ID, Client Secret and Username first.</p></div>';
            return;
        }
        $test_nonce = wp_create_nonce('aipex_sc_test');
        ?>
        <p>
            <button type="button" class="button" id="aipex-sc-test">Test Connection</button>
            <span id="aipex-sc-test-result" style="margin-left:10px;font-style:italic;color:#646970"></span>
        </p>
        <pre id="aipex-sc-test-log" style="display:none;background:#f6f7f7;padding:10px;max-height:200px;overflow:auto;white-space:pre-wrap;font-size:12px;margin-top:8px"></pre>
        <script>
        jQuery(function($){
            $('#aipex-sc-test').on('click', function(){
                var $btn=$(this), $r=$('#aipex-sc-test-result'), $log=$('#aipex-sc-test-log');
                $btn.prop('disabled',true); $r.text('Testing…'); $log.hide().text('');
                $.post(ajaxurl,{action:'aipex_sc_test',nonce:<?php echo wp_json_encode($test_nonce); ?>},function(resp){
                    $btn.prop('disabled',false);
                    var d=resp&&resp.data?resp.data:{};
                    if(resp&&resp.success){
                        $r.css('color','green').text('✓ Connected — user ID: '+d.user_id+' ('+d.username+') via '+d.auth);
                    } else {
                        $r.css('color','red').text('✗ '+(d.message||'Failed'));
                    }
                    if(d.log&&d.log.length){ $log.show().text(d.log.join('\n')); }
                }).fail(function(xhr){ $btn.prop('disabled',false); $r.css('color','red').text('HTTP '+xhr.status); });
            });
        });
        </script>
        <?php
        ?>
        <div id="aipex-sc-wrap" style="max-width:700px;margin-top:10px">
            <p>
                <button type="button" class="button button-primary" id="aipex-sc-start">Start Import</button>
                <button type="button" class="button" id="aipex-sc-stop" style="display:none">Stop</button>
                <?php if($review_count||$new_ep_count): ?>
                    <span style="margin-left:12px">
                        <?php if($review_count) echo '<strong>'.$review_count.' uncertain</strong> in review table. '; ?>
                        <?php if($new_ep_count) echo '<strong>'.$new_ep_count.' new episode candidates</strong> below.'; ?>
                    </span>
                <?php endif; ?>
                <span id="aipex-sc-status" style="margin-left:12px;color:#646970"></span>
            </p>
            <div style="height:16px;background:#f0f0f1;border-radius:20px;overflow:hidden;margin-bottom:8px">
                <div id="aipex-sc-bar" style="height:16px;width:0%;background:var(--aipex-brand,#e4005a);border-radius:20px;transition:width .3s ease"></div>
            </div>
            <pre id="aipex-sc-log" style="background:#f6f7f7;padding:10px;max-height:200px;overflow:auto;white-space:pre-wrap;font-size:12px"></pre>
        </div>
        <script>
        jQuery(function($){
            var running=false, nonce=<?php echo wp_json_encode($nonce); ?>;
            var $start=$('#aipex-sc-start'),$stop=$('#aipex-sc-stop');
            var $bar=$('#aipex-sc-bar'),$status=$('#aipex-sc-status'),$log=$('#aipex-sc-log');
            function log(msg){ $log.text($log.text()?$log.text()+'\n'+msg:msg); $log.scrollTop($log[0].scrollHeight); }
            function step(action){
                if(!running) return;
                $.post(ajaxurl,{action:action,nonce:nonce},function(resp){
                    if(!resp||!resp.success){ running=false; $start.prop('disabled',false); $stop.hide(); log('ERROR: '+(resp&&resp.data&&resp.data.message?resp.data.message:'Request failed')); return; }
                    var d=resp.data;
                    $bar.css('width',(d.pct||0)+'%');
                    $.each(d.log||[],function(_,l){ log(l); });
                    if(d.phase==='fetching'||d.phase==='fetch_error') $status.text('Fetching SC tracks… '+d.fetched+' so far');
                    else $status.text('Matching '+d.done+'/'+d.total+' — linked:'+d.linked+' review:'+d.review+' new:'+d.new_ep+' skipped:'+d.skipped);
                    if(d.finished){ running=false; $start.prop('disabled',false); $stop.hide(); $bar.css('width','100%'); $status.text('Complete. Refresh to see review and new episode tables.'); }
                    else setTimeout(function(){ step('aipex_sc_import_batch'); },300);
                }).fail(function(xhr){ running=false; $start.prop('disabled',false); $stop.hide(); log('AJAX failed: HTTP '+xhr.status); });
            }
            $start.on('click',function(){ running=true; $log.text(''); $bar.css('width','0%'); $status.text('Starting…'); $start.prop('disabled',true); $stop.show(); step('aipex_sc_import_start'); });
            $stop.on('click',function(){ running=false; $start.prop('disabled',false); $stop.hide(); $status.text('Stopped.'); });
        });
        </script>
        <?php
    }

    public static function render_review(){
        $items = get_option(self::REVIEW_OPTION, []);
        if (!$items) return;
        echo '<hr><h2>SoundCloud Matching — Needs Review ('.count($items).')</h2>';
        echo '<p><strong style="color:#b45309">⚠ 60–89%</strong> — uncertain match, URL pre-filled, verify it. <strong style="color:#b91c1c">✗ Below 60%</strong> — no confident episode match, paste URL manually. Leave unticked to skip.</p>';
        echo '<form method="post">'; wp_nonce_field('aipex_tools');
        echo '<p><button class="button button-primary" name="aipex_apply_sc_review" value="1">Apply Selected</button> ';
        echo '<button class="button" name="aipex_clear_sc_review" value="1" onclick="return confirm(\'Clear?\')">Clear List</button>';
        echo ' <label style="margin-left:16px"><input type="checkbox" id="aipex-sc-cb-all"> Select all</label></p>';
        echo '<table class="widefat striped"><thead><tr><th style="width:32px">✓</th><th>Episode</th><th style="width:55px">Score</th><th>SoundCloud URL</th></tr></thead><tbody>';
        foreach ($items as $i => $item) {
            $ep_id = (int)$item['episode_id'];
            $sc = $item['score'] >= 60 ? '#b45309' : '#b91c1c';
            $sl = $item['score'] >= 60 ? $item['score'].'%' : ($item['score'] ? $item['score'].'% ✗' : '—');
            echo '<tr>';
            echo '<td><input type="checkbox" class="aipex-sc-cb" name="sc_review['.$i.'][apply]" value="1"></td>';
            echo '<td><a href="'.esc_url(get_edit_post_link($ep_id)).'" target="_blank">'.esc_html($item['episode_title']).'</a>';
            if(!empty($item['track_title'])) echo '<br><small style="color:#646970">SC track: '.esc_html($item['track_title']).'</small>';
            echo '</td><td><strong style="color:'.esc_attr($sc).'">'.esc_html($sl).'</strong></td>';
            echo '<td><input type="url" name="sc_review['.$i.'][url]" value="'.esc_attr($item['track_url']).'" placeholder="https://soundcloud.com/..." style="width:100%"></td>';
            echo '</tr>';
        }
        echo '</tbody></table></form>';
        echo '<script>jQuery(function($){ $("#aipex-sc-cb-all").on("change",function(){ $(".aipex-sc-cb").prop("checked",this.checked); }); });</script>';
    }

    public static function render_new_episodes(){
        $items = get_option(self::NEW_EP_OPTION, []);
        if (!$items) return;
        $presenters = get_posts(['post_type'=>'aipex_presenter','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
        $series_all = get_posts(['post_type'=>'aipex_series','post_status'=>'any','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
        $draft_nonce = wp_create_nonce('aipex_sc_draft');
        echo '<hr><h2>New Episode Candidates ('.count($items).')</h2>';
        echo '<p>These SoundCloud tracks match a show on the website but have no existing episode post. Click <strong>Create Draft</strong> to create a draft episode post with the show and presenter pre-assigned.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Track</th><th>Matched Show</th><th>Assign Presenter</th><th style="width:130px"></th></tr></thead><tbody>';
        foreach ($items as $i => $item) {
            echo '<tr id="aipex-sc-new-'.esc_attr($i).'">';
            echo '<td><a href="'.esc_url($item['track_url']).'" target="_blank">'.esc_html($item['track_title']).'</a>';
            if (!empty($item['track_created'])) echo '<br><small style="color:#646970">'.esc_html(date('j M Y', strtotime($item['track_created']))).'</small>';
            echo '</td>';
            echo '<td>';
            echo '<select class="aipex-sc-series-sel" data-index="'.esc_attr($i).'">';
            foreach ($series_all as $s) echo '<option value="'.esc_attr($s->ID).'" '.selected($s->ID,(int)$item['series_id'],false).'>'.esc_html($s->post_title).'</option>';
            echo '</select>';
            echo '<br><small style="color:#646970">'.esc_html($item['series_score']).'% match</small>';
            echo '</td>';
            echo '<td><select class="aipex-sc-presenter-sel" data-index="'.esc_attr($i).'"><option value="0">— None —</option>';
            foreach ($presenters as $p) echo '<option value="'.esc_attr($p->ID).'">'.esc_html($p->post_title).'</option>';
            echo '</select></td>';
            echo '<td><button type="button" class="button button-primary aipex-sc-create-draft" data-index="'.esc_attr($i).'" data-nonce="'.esc_attr($draft_nonce).'">Create Draft</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        ?>
        <script>
        jQuery(function($){
            $(document).on('click','.aipex-sc-create-draft',function(){
                var $btn=$(this), idx=$btn.data('index'), nonce=$btn.data('nonce');
                var series_id=$('.aipex-sc-series-sel[data-index="'+idx+'"]').val()||0;
                var presenter_id=$('.aipex-sc-presenter-sel[data-index="'+idx+'"]').val()||0;
                $btn.prop('disabled',true).text('Creating…');
                $.post(ajaxurl,{action:'aipex_sc_create_draft',nonce:nonce,index:idx,series_id:series_id,presenter_id:presenter_id},function(resp){
                    if(resp&&resp.success){
                        $('#aipex-sc-new-'+idx).html('<td colspan="4" style="color:green">✓ Draft created: <a href="'+resp.data.edit_url+'" target="_blank">'+resp.data.title+'</a></td>');
                    } else {
                        $btn.prop('disabled',false).text('Create Draft');
                        alert('Error: '+(resp&&resp.data&&resp.data.message?resp.data.message:'Unknown error'));
                    }
                }).fail(function(){ $btn.prop('disabled',false).text('Create Draft'); alert('Request failed'); });
            });
        });
        </script>
        <?php
    }

    public static function apply_review($posted){
        $items = get_option(self::REVIEW_OPTION, []);
        $remaining = []; $done = 0;
        foreach ($items as $i => $item) {
            $row = $posted[$i] ?? [];
            if (empty($row['apply'])){ $remaining[] = $item; continue; }
            $url = esc_url_raw(trim($row['url'] ?? ''));
            if (!$url || !(strpos($url, 'soundcloud.com') !== false)){ $remaining[] = $item; continue; }
            update_post_meta((int)$item['episode_id'], 'soundcloud_url', $url);
            $done++;
        }
        update_option(self::REVIEW_OPTION, array_values($remaining), false);
        return 'Applied '.$done.' SoundCloud URL'.($done!==1?'s':'').'. '.count($remaining).' still in review.';
    }
}
