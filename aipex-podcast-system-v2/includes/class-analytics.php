<?php
if (!defined('ABSPATH')) exit;

/**
 * Listener analytics — tracks play events and aggregates them for the
 * dashboard map and stat panels.
 *
 * Privacy / GDPR notes:
 *  - Raw IP addresses are never stored. IPv4 addresses have the last octet
 *    zeroed before any processing (192.168.1.123 → 192.168.1.0); IPv6 is
 *    truncated to /48. The anonymised form is still accurate enough for
 *    country-level geolocation.
 *  - A session hash (SHA-256 of episode + anonymised IP + calendar date)
 *    deduplicates plays so the same listener counts once per episode per day.
 *    The hash is one-way and cannot be reversed to an IP.
 *  - Geolocation results (country, city) are cached in a transient keyed
 *    by the anonymised IP for 30 days, so each unique visitor is looked up
 *    at most once.
 *  - What IS stored per row: episode ID, country code, country name, city,
 *    browser name, device type (mobile/tablet/desktop), OS family, timestamp.
 *    Nothing that identifies an individual.
 */
class Aipex_Podcast_Analytics {
    const TABLE_SUFFIX = 'aipex_play_events';
    const GEO_CACHE_SECONDS = 30 * DAY_IN_SECONDS;

    public static function table(){
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function maybe_create_table(){
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = self::table();
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            episode_id BIGINT UNSIGNED NOT NULL,
            session_hash VARCHAR(64) NOT NULL,
            country_code VARCHAR(5) DEFAULT '',
            country_name VARCHAR(100) DEFAULT '',
            city VARCHAR(100) DEFAULT '',
            browser VARCHAR(50) DEFAULT '',
            device_type VARCHAR(20) DEFAULT '',
            os VARCHAR(50) DEFAULT '',
            played_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY aipex_session (session_hash),
            KEY aipex_episode (episode_id),
            KEY aipex_country (country_code),
            KEY aipex_played (played_at)
        ) {$charset};");
    }

    // -------------------------------------------------------------------------
    // Play event recording
    // -------------------------------------------------------------------------

    public static function record_play($episode_id, $ip, $user_agent){
        global $wpdb;
        $episode_id = (int)$episode_id;
        if (!$episode_id || get_post_type($episode_id) !== 'aipex_podcast') return;

        $anon_ip = self::anonymise_ip($ip);
        $session_hash = hash('sha256', $episode_id . '|' . $anon_ip . '|' . date('Y-m-d'));

        // Idempotent — same listener + episode + day = one row
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM ".self::table()." WHERE session_hash=%s", $session_hash))) return;

        $geo = self::geolocate($anon_ip);
        $ua = self::parse_ua($user_agent);

        $wpdb->insert(self::table(), [
            'episode_id'   => $episode_id,
            'session_hash' => $session_hash,
            'country_code' => $geo['country_code'],
            'country_name' => $geo['country_name'],
            'city'         => $geo['city'],
            'browser'      => $ua['browser'],
            'device_type'  => $ua['device'],
            'os'           => $ua['os'],
            'played_at'    => current_time('mysql'),
        ]);
    }

    // -------------------------------------------------------------------------
    // IP anonymisation
    // -------------------------------------------------------------------------

    private static function anonymise_ip($ip){
        $ip = trim((string)$ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Zero the last octet: 192.168.1.123 → 192.168.1.0
            return long2ip(ip2long($ip) & 0xFFFFFF00);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Keep only the first 48 bits (network prefix)
            $bin = inet_pton($ip);
            $bin = substr($bin, 0, 6) . str_repeat("\x00", 10);
            return inet_ntop($bin);
        }
        return '';
    }

    private static function get_visitor_ip(){
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $ip;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    // -------------------------------------------------------------------------
    // Geolocation — ip-api.com (free, server-side, HTTP only on free tier)
    // -------------------------------------------------------------------------

    private static function geolocate($anon_ip){
        $empty = ['country_code'=>'','country_name'=>'','city'=>''];
        if (!$anon_ip) return $empty;

        $cache_key = 'aipex_geo_'.md5($anon_ip);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $response = wp_remote_get(
            'http://ip-api.com/json/'.rawurlencode($anon_ip).'?fields=status,country,countryCode,city',
            ['timeout'=>4,'user-agent'=>'Aipex Podcast Analytics']
        );

        $result = $empty;
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body['status']) && $body['status'] === 'success') {
                $result = [
                    'country_code' => sanitize_text_field($body['countryCode'] ?? ''),
                    'country_name' => sanitize_text_field($body['country'] ?? ''),
                    'city'         => sanitize_text_field($body['city'] ?? ''),
                ];
            }
        }
        set_transient($cache_key, $result, self::GEO_CACHE_SECONDS);
        return $result;
    }

    // -------------------------------------------------------------------------
    // User-agent parsing — no library, just pattern matching
    // -------------------------------------------------------------------------

    private static function parse_ua($ua){
        $ua = (string)$ua;
        return [
            'browser' => self::detect_browser($ua),
            'device'  => self::detect_device($ua),
            'os'      => self::detect_os($ua),
        ];
    }

    private static function detect_browser($ua){
        $checks = [
            'Edge'    => '/Edge?\/|Edg\//i',
            'Chrome'  => '/Chrome\/(?!.*Chromium)/i',
            'Firefox' => '/Firefox\//i',
            'Safari'  => '/Safari\/(?!.*Chrome)/i',
            'Opera'   => '/OPR\/|Opera\//i',
            'IE'      => '/MSIE |Trident\//i',
        ];
        foreach ($checks as $name => $pattern) {
            if (preg_match($pattern, $ua)) return $name;
        }
        return 'Other';
    }

    private static function detect_device($ua){
        if (preg_match('/tablet|ipad|playbook|silk/i', $ua)) return 'Tablet';
        if (preg_match('/mobile|android|iphone|ipod|blackberry|opera mini|iemobile/i', $ua)) return 'Mobile';
        return 'Desktop';
    }

    private static function detect_os($ua){
        $checks = [
            'iOS'     => '/iPhone|iPad|iPod/i',
            'Android' => '/Android/i',
            'Windows' => '/Windows NT/i',
            'macOS'   => '/Macintosh|Mac OS X/i',
            'Linux'   => '/Linux/i',
        ];
        foreach ($checks as $name => $pattern) {
            if (preg_match($pattern, $ua)) return $name;
        }
        return 'Other';
    }

    // -------------------------------------------------------------------------
    // Dashboard data queries
    // -------------------------------------------------------------------------

    public static function get_summary_stats(){
        global $wpdb;
        $table = self::table();
        return [
            'total'     => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'this_month'=> (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE played_at >= %s", date('Y-m-01 00:00:00'))),
            'this_week' => (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE played_at >= %s", date('Y-m-d', strtotime('monday this week')).' 00:00:00')),
            'countries' => (int)$wpdb->get_var("SELECT COUNT(DISTINCT country_code) FROM {$table} WHERE country_code != ''"),
        ];
    }

    public static function get_country_data($limit=50){
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT country_code, country_name, COUNT(*) as plays FROM {$table} WHERE country_code != '' GROUP BY country_code, country_name ORDER BY plays DESC LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    public static function get_browser_data(){
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results(
            "SELECT browser, COUNT(*) as plays FROM {$table} WHERE browser != '' GROUP BY browser ORDER BY plays DESC",
            ARRAY_A
        );
    }

    public static function get_device_data(){
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results(
            "SELECT device_type, COUNT(*) as plays FROM {$table} WHERE device_type != '' GROUP BY device_type ORDER BY plays DESC",
            ARRAY_A
        );
    }

    public static function get_top_episodes($limit=10){
        global $wpdb;
        $table = self::table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT episode_id, COUNT(*) as plays FROM {$table} GROUP BY episode_id ORDER BY plays DESC LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    public static function get_plays_by_month($months=12){
        global $wpdb;
        $table = self::table();
        $since = date('Y-m-01', strtotime("-{$months} months"));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(played_at,'%%Y-%%m') as month, COUNT(*) as plays FROM {$table} WHERE played_at >= %s GROUP BY month ORDER BY month ASC",
            $since
        ), ARRAY_A);
    }

    // -------------------------------------------------------------------------
    // AJAX handler — called from podcast.js when audio starts playing
    // -------------------------------------------------------------------------

    public static function ajax_track_play(){
        // Intentionally NOT using check_ajax_referer here — the nonce is still
        // validated but we use a verify_nonce so we can fail silently rather
        // than die() and interrupt playback state from the JS side.
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aipex_podcast')) {
            wp_send_json_error(null, 403);
        }
        $episode_id = (int)($_POST['episode_id'] ?? 0);
        $ip = self::get_visitor_ip();
        $ua = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
        self::record_play($episode_id, $ip, $ua);
        wp_send_json_success();
    }
}
