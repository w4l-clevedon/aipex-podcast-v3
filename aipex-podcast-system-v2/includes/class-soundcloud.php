<?php
if (!defined('ABSPATH')) exit;

/**
 * SoundCloud integration.
 *
 * Fetches all tracks from the configured SoundCloud account, fuzzy-matches
 * them against episode titles, and stores the SoundCloud track permalink URL
 * in the existing `soundcloud_url` field (already in the field alias map so
 * it's already surfaced in every place that reads audio data).
 *
 * Uses the SoundCloud API v2. Credentials are stored in wp_options via the
 * Settings screen — they are NEVER committed to the repository.
 */
class Aipex_Podcast_Soundcloud {

    const REVIEW_OPTION = 'aipex_sc_review';
    const STATE_KEY     = 'aipex_sc_import_state';
    const INDEX_OPTION  = 'aipex_sc_track_index';

    // -------------------------------------------------------------------------
    // Settings helpers
    // -------------------------------------------------------------------------

    public static function client_id(){
        return trim(Aipex_Podcast_Crypto::decrypt(get_option('aipex_sc_client_id', '')));
    }

    public static function username(){
        return trim(get_option('aipex_sc_username', ''));
    }

    // -------------------------------------------------------------------------
    // API calls
    // -------------------------------------------------------------------------

    private static function api_get($url){
        $response = wp_remote_get($url, [
            'timeout'    => 15,
            'user-agent' => 'Aipex Podcast System/1.0',
        ]);
        if (is_wp_error($response)) return null;
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) return null;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) ? $body : null;
    }

    /**
     * Resolves the SoundCloud username to a numeric user ID.
     * Cached in a transient for 7 days.
     */
    public static function resolve_user_id(){
        $cached = get_transient('aipex_sc_user_id');
        if ($cached) return (int)$cached;
        $username = self::username();
        $client_id = self::client_id();
        if (!$username || !$client_id) return 0;
        $url = 'https://api-v2.soundcloud.com/resolve?url='.rawurlencode('https://soundcloud.com/'.$username).'&client_id='.rawurlencode($client_id);
        $data = self::api_get($url);
        if (!$data || empty($data['id'])) return 0;
        $id = (int)$data['id'];
        set_transient('aipex_sc_user_id', $id, 7 * DAY_IN_SECONDS);
        return $id;
    }

    /**
     * Fetches one page of tracks for the user.
     * Returns ['tracks' => [...], 'next_href' => string|null]
     */
    public static function fetch_tracks_page($user_id, $next_href = null){
        $client_id = self::client_id();
        if (!$client_id || !$user_id) return null;
        $url = $next_href ?: 'https://api-v2.soundcloud.com/users/'.rawurlencode((string)$user_id).'/tracks?limit=200&client_id='.rawurlencode($client_id);
        // Ensure client_id is always on the URL (next_href may not have it)
        if ($next_href && !str_contains($next_href, 'client_id')) {
            $url .= (str_contains($next_href, '?') ? '&' : '?').'client_id='.rawurlencode($client_id);
        }
        $data = self::api_get($url);
        if (!$data) return null;
        $tracks = $data['collection'] ?? $data;
        if (!is_array($tracks)) return null;
        return [
            'tracks'    => $tracks,
            'next_href' => $data['next_href'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // SoundCloud embed URL builder
    // -------------------------------------------------------------------------

    /**
     * Given a SoundCloud track permalink URL, returns the iframe embed src.
     * Colour defaults to the site's brand colour from Settings.
     */
    public static function embed_url($permalink_url, $auto_play = false){
        $color = ltrim(sanitize_hex_color(Aipex_Podcast_Settings::get('brand_color')) ?: '#e4005a', '#');
        return 'https://w.soundcloud.com/player/?url='.rawurlencode($permalink_url)
            .'&color=%23'.rawurlencode($color)
            .'&auto_play='.($auto_play ? 'true' : 'false')
            .'&hide_related=true&show_comments=false&show_user=true&show_reposts=false&show_teaser=false';
    }

    // -------------------------------------------------------------------------
    // Batch import AJAX handlers
    // -------------------------------------------------------------------------

    public static function ajax_import_start(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_sc_import','nonce');

        $client_id = self::client_id();
        $username  = self::username();
        if (!$client_id || !$username) wp_send_json_error(['message'=>'SoundCloud credentials not set. Go to Podcasts → Settings and enter your Client ID and Username first.']);

        $user_id = self::resolve_user_id();
        if (!$user_id) wp_send_json_error(['message'=>'Could not resolve SoundCloud user. Check your username in Settings.']);

        // Reset
        delete_option(self::REVIEW_OPTION);
        delete_option(self::INDEX_OPTION);
        delete_transient(self::STATE_KEY);

        $state = [
            'user_id'   => $user_id,
            'next_href' => null,
            'fetched'   => 0,
            'done'      => 0,
            'linked'    => 0,
            'review'    => 0,
            'skipped'   => 0,
            'phase'     => 'fetch', // fetch first, then match
        ];
        set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
        wp_send_json_success(array_merge(self::run_fetch_batch($state), ['log'=>['Starting — fetching tracks from SoundCloud account @'.$username.'…']]));
    }

    public static function ajax_import_batch(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_sc_import','nonce');
        $state = get_transient(self::STATE_KEY);
        if (!$state || !is_array($state)) wp_send_json_error(['message'=>'No import in progress. Click Start.']);
        if ($state['phase'] === 'fetch') wp_send_json_success(self::run_fetch_batch($state));
        else wp_send_json_success(self::run_match_batch($state));
    }

    // Phase 1: fetch all tracks from SoundCloud into the index option
    private static function run_fetch_batch($state){
        $page = self::fetch_tracks_page($state['user_id'], $state['next_href']);
        $log = [];

        if (!$page) {
            // Fetch failed — move to match phase with whatever we have
            $state['phase'] = 'match';
            $state['match_offset'] = 0;
            $index = get_option(self::INDEX_OPTION, []);
            $state['match_total'] = count($index);
            set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
            $log[] = 'Fetch page failed. Proceeding to match with '.count($index).' tracks fetched so far.';
            return ['phase'=>'fetch_done','fetched'=>$state['fetched'],'log'=>$log,'pct'=>50,'done'=>0,'total'=>0,'linked'=>0,'review'=>0,'skipped'=>0,'finished'=>false];
        }

        $index = get_option(self::INDEX_OPTION, []);
        if (!is_array($index)) $index = [];

        foreach ($page['tracks'] as $track) {
            if (empty($track['title']) || empty($track['permalink_url'])) continue;
            $index[] = [
                'title'   => $track['title'],
                'url'     => $track['permalink_url'],
                'created' => $track['created_at'] ?? '',
            ];
        }
        update_option(self::INDEX_OPTION, $index, false);

        $state['fetched'] = count($index);
        $state['next_href'] = $page['next_href'];
        $log[] = 'Fetched page — '.count($page['tracks']).' tracks, '.count($index).' total so far.';

        if (!$page['next_href']) {
            // All pages fetched — move to matching phase
            $state['phase'] = 'match';
            $state['match_offset'] = 0;
            $state['match_total'] = count($index);
            $log[] = 'All tracks fetched ('.$state['fetched'].'). Starting episode matching…';
        }

        set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
        return ['phase'=>'fetching','fetched'=>$state['fetched'],'log'=>$log,'pct'=>20,'done'=>0,'total'=>0,'linked'=>0,'review'=>0,'skipped'=>0,'finished'=>false];
    }

    // Phase 2: match fetched tracks against episodes
    private static function run_match_batch($state, $batch_size = 30){
        $index   = get_option(self::INDEX_OPTION, []);
        $review  = get_option(self::REVIEW_OPTION, []);
        if (!is_array($review)) $review = [];
        $log     = [];

        // Get episodes with no soundcloud_url set, in batches
        if (empty($state['episode_ids'])) {
            $all = get_posts(['post_type'=>'aipex_podcast','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'date','order'=>'DESC']);
            // Only process episodes that don't already have a soundcloud_url
            $state['episode_ids'] = array_values(array_filter($all, function($id){
                return !Aipex_Podcast_Fields::get('soundcloud_url', $id);
            }));
            $state['match_total'] = count($state['episode_ids']);
            $state['match_offset'] = 0;
        }

        $slice = array_slice($state['episode_ids'], $state['match_offset'], $batch_size);

        foreach ($slice as $episode_id) {
            $title  = get_the_title($episode_id);
            $match  = self::best_track_match($title, $index);
            $state['done']++;

            if (!$match) {
                $state['skipped']++;
                $review[] = ['episode_id'=>$episode_id,'episode_title'=>$title,'track_url'=>'','track_title'=>'','score'=>0];
                $state['review']++;
                continue;
            }

            if ($match['score'] >= 90) {
                update_post_meta($episode_id, 'soundcloud_url', esc_url_raw($match['url']));
                $state['linked']++;
                $log[] = 'LINKED '.$match['score'].'%: '.mb_substr($title,0,55).' → '.mb_substr($match['title'],0,40);
            } elseif ($match['score'] >= 60) {
                $review[] = ['episode_id'=>$episode_id,'episode_title'=>$title,'track_url'=>$match['url'],'track_title'=>$match['title'],'score'=>$match['score']];
                $state['review']++;
                $log[] = 'REVIEW '.$match['score'].'%: '.mb_substr($title,0,55).' → '.mb_substr($match['title'],0,40).'?';
            } else {
                $review[] = ['episode_id'=>$episode_id,'episode_title'=>$title,'track_url'=>'','track_title'=>$match['title'].' ('.$match['score'].'%)','score'=>$match['score']];
                $state['review']++;
                $log[] = 'UNMATCHED '.$match['score'].'%: '.mb_substr($title,0,55);
            }
        }

        update_option(self::REVIEW_OPTION, $review, false);
        $state['match_offset'] += $batch_size;

        $total    = max(1, $state['match_total']);
        $finished = $state['match_offset'] >= $state['match_total'];
        $pct      = min(100, (int)round(50 + 50 * $state['match_offset'] / $total));

        if ($finished) {
            delete_transient(self::STATE_KEY);
            $log[] = 'Done. Auto-linked: '.$state['linked'].'. Needs review: '.$state['review'].'. Already had SC URL (skipped): '.$state['skipped'].'.';
        } else {
            set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
        }

        return [
            'phase'    => 'matching',
            'fetched'  => $state['fetched'],
            'done'     => $state['done'],
            'total'    => $state['match_total'],
            'linked'   => $state['linked'],
            'review'   => $state['review'],
            'skipped'  => $state['skipped'],
            'pct'      => $pct,
            'finished' => $finished,
            'log'      => $log,
        ];
    }

    private static function best_track_match($episode_title, $index){
        if (!$index) return null;
        $best_score = 0; $best = null;
        foreach ($index as $track) {
            $score = Aipex_Podcast_Fields::match_score($episode_title, $track['title']);
            if ($score > $best_score) { $best_score = $score; $best = $track; }
        }
        return $best ? array_merge($best, ['score' => $best_score]) : null;
    }

    // -------------------------------------------------------------------------
    // Review UI
    // -------------------------------------------------------------------------

    public static function render_ui(){
        $nonce        = wp_create_nonce('aipex_sc_import');
        $review_count = count(get_option(self::REVIEW_OPTION, []));
        $client_id    = self::client_id();
        $username     = self::username();
        if (!$client_id || !$username) {
            echo '<div class="notice notice-warning inline"><p>SoundCloud credentials not configured. Go to <a href="'.esc_url(admin_url('edit.php?post_type=aipex_podcast&page=aipex-podcast-settings')).'">Settings</a> and enter your Client ID and Username first.</p></div>';
            return;
        }
        ?>
        <div id="aipex-sc-wrap" style="max-width:700px;margin-top:10px">
            <p>
                <button type="button" class="button button-primary" id="aipex-sc-start">Start Import</button>
                <button type="button" class="button" id="aipex-sc-stop" style="display:none">Stop</button>
                <?php if($review_count): ?><strong style="margin-left:12px"><?php echo esc_html($review_count); ?> tracks waiting in the review table below.</strong><?php endif; ?>
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
                    if(d.phase==='fetching') $status.text('Fetching SoundCloud tracks… '+d.fetched+' so far');
                    else $status.text('Matching: '+d.done+'/'+d.total+' episodes (linked: '+d.linked+', review: '+d.review+')');
                    if(d.finished){ running=false; $start.prop('disabled',false); $stop.hide(); $bar.css('width','100%'); $status.text('Complete. Refresh this page to see the review table.'); }
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
        echo '<hr><h2>SoundCloud Matching — Needs Review ('.count($items).' episodes)</h2>';
        echo '<p>';
        echo '<strong style="color:#b45309">⚠ 60–89%</strong> — uncertain, track pre-selected, verify it\'s correct. ';
        echo '<strong style="color:#b91c1c">✗ Below 60%</strong> — no confident match, enter or paste the SoundCloud URL manually. ';
        echo 'Leave unticked to skip.';
        echo '</p>';
        echo '<form method="post">'; wp_nonce_field('aipex_tools');
        echo '<p>';
        echo '<button class="button button-primary" name="aipex_apply_sc_review" value="1">Apply Selected</button> ';
        echo '<button class="button" name="aipex_clear_sc_review" value="1" onclick="return confirm(\'Clear the review list?\')">Clear List</button>';
        echo ' <label style="margin-left:16px"><input type="checkbox" id="aipex-sc-check-all"> Select all</label>';
        echo '</p>';
        echo '<table class="widefat striped"><thead><tr><th style="width:32px">Apply</th><th>Episode</th><th style="width:60px">Score</th><th>SoundCloud URL</th></tr></thead><tbody>';
        foreach ($items as $i => $item) {
            $episode_id = (int)$item['episode_id'];
            $score_color = $item['score'] >= 60 ? '#b45309' : '#b91c1c';
            $score_label = $item['score'] >= 60 ? $item['score'].'%' : ($item['score'] ? $item['score'].'% ✗' : '—');
            echo '<tr>';
            echo '<td><input type="checkbox" class="aipex-sc-cb" name="sc_review['.(int)$i.'][apply]" value="1"></td>';
            echo '<td><a href="'.esc_url(get_edit_post_link($episode_id)).'" target="_blank">'.esc_html($item['episode_title']).'</a>';
            if (!empty($item['track_title'])) echo '<br><small style="color:#646970">Best guess: '.esc_html($item['track_title']).'</small>';
            echo '</td>';
            echo '<td><strong style="color:'.esc_attr($score_color).'">'.esc_html($score_label).'</strong></td>';
            echo '<td><input type="url" name="sc_review['.(int)$i.'][url]" value="'.esc_attr($item['track_url']).'" placeholder="https://soundcloud.com/womensradiostation/track-slug" style="width:100%"></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</form>';
        echo '<script>jQuery(function($){ $("#aipex-sc-check-all").on("change",function(){ $(".aipex-sc-cb").prop("checked",this.checked); }); });</script>';
    }

    public static function apply_review($posted){
        $items = get_option(self::REVIEW_OPTION, []);
        $remaining = []; $done = 0;
        foreach ($items as $i => $item) {
            $row = $posted[$i] ?? [];
            if (empty($row['apply'])){ $remaining[] = $item; continue; }
            $url = esc_url_raw(trim($row['url'] ?? ''));
            if (!$url || !str_contains($url, 'soundcloud.com')){ $remaining[] = $item; continue; }
            update_post_meta((int)$item['episode_id'], 'soundcloud_url', $url);
            $done++;
        }
        update_option(self::REVIEW_OPTION, array_values($remaining), false);
        return 'Applied '.$done.' SoundCloud URL'.($done!==1?'s':'').'. '.count($remaining).' still in review.';
    }
}
