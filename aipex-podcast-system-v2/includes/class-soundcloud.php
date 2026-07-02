<?php
if (!defined('ABSPATH')) exit;

/**
 * SoundCloud integration — OAuth 2.0 Authorization Code flow.
 *
 * SoundCloud requires an Authorization header for all API calls (including
 * public data reads) since their 2020 security update. Client Credentials
 * grant is not supported for standard app registrations. The Authorization
 * Code flow is the only supported path:
 *
 *   1. Admin clicks "Connect SoundCloud" in Settings
 *   2. Redirected to SoundCloud login/authorise page
 *   3. SoundCloud redirects back to this site with ?aipex_sc_auth=1&code=...
 *   4. Plugin exchanges code for access_token + refresh_token (stored encrypted)
 *   5. All API calls use Authorization: OAuth {access_token}
 *
 * The redirect URI registered in your SoundCloud app must exactly match:
 *   https://your-site.com/wp-admin/admin.php?aipex_sc_auth=1
 */
class Aipex_Podcast_Soundcloud {

    const REVIEW_OPTION = 'aipex_sc_review';
    const NEW_EP_OPTION = 'aipex_sc_new_episodes';
    const STATE_KEY     = 'aipex_sc_import_state';
    const INDEX_OPTION  = 'aipex_sc_track_index';

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

    public static function access_token(){
        return trim(Aipex_Podcast_Crypto::decrypt(get_option('aipex_sc_access_token', '')));
    }

    public static function is_connected(){
        return (bool) self::access_token();
    }

    public static function redirect_uri(){
        return admin_url('admin.php?aipex_sc_auth=1');
    }

    // -------------------------------------------------------------------------
    // OAuth Authorization Code flow
    // -------------------------------------------------------------------------

    public static function get_oauth_url(){
        $state = wp_create_nonce('aipex_sc_oauth_state');
        set_transient('aipex_sc_oauth_state', $state, 10 * MINUTE_IN_SECONDS);
        return 'https://soundcloud.com/connect?'.http_build_query([
            'client_id'     => self::client_id(),
            'redirect_uri'  => self::redirect_uri(),
            'response_type' => 'code',
            'scope'         => 'non-expiring',
            'state'         => $state,
        ]);
    }

    /**
     * Hooked on admin_init — handles the redirect back from SoundCloud after
     * the user authorises. Exchanges the code for an access token and stores
     * it encrypted.
     */
    public static function handle_oauth_callback(){
        if (empty($_GET['aipex_sc_auth'])) return;
        if (!current_user_can('manage_options')) wp_die('Permission denied.');

        $code  = sanitize_text_field($_GET['code']  ?? '');
        $state = sanitize_text_field($_GET['state'] ?? '');
        $error = sanitize_text_field($_GET['error'] ?? '');

        if ($error) {
            set_transient('aipex_admin_notice', 'SoundCloud connection cancelled: '.esc_html($error), 60);
            wp_safe_redirect(admin_url('edit.php?post_type=aipex_podcast&page=aipex-podcast-settings'));
            exit;
        }

        $saved_state = get_transient('aipex_sc_oauth_state');
        if (!$code || !$state || !hash_equals((string)$saved_state, $state)) {
            wp_die('Invalid OAuth state. Please try connecting again.');
        }
        delete_transient('aipex_sc_oauth_state');

        $response = wp_remote_post('https://api.soundcloud.com/oauth2/token', [
            'timeout' => 15,
            'body'    => [
                'grant_type'    => 'authorization_code',
                'client_id'     => self::client_id(),
                'client_secret' => self::client_secret(),
                'redirect_uri'  => self::redirect_uri(),
                'code'          => $code,
            ],
            'user-agent' => 'Aipex Podcast System/1.0',
        ]);

        if (is_wp_error($response)) {
            wp_die('OAuth token exchange failed: '.$response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $data      = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code !== 200 || empty($data['access_token'])) {
            wp_die('SoundCloud token exchange failed (HTTP '.$http_code.'): '.wp_remote_retrieve_body($response));
        }

        update_option('aipex_sc_access_token', Aipex_Podcast_Crypto::encrypt($data['access_token']), false);
        if (!empty($data['refresh_token'])) {
            update_option('aipex_sc_refresh_token', Aipex_Podcast_Crypto::encrypt($data['refresh_token']), false);
        }
        delete_transient('aipex_sc_user_id');

        set_transient('aipex_admin_notice', '✓ SoundCloud connected successfully.', 60);
        wp_safe_redirect(admin_url('edit.php?post_type=aipex_podcast&page=aipex-podcast-settings'));
        exit;
    }

    public static function disconnect(){
        delete_option('aipex_sc_access_token');
        delete_option('aipex_sc_refresh_token');
        delete_transient('aipex_sc_user_id');
    }

    // -------------------------------------------------------------------------
    // API — uses OAuth access token
    // -------------------------------------------------------------------------

    private static function api_get($url){
        $token = self::access_token();
        if (!$token) return ['code'=>0,'error'=>'not_connected','body'=>''];

        // Do NOT add client_id when using an OAuth token — SoundCloud
        // treats any request with client_id in the URL as unauthenticated
        // and ignores the Authorization header entirely.

        // Use direct cURL to bypass WordPress HTTP API which strips the
        // Authorization header on some Plesk/cPanel server configurations.
        if (function_exists('curl_init')) {
            return self::curl_get($url, $token);
        }

        // Fallback: wp_remote_get
        $response = wp_remote_get($url, [
            'timeout'    => 15,
            'headers'    => ['Authorization' => 'OAuth '.$token],
            'user-agent' => 'Aipex Podcast System/1.0',
        ]);
        if (is_wp_error($response)) return ['code'=>0,'error'=>$response->get_error_message(),'data'=>null,'body'=>''];
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        return ['code'=>$code,'data'=>json_decode($body,true),'body'=>$body,'error'=>''];
    }

    /**
     * Direct cURL — bypasses WordPress HTTP layer which strips Authorization
     * headers on some Plesk/cPanel hosting configurations.
     * Tries OAuth prefix first, then Bearer if that returns 401.
     */
    private static function curl_get($url, $token){
        $prefix = get_option('aipex_sc_auth_prefix', 'OAuth');
        $prefixes = ($prefix === 'OAuth') ? ['OAuth', 'Bearer'] : ['Bearer', 'OAuth'];
        $last_body = ''; $last_code = 0;
        foreach ($prefixes as $p) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: '.$p.' '.$token,
                    'User-Agent: Aipex Podcast System/1.0',
                ],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_UNRESTRICTED_AUTH => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $last_body = (string)curl_exec($ch);
            $last_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($last_code !== 401) {
                if ($p !== get_option('aipex_sc_auth_prefix','OAuth')) update_option('aipex_sc_auth_prefix', $p, false);
                return ['code'=>$last_code,'data'=>json_decode($last_body,true),'body'=>$last_body,'error'=>''];
            }
        }
        return ['code'=>$last_code,'data'=>json_decode($last_body,true),'body'=>$last_body,'error'=>'Both OAuth and Bearer returned 401'];
    }

    public static function resolve_user_id(){
        $cached = get_transient('aipex_sc_user_id');
        if ($cached) return (int)$cached;
        $username = self::username();
        if (!$username) return 0;
        $r = self::api_get('https://api.soundcloud.com/resolve.json?url='.rawurlencode('https://soundcloud.com/'.$username));
        if ($r['code'] !== 200 || empty($r['data']['id'])) return 0;
        $id = (int)$r['data']['id'];
        set_transient('aipex_sc_user_id', $id, 7 * DAY_IN_SECONDS);
        return $id;
    }

    /**
     * Fetches one page of tracks using SoundCloud's cursor-based pagination
     * (linked_partitioning=1). On the first call, pass $next_href=null to
     * get the first page. Subsequent calls pass the next_href from the
     * previous response. Returns null when the last page is reached.
     */
    public static function fetch_tracks_page($user_id, $next_href = null){
        if ($next_href) {
            // next_href already contains all params — just use it directly
            $url = $next_href;
        } else {
            $url = 'https://api.soundcloud.com/users/'.rawurlencode((string)$user_id).'/tracks.json?limit=200&linked_partitioning=1';
        }
        $r = self::api_get($url);
        if ($r['code'] === 429) return ['error'=>'rate_limit'];
        if ($r['code'] !== 200 || !is_array($r['data'])) return null;

        // linked_partitioning wraps response in {collection:[...], next_href:...}
        // Plain array response means the API returned tracks directly (fallback)
        if (isset($r['data']['collection'])) {
            $tracks   = $r['data']['collection'];
            $next     = $r['data']['next_href'] ?? null;
        } else {
            $tracks   = $r['data'];
            $next     = null; // no cursor — this is the only page
        }

        return ['tracks'=>$tracks,'has_more'=>!empty($next),'next_href'=>$next];
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
    // Test connection AJAX
    // -------------------------------------------------------------------------

    public static function ajax_test_connection(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_sc_test','nonce');

        $log = [];
        if (!self::is_connected()) wp_send_json_error(['message'=>'Not connected. Use the Connect SoundCloud button in Settings.','log'=>$log]);

        $log[] = 'Access token: …'.substr(self::access_token(), -6).' ('.strlen(self::access_token()).' chars)';
        $log[] = 'Username: '.self::username();

        delete_transient('aipex_sc_user_id');
        $log[] = 'cURL available: '.(function_exists('curl_init')?'yes':'no');
        $log[] = 'Stored auth prefix: '.get_option('aipex_sc_auth_prefix','OAuth (default)');
        $tok = self::access_token();
        $log[] = 'Token first 20 chars: '.substr($tok,0,20).'… (type: '.(strpos($tok,'.')!==false?'JWT-style':'integer/opaque').')';
        // Quick /me test with no client_id to isolate token validity
        if (function_exists('curl_init')) {
            $ch2 = curl_init('https://api.soundcloud.com/me');
            curl_setopt_array($ch2,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Authorization: OAuth '.$tok,'Accept: application/json','User-Agent: Aipex/1.0'],CURLOPT_UNRESTRICTED_AUTH=>true,CURLOPT_SSL_VERIFYPEER=>true]);
            $me_body=(string)curl_exec($ch2); $me_code=(int)curl_getinfo($ch2,CURLINFO_HTTP_CODE); curl_close($ch2);
            $log[] = '/me endpoint (no client_id) → HTTP '.$me_code;
            if ($me_code!==200) $log[] = '/me body: '.substr($me_body,0,150);
            else { $me=json_decode($me_body,true); $log[] = '/me OK — id: '.($me['id']??'?').' username: '.($me['permalink']??'?'); }
        }

        // Test each prefix explicitly so we can see which one SoundCloud accepts
        // No client_id — OAuth token in Authorization header only
        $resolve_url = 'https://api.soundcloud.com/resolve.json?url='.rawurlencode('https://soundcloud.com/'.self::username());
        foreach (['OAuth','Bearer'] as $prefix) {
            if (function_exists('curl_init')) {
                $ch = curl_init($resolve_url);
                curl_setopt_array($ch,[
                    CURLOPT_RETURNTRANSFER    => true,
                    CURLOPT_TIMEOUT           => 10,
                    CURLOPT_HTTPHEADER        => ['Authorization: '.$prefix.' '.self::access_token(),'User-Agent: Aipex/1.0'],
                    CURLOPT_FOLLOWLOCATION    => true,
                    CURLOPT_UNRESTRICTED_AUTH => true,
                    CURLOPT_SSL_VERIFYPEER    => true,
                    // No CURLOPT_HEADER — with redirects it contaminates the body
                ]);
                $body = (string)curl_exec($ch);
                $code = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
                $redirect_count = (int)curl_getinfo($ch,CURLINFO_REDIRECT_COUNT);
                $effective_url  = curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
                $curl_err = curl_error($ch);
                curl_close($ch);
                $log[] = 'cURL '.$prefix.' → HTTP '.$code.' (redirects: '.$redirect_count.')'
                    .($effective_url ? ' final: '.basename(parse_url($effective_url,PHP_URL_PATH)) : '')
                    .($curl_err?' [err: '.$curl_err.']':'');
                if ($code === 200) { $r = ['code'=>200,'data'=>json_decode($body,true),'body'=>$body]; update_option('aipex_sc_auth_prefix',$prefix,false); break; }
                $log[] = 'Body: '.substr($body,0,120);
            }
        }
        if (!isset($r)) $r = self::api_get($resolve_url);

        if ($r['code'] === 429) wp_send_json_error(['message'=>'Rate limited — wait 60 seconds.','log'=>$log]);
        if ($r['code'] !== 200 || empty($r['data']['id'])) {
            wp_send_json_error(['message'=>'Resolve failed — HTTP '.$r['code'].'. See log.','log'=>$log]);
        }

        $user_id = (int)$r['data']['id'];
        set_transient('aipex_sc_user_id', $user_id, 7 * DAY_IN_SECONDS);
        $log[] = 'User ID: '.$user_id;

        wp_send_json_success(['user_id'=>$user_id,'username'=>self::username(),'auth'=>'OAuth access token','log'=>$log]);
    }

    // -------------------------------------------------------------------------
    // Batch import AJAX
    // -------------------------------------------------------------------------

    public static function ajax_import_start(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_sc_import','nonce');

        if (!self::is_connected())
            wp_send_json_error(['message'=>'Not connected. Use the Connect SoundCloud button in Settings first.']);

        $user_id = self::resolve_user_id();
        if (!$user_id) wp_send_json_error(['message'=>'Could not resolve SoundCloud user. Run Test Connection first.']);

        delete_option(self::REVIEW_OPTION);
        delete_option(self::NEW_EP_OPTION);
        delete_option(self::INDEX_OPTION);
        delete_transient(self::STATE_KEY);

        $state = ['user_id'=>$user_id,'next_href'=>null,'fetched'=>0,'done'=>0,'linked'=>0,'review'=>0,'new_ep'=>0,'skipped'=>0,'phase'=>'fetch'];
        set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
        $fetch_result = self::run_fetch_batch($state);
        $fetch_result['log'] = array_merge(['Connected. Fetching tracks from @'.self::username().'…'], $fetch_result['log'] ?? []);
        wp_send_json_success($fetch_result);
    }

    public static function ajax_import_batch(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_sc_import','nonce');
        $state = get_transient(self::STATE_KEY);
        if (!$state || !is_array($state)) wp_send_json_error(['message'=>'No import in progress. Click Start.']);
        wp_send_json_success($state['phase'] === 'fetch' ? self::run_fetch_batch($state) : self::run_match_batch($state));
    }

    private static function run_fetch_batch($state){
        $next_href = $state['next_href'] ?? null;
        $tracks_url = $next_href ?: 'https://api.soundcloud.com/users/'.rawurlencode((string)$state['user_id']).'/tracks.json?limit=200&linked_partitioning=1';
        $page = self::fetch_tracks_page($state['user_id'], $next_href);
        $log  = ['Calling: '.substr($tracks_url,0,80)];

        if (isset($page['error']) && $page['error'] === 'rate_limit') {
            set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
            $log[] = 'Rate limited — waiting 6 seconds…';
            return ['phase'=>'fetch_retry','fetched'=>$state['fetched'],'log'=>$log,'pct'=>max(5,(int)($state['fetched']/25)),'done'=>0,'total'=>0,'linked'=>0,'review'=>0,'new_ep'=>0,'skipped'=>0,'finished'=>false,'retry_delay'=>6000];
        }

        if (!$page) {
            // Log what the raw API actually returned for diagnosis
            $raw = self::api_get($tracks_url);
            $log[] = 'Fetch returned null — HTTP '.$raw['code'].' body: '.substr($raw['body']??'',0,200);
            $state['phase'] = 'match'; $state['match_offset'] = 0;
            $index = get_option(self::INDEX_OPTION, []);
            $state['match_total'] = count($index);
            set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
            $log[] = 'Matching against '.count($index).' tracks.';
            return ['phase'=>'fetch_error','fetched'=>$state['fetched'],'log'=>$log,'pct'=>50,'done'=>0,'total'=>0,'linked'=>0,'review'=>0,'new_ep'=>0,'skipped'=>0,'finished'=>false];
        }

        // Loop detected — SoundCloud v1 API cycled back to start
        if (!empty($page['looped'])) {
            $state['phase'] = 'match'; $state['match_offset'] = 0;
            $index = get_option(self::INDEX_OPTION, []);
            $state['match_total'] = count($index);
            set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
            $log[] = 'Pagination loop detected — end of catalogue. '.$state['fetched'].' unique tracks. Starting matching…';
            return ['phase'=>'fetch_done','fetched'=>$state['fetched'],'log'=>$log,'pct'=>50,'done'=>0,'total'=>0,'linked'=>0,'review'=>0,'new_ep'=>0,'skipped'=>0,'finished'=>false];
        }

        $index = get_option(self::INDEX_OPTION, []); if (!is_array($index)) $index = [];
        foreach ($page['tracks'] as $t) {
            if (!empty($t['title']) && !empty($t['permalink_url']))
                $index[] = ['title'=>$t['title'],'url'=>$t['permalink_url'],'created'=>$t['created_at']??''];
        }
        update_option(self::INDEX_OPTION, $index, false);
        $state['fetched']   = count($index);
        $state['next_href'] = $page['next_href'] ?? null;
        $log[] = 'Fetched '.count($page['tracks']).' tracks — '.count($index).' total.'.(!empty($state['next_href'])?' (more to fetch)':' (last page)');

        if (!$page['has_more']) {
            $state['phase'] = 'match'; $state['match_offset'] = 0; $state['match_total'] = count($index);
            $log[] = 'All tracks fetched ('.count($index).'). Starting show-filtered matching…';
        }
        set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
        return ['phase'=>'fetching','fetched'=>$state['fetched'],'log'=>$log,'pct'=>min(45,max(5,(int)($state['fetched']/25))),'done'=>0,'total'=>0,'linked'=>0,'review'=>0,'new_ep'=>0,'skipped'=>0,'finished'=>false];
    }

    // -------------------------------------------------------------------------
    // Series + episode matching
    // -------------------------------------------------------------------------

    private static function get_series_lookup(){
        static $lookup = null;
        if ($lookup !== null) return $lookup;
        $lookup = [];
        foreach (get_posts(['post_type'=>'aipex_series','post_status'=>'any','posts_per_page'=>-1]) as $s)
            $lookup[$s->ID] = $s->post_title;
        return $lookup;
    }

    /**
     * Finds the most-used presenter across all existing episodes for a
     * given series. Used to auto-assign presenter when the track/CSV row
     * doesn't specify one, avoiding 700+ manual assignments.
     * Cached per series per request.
     */
    public static function get_series_primary_presenter($series_id){
        static $cache = [];
        $series_id = (int)$series_id;
        if (!$series_id) return 0;
        if (isset($cache[$series_id])) return $cache[$series_id];

        // Strategy 1: most-used presenter across existing episodes for this show
        $ep_ids = Aipex_Podcast_Relationships::episodes_for(Aipex_Podcast_Relationships::TYPE_SHOW, $series_id);
        $counts = [];
        foreach ($ep_ids as $ep_id) {
            $hosts = Aipex_Podcast_Relationships::hosts_for(Aipex_Podcast_Relationships::TYPE_EPISODE, $ep_id);
            foreach ($hosts as $host_id) $counts[$host_id] = ($counts[$host_id] ?? 0) + 1;
        }
        if ($counts) {
            arsort($counts);
            $cache[$series_id] = (int)array_key_first($counts);
            return $cache[$series_id];
        }

        // Strategy 2: fuzzy-match the show name against presenter post titles.
        // WRS presenter titles often contain the show name (e.g. a presenter
        // whose post is titled after their show). Find the best match >= 75%.
        $series_title = get_the_title($series_id);
        $presenters   = get_posts(['post_type'=>'aipex_presenter','post_status'=>'any','posts_per_page'=>-1,'fields'=>'all']);
        $best_id = 0; $best_score = 0;
        foreach ($presenters as $p) {
            $score = Aipex_Podcast_Fields::match_score($series_title, $p->post_title);
            if ($score > $best_score) { $best_score = $score; $best_id = (int)$p->ID; }
        }
        $result = $best_score >= 75 ? $best_id : 0;
        $cache[$series_id] = $result;
        return $result;
    }

    private static function best_series_match($track_title, $min_score = 80){
        $best_id = 0; $best_score = 0; $best_title = '';
        foreach (self::get_series_lookup() as $sid => $stitle) {
            $score = Aipex_Podcast_Fields::match_score($track_title, $stitle);
            if ($score > $best_score) { $best_score = $score; $best_id = $sid; $best_title = $stitle; }
        }
        return $best_score >= $min_score ? ['id'=>$best_id,'title'=>$best_title,'score'=>$best_score] : null;
    }

    private static function best_episode_match($track_title, $series_id){
        $ep_ids = Aipex_Podcast_Relationships::episodes_for(Aipex_Podcast_Relationships::TYPE_SHOW, $series_id);
        if (!$ep_ids) return null;
        $posts = get_posts(['post_type'=>'aipex_podcast','post_status'=>'any','posts_per_page'=>-1,'post__in'=>$ep_ids,'meta_query'=>[['key'=>'soundcloud_url','compare'=>'NOT EXISTS']]]);
        $best = null; $best_score = 0;
        foreach ($posts as $ep) {
            $score = Aipex_Podcast_Fields::match_score($track_title, $ep->post_title);
            if ($score > $best_score) { $best_score = $score; $best = $ep; }
        }
        return $best ? ['post'=>$best,'score'=>$best_score] : null;
    }

    private static function run_match_batch($state, $batch_size = 25){
        $index   = get_option(self::INDEX_OPTION, []);
        $review  = get_option(self::REVIEW_OPTION, []); if (!is_array($review)) $review = [];
        $new_eps = get_option(self::NEW_EP_OPTION, []); if (!is_array($new_eps)) $new_eps = [];
        $log     = [];

        foreach (array_slice($index, $state['match_offset'] ?? 0, $batch_size) as $track) {
            $state['done']++;
            $series = self::best_series_match($track['title'], 80);
            if (!$series) { $state['skipped']++; continue; }

            // Auto-find the primary presenter for this series from existing episodes
            $auto_presenter_id = self::get_series_primary_presenter($series['id']);

            $ep = self::best_episode_match($track['title'], $series['id']);

            if ($ep && $ep['score'] >= 90) {
                update_post_meta($ep['post']->ID, 'soundcloud_url', esc_url_raw($track['url']));
                // Also assign presenter if not already set
                if ($auto_presenter_id && empty(Aipex_Podcast_Relationships::hosts_for(Aipex_Podcast_Relationships::TYPE_EPISODE, $ep['post']->ID))) {
                    Aipex_Podcast_Fields::update('presenters', [$auto_presenter_id], $ep['post']->ID);
                    Aipex_Podcast_Relationships::add(Aipex_Podcast_Relationships::TYPE_EPISODE, $ep['post']->ID, Aipex_Podcast_Relationships::TYPE_HOST, $auto_presenter_id);
                }
                Aipex_Podcast_Relationships::sync_post($ep['post']->ID);
                $state['linked']++;
                $log[] = 'LINKED '.$ep['score'].'%: '.mb_substr($track['title'],0,50);
            } elseif ($ep && $ep['score'] >= 60) {
                $review[] = ['episode_id'=>$ep['post']->ID,'episode_title'=>$ep['post']->post_title,'track_url'=>$track['url'],'track_title'=>$track['title'],'score'=>$ep['score'],'series_id'=>$series['id'],'series_title'=>$series['title'],'presenter_id'=>$auto_presenter_id];
                $state['review']++;
                $log[] = 'REVIEW '.$ep['score'].'%: '.mb_substr($track['title'],0,50);
            } else {
                $new_eps[] = ['track_title'=>$track['title'],'track_url'=>$track['url'],'track_created'=>$track['created']??'','series_id'=>$series['id'],'series_title'=>$series['title'],'series_score'=>$series['score'],'presenter_id'=>$auto_presenter_id];
                $state['new_ep']++;
                $log[] = 'NEW EP: '.mb_substr($track['title'],0,60).' → '.$series['title'];
            }
        }

        update_option(self::REVIEW_OPTION, $review, false);
        update_option(self::NEW_EP_OPTION, $new_eps, false);
        $state['match_offset'] = ($state['match_offset'] ?? 0) + $batch_size;
        $finished = $state['match_offset'] >= count($index);
        $pct = min(100, (int)round(50 + 50 * $state['match_offset'] / max(1, count($index))));
        if ($finished) {
            delete_transient(self::STATE_KEY);
            $log[] = 'Done. Linked: '.$state['linked'].'. Review: '.$state['review'].'. New EP candidates: '.$state['new_ep'].'. Not on site: '.$state['skipped'].'.';
        } else {
            set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
        }
        return ['phase'=>'matching','fetched'=>$state['fetched'],'done'=>$state['done'],'total'=>count($index),'linked'=>$state['linked'],'review'=>$state['review'],'new_ep'=>$state['new_ep'],'skipped'=>$state['skipped'],'pct'=>$pct,'finished'=>$finished,'log'=>$log];
    }

    // -------------------------------------------------------------------------
    // Draft creation AJAX
    // -------------------------------------------------------------------------

    public static function ajax_create_draft(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_sc_draft','nonce');

        $index        = (int)($_POST['index'] ?? -1);
        $series_id    = (int)($_POST['series_id'] ?? 0);
        $presenter_id = (int)($_POST['presenter_id'] ?? 0);
        $new_eps      = get_option(self::NEW_EP_OPTION, []);
        if (!isset($new_eps[$index])) wp_send_json_error(['message'=>'Item not found.']);
        $item = $new_eps[$index];

        $post_id = wp_insert_post(['post_type'=>'aipex_podcast','post_status'=>'draft','post_title'=>sanitize_text_field($item['track_title']),'post_date'=>(!empty($item['track_created']) ? date('Y-m-d H:i:s', strtotime($item['track_created'])) : current_time('mysql'))]);
        if (is_wp_error($post_id)) wp_send_json_error(['message'=>$post_id->get_error_message()]);

        update_post_meta($post_id, 'soundcloud_url', esc_url_raw($item['track_url']));
        $show_id = $series_id ?: $item['series_id'];
        if ($show_id) { Aipex_Podcast_Fields::update('series', [$show_id], $post_id); Aipex_Podcast_Relationships::add(Aipex_Podcast_Relationships::TYPE_EPISODE, $post_id, Aipex_Podcast_Relationships::TYPE_SHOW, $show_id); }
        if ($presenter_id) { Aipex_Podcast_Fields::update('presenters', [$presenter_id], $post_id); Aipex_Podcast_Relationships::add(Aipex_Podcast_Relationships::TYPE_EPISODE, $post_id, Aipex_Podcast_Relationships::TYPE_HOST, $presenter_id); }
        unset($new_eps[$index]);
        update_option(self::NEW_EP_OPTION, array_values($new_eps), false);
        wp_send_json_success(['post_id'=>$post_id,'edit_url'=>get_edit_post_link($post_id,'raw'),'title'=>$item['track_title']]);
    }

    /** Batch-creates all new episode candidates as drafts in one go. */
    public static function ajax_create_all_drafts(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'],403);
        check_ajax_referer('aipex_sc_draft','nonce');

        $offset     = (int)($_POST['offset'] ?? 0);
        $batch_size = 20;
        $new_eps    = get_option(self::NEW_EP_OPTION, []);
        $total      = count($new_eps);
        $slice      = array_slice($new_eps, $offset, $batch_size, true);
        $done = 0; $log = [];

        foreach ($slice as $index => $item) {
            $presenter_id = (int)$item['presenter_id'];
            // Fallback: look up from series if still 0
            if (!$presenter_id && !empty($item['series_id'])) {
                $presenter_id = (int)self::get_series_primary_presenter((int)$item['series_id']);
            }
            $post_id = wp_insert_post([
                'post_type'   => 'aipex_podcast',
                'post_status' => 'draft',
                'post_title'  => sanitize_text_field($item['track_title']),
                'post_date'   => !empty($item['track_created']) ? date('Y-m-d H:i:s', strtotime($item['track_created'])) : current_time('mysql'),
            ]);
            if (is_wp_error($post_id)) { $log[] = 'FAIL: '.mb_substr($item['track_title'],0,50).' — '.$post_id->get_error_message(); continue; }

            update_post_meta($post_id, 'soundcloud_url', esc_url_raw($item['track_url']));
            $show_id = (int)$item['series_id'];
            if ($show_id) {
                Aipex_Podcast_Fields::update('series', [$show_id], $post_id);
                Aipex_Podcast_Relationships::add(Aipex_Podcast_Relationships::TYPE_EPISODE, $post_id, Aipex_Podcast_Relationships::TYPE_SHOW, $show_id);
            }
            if ($presenter_id) {
                Aipex_Podcast_Fields::update('presenters', [$presenter_id], $post_id);
                Aipex_Podcast_Relationships::add(Aipex_Podcast_Relationships::TYPE_EPISODE, $post_id, Aipex_Podcast_Relationships::TYPE_HOST, $presenter_id);
            }
            $done++;
            $log[] = 'CREATED: '.mb_substr($item['track_title'],0,55).($presenter_id ? ' ['.get_the_title($presenter_id).']' : ' [no presenter]');
        }

        // Remove processed items from the queue
        $remaining = array_values(array_diff_key($new_eps, array_slice($new_eps, $offset, $batch_size, true)));
        update_option(self::NEW_EP_OPTION, $remaining, false);

        $new_offset  = $offset + $batch_size;
        $finished    = $new_offset >= $total;
        $pct         = min(100, (int)round(100 * $new_offset / max(1,$total)));
        wp_send_json_success(['done'=>$done,'offset'=>$new_offset,'total'=>$total,'pct'=>$pct,'finished'=>$finished,'remaining'=>count($remaining),'log'=>$log]);
    }

    // -------------------------------------------------------------------------
    // Admin UI
    // -------------------------------------------------------------------------

    public static function render_ui(){
        $nonce      = wp_create_nonce('aipex_sc_import');
        $test_nonce = wp_create_nonce('aipex_sc_test');
        $connected  = self::is_connected();
        $review_count = count(get_option(self::REVIEW_OPTION, []));
        $new_ep_count = count(get_option(self::NEW_EP_OPTION, []));

        if (!self::client_id()) {
            echo '<div class="notice notice-warning inline"><p>SoundCloud Client ID not set. Go to <a href="'.esc_url(admin_url('edit.php?post_type=aipex_podcast&page=aipex-podcast-settings')).'">Settings → SoundCloud</a>.</p></div>';
            return;
        }

        // Connection status + connect/disconnect button
        echo '<p>';
        if ($connected) {
            echo '<strong style="color:green">✓ SoundCloud connected</strong>';
            echo ' &nbsp; <a href="'.esc_url(wp_nonce_url(admin_url('edit.php?post_type=aipex_podcast&page=aipex-podcast-settings&aipex_sc_disconnect=1'),'aipex_sc_disconnect')).'" class="button">Disconnect</a>';
        } else {
            $oauth_url = self::get_oauth_url();
            echo '<a href="'.esc_url($oauth_url).'" class="button button-primary">Connect SoundCloud</a>';
            echo ' <span style="color:#646970;margin-left:8px">You\'ll be taken to SoundCloud to authorise this site, then redirected back.</span>';
        }
        echo '</p>';

        if (!$connected) return;
        ?>
        <p>
            <button type="button" class="button" id="aipex-sc-test">Test Connection</button>
            <span id="aipex-sc-test-result" style="margin-left:10px;font-style:italic;color:#646970"></span>
        </p>
        <pre id="aipex-sc-test-log" style="display:none;background:#f6f7f7;padding:10px;max-height:400px;overflow-y:auto;white-space:pre-wrap;font-size:12px;margin-top:6px;word-break:break-all"></pre>
        <p style="margin-top:12px">
            <button type="button" class="button button-primary" id="aipex-sc-start">Start Import</button>
            <button type="button" class="button" id="aipex-sc-stop" style="display:none">Stop</button>
            <?php if($review_count||$new_ep_count): ?>
            <span style="margin-left:12px">
                <?php if($review_count) echo '<strong>'.$review_count.' uncertain</strong> in review. '; ?>
                <?php if($new_ep_count) echo '<strong>'.$new_ep_count.' new episode candidates</strong> below.'; ?>
            </span>
            <?php endif; ?>
            <span id="aipex-sc-status" style="margin-left:12px;color:#646970"></span>
        </p>
        <div style="height:16px;background:#f0f0f1;border-radius:20px;overflow:hidden;margin-bottom:8px">
            <div id="aipex-sc-bar" style="height:16px;width:0%;background:var(--aipex-brand,#e4005a);border-radius:20px;transition:width .3s ease"></div>
        </div>
        <pre id="aipex-sc-log" style="background:#f6f7f7;padding:10px;max-height:200px;overflow:auto;white-space:pre-wrap;font-size:12px"></pre>
        <script>
        jQuery(function($){
            var testNonce=<?php echo wp_json_encode($test_nonce); ?>;
            var importNonce=<?php echo wp_json_encode($nonce); ?>;
            $('#aipex-sc-test').on('click',function(){
                var $btn=$(this),$r=$('#aipex-sc-test-result'),$log=$('#aipex-sc-test-log');
                $btn.prop('disabled',true); $r.text('Testing…'); $log.hide().text('');
                $.post(ajaxurl,{action:'aipex_sc_test',nonce:testNonce},function(resp){
                    $btn.prop('disabled',false);
                    var d=resp&&resp.data?resp.data:{};
                    if(resp&&resp.success) $r.css('color','green').text('✓ User ID '+d.user_id+' via '+d.auth);
                    else $r.css('color','red').text('✗ '+(d.message||'Failed'));
                    if(d.log&&d.log.length) $log.show().text(d.log.join('\n'));
                }).fail(function(xhr){ $btn.prop('disabled',false); $r.css('color','red').text('HTTP '+xhr.status); });
            });
            var running=false;
            function log(msg){ var $l=$('#aipex-sc-log'); $l.text($l.text()?$l.text()+'\n'+msg:msg); $l.scrollTop($l[0].scrollHeight); }
            function step(action,delay){
                if(!running) return;
                setTimeout(function(){
                    $.post(ajaxurl,{action:action,nonce:importNonce},function(resp){
                        if(!resp||!resp.success){ running=false; $('#aipex-sc-start').prop('disabled',false); $('#aipex-sc-stop').hide(); log('ERROR: '+(resp&&resp.data&&resp.data.message?resp.data.message:'Failed')); return; }
                        var d=resp.data;
                        $('#aipex-sc-bar').css('width',(d.pct||0)+'%');
                        $.each(d.log||[],function(_,l){ log(l); });
                        if(d.phase==='fetching'||d.phase==='fetch_retry') $('#aipex-sc-status').text('Fetching… '+d.fetched+' tracks');
                        else $('#aipex-sc-status').text('Matching '+d.done+'/'+d.total+' — linked:'+d.linked+' review:'+d.review+' new:'+d.new_ep);
                        if(d.finished){ running=false; $('#aipex-sc-start').prop('disabled',false); $('#aipex-sc-stop').hide(); $('#aipex-sc-bar').css('width','100%'); $('#aipex-sc-status').text('Complete — refresh page to see results.'); }
                        else step('aipex_sc_import_batch', d.retry_delay||300);
                    }).fail(function(xhr){ running=false; $('#aipex-sc-start').prop('disabled',false); $('#aipex-sc-stop').hide(); log('AJAX failed: HTTP '+xhr.status); });
                }, delay||0);
            }
            $('#aipex-sc-start').on('click',function(){ running=true; $('#aipex-sc-log').text(''); $('#aipex-sc-bar').css('width','0%'); $('#aipex-sc-status').text('Starting…'); $(this).prop('disabled',true); $('#aipex-sc-stop').show(); step('aipex_sc_import_start',0); });
            $('#aipex-sc-stop').on('click',function(){ running=false; $(this).hide(); $('#aipex-sc-start').prop('disabled',false); $('#aipex-sc-status').text('Stopped.'); });
        });
        </script>
        <?php
    }

    public static function render_review(){
        $items = get_option(self::REVIEW_OPTION, []);
        if (!$items) return;
        echo '<hr><h2>SoundCloud Matching — Needs Review ('.count($items).')</h2>';
        echo '<p><strong style="color:#b45309">⚠ 60–89%</strong> — uncertain, URL pre-filled. <strong style="color:#b91c1c">✗ Below 60%</strong> — paste SC URL manually. Untick to skip.</p>';
        echo '<form method="post">'; wp_nonce_field('aipex_tools');
        echo '<p><button class="button button-primary" name="aipex_apply_sc_review" value="1">Apply Selected</button> ';
        echo '<button class="button" name="aipex_clear_sc_review" value="1" onclick="return confirm(\'Clear?\')">Clear List</button>';
        echo ' <label style="margin-left:16px"><input type="checkbox" id="aipex-sc-cb-all"> Select all</label></p>';
        echo '<table class="widefat striped"><thead><tr><th style="width:32px">✓</th><th>Episode</th><th style="width:55px">Score</th><th>SoundCloud URL</th></tr></thead><tbody>';
        foreach ($items as $i => $item) {
            $ep_id = (int)$item['episode_id'];
            $sc = $item['score'] >= 60 ? '#b45309' : '#b91c1c';
            $sl = $item['score'] >= 60 ? $item['score'].'%' : ($item['score'] ? $item['score'].'% ✗' : '—');
            echo '<tr><td><input type="checkbox" class="aipex-sc-cb" name="sc_review['.$i.'][apply]" value="1"></td>';
            echo '<td><a href="'.esc_url(get_edit_post_link($ep_id)).'" target="_blank">'.esc_html($item['episode_title']).'</a>';
            if(!empty($item['track_title'])) echo '<br><small style="color:#646970">'.esc_html($item['track_title']).'</small>';
            echo '</td><td><strong style="color:'.esc_attr($sc).'">'.esc_html($sl).'</strong></td>';
            echo '<td><input type="url" name="sc_review['.$i.'][url]" value="'.esc_attr($item['track_url']).'" placeholder="https://soundcloud.com/..." style="width:100%"></td></tr>';
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
        $draft_nonce_all = wp_create_nonce('aipex_sc_draft');
        echo '<hr><h2>New Episode Candidates ('.count($items).')</h2>';
        echo '<p>Tracks matching a known show but no existing episode post. Presenter is auto-detected from existing episodes on that show.</p>';
        echo '<div style="margin-bottom:16px;padding:14px 18px;background:#f6f7f7;border-radius:8px;display:flex;align-items:center;gap:16px">';
        echo '<button type="button" class="button button-primary" id="aipex-sc-create-all" data-nonce="'.esc_attr($draft_nonce_all).'" data-total="'.esc_attr(count($items)).'">Create All '.count($items).' Drafts</button>';
        echo '<div style="flex:1"><div style="height:12px;background:#e4e7ec;border-radius:20px;overflow:hidden"><div id="aipex-sc-all-bar" style="height:12px;width:0%;background:var(--aipex-brand,#e4005a);border-radius:20px;transition:width .3s"></div></div><span id="aipex-sc-all-status" style="font-size:13px;color:#646970;margin-top:4px;display:block"></span></div>';
        echo '</div>';
        echo '<pre id="aipex-sc-all-log" style="display:none;background:#f6f7f7;padding:10px;max-height:200px;overflow:auto;white-space:pre-wrap;font-size:12px;margin-bottom:12px"></pre>';
        echo '<p style="color:#646970;font-size:13px">Or create individually:</p>';
        echo '<table class="widefat striped"><thead><tr><th>Track</th><th>Show</th><th>Presenter</th><th style="width:120px"></th></tr></thead><tbody>';
        foreach ($items as $i => $item) {
            echo '<tr id="aipex-sc-new-'.esc_attr($i).'">';
            echo '<td><a href="'.esc_url($item['track_url']).'" target="_blank">'.esc_html($item['track_title']).'</a>';
            if (!empty($item['track_created'])) echo '<br><small style="color:#646970">'.esc_html(date('j M Y', strtotime($item['track_created']))).'</small>';
            echo '</td><td><select class="aipex-sc-series-sel" data-index="'.esc_attr($i).'">';
            foreach ($series_all as $s) echo '<option value="'.esc_attr($s->ID).'" '.selected($s->ID,(int)$item['series_id'],false).'>'.esc_html($s->post_title).'</option>';
            echo '</select><br><small style="color:#646970">'.esc_html($item['series_score']).'% match</small></td>';
            echo '<td><select class="aipex-sc-presenter-sel" data-index="'.esc_attr($i).'"><option value="0">— None —</option>';
            foreach ($presenters as $p) echo '<option value="'.esc_attr($p->ID).'">'.esc_html($p->post_title).'</option>';
            echo '</select></td>';
            echo '<td><button type="button" class="button button-primary aipex-sc-create-draft" data-index="'.esc_attr($i).'" data-nonce="'.esc_attr($draft_nonce).'">Create Draft</button></td></tr>';
        }
        echo '</tbody></table>';
        ?>
        <script>
        jQuery(function($){
            // Individual draft create
            $(document).on('click','.aipex-sc-create-draft',function(){
                var $btn=$(this),idx=$btn.data('index'),nonce=$btn.data('nonce');
                var series_id=$('.aipex-sc-series-sel[data-index="'+idx+'"]').val()||0;
                var presenter_id=$('.aipex-sc-presenter-sel[data-index="'+idx+'"]').val()||0;
                $btn.prop('disabled',true).text('Creating…');
                $.post(ajaxurl,{action:'aipex_sc_create_draft',nonce:nonce,index:idx,series_id:series_id,presenter_id:presenter_id},function(resp){
                    if(resp&&resp.success){ $('#aipex-sc-new-'+idx).html('<td colspan="4" style="color:green">✓ <a href="'+resp.data.edit_url+'" target="_blank">'+resp.data.title+'</a></td>'); }
                    else { $btn.prop('disabled',false).text('Create Draft'); alert('Error: '+(resp&&resp.data&&resp.data.message?resp.data.message:'Unknown')); }
                }).fail(function(){ $btn.prop('disabled',false).text('Create Draft'); });
            });
            // Apply All
            var allRunning=false,allOffset=0;
            function allLog(m){ var $l=$('#aipex-sc-all-log'); $l.show().text($l.text()?$l.text()+'\n'+m:m); $l.scrollTop($l[0].scrollHeight); }
            function allStep(nonce){
                if(!allRunning) return;
                $.post(ajaxurl,{action:'aipex_sc_create_all_drafts',nonce:nonce,offset:allOffset},function(resp){
                    if(!resp||!resp.success){ allRunning=false; $('#aipex-sc-create-all').prop('disabled',false); allLog('ERROR: '+(resp&&resp.data&&resp.data.message?resp.data.message:'Failed')); return; }
                    var d=resp.data;
                    $('#aipex-sc-all-bar').css('width',(d.pct||0)+'%');
                    $.each(d.log||[],function(_,l){ allLog(l); });
                    $('#aipex-sc-all-status').text(d.done+' created — '+d.remaining+' remaining');
                    allOffset=d.offset;
                    if(d.finished){ allRunning=false; $('#aipex-sc-create-all').prop('disabled',false); $('#aipex-sc-all-bar').css('width','100%'); $('#aipex-sc-all-status').text('Complete — '+d.total+' drafts created. Refresh to update.'); }
                    else setTimeout(function(){ allStep(nonce); },300);
                }).fail(function(xhr){ allRunning=false; allLog('HTTP '+xhr.status); });
            }
            $('#aipex-sc-create-all').on('click',function(){
                if(!confirm('Create all '+$(this).data('total')+' drafts? This cannot be undone.')) return;
                allRunning=true; allOffset=0; $(this).prop('disabled',true);
                $('#aipex-sc-all-log').text('').show(); $('#aipex-sc-all-bar').css('width','0%');
                allStep($(this).data('nonce'));
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
            if (!$url || (strpos($url, 'soundcloud.com') === false)){ $remaining[] = $item; continue; }
            update_post_meta((int)$item['episode_id'], 'soundcloud_url', $url);
            $done++;
        }
        update_option(self::REVIEW_OPTION, array_values($remaining), false);
        return 'Applied '.$done.' SoundCloud URL'.($done!==1?'s':'').'. '.count($remaining).' still in review.';
    }
}
