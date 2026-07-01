<?php
if (!defined('ABSPATH')) exit;
class Aipex_Podcast_Core {
    public static function init(){
        add_action('init', ['Aipex_Podcast_Post_Types','register'], 5);
        add_action('init', ['Aipex_Podcast_Shortcodes','register'], 20);
        add_action('acf/init', ['Aipex_Podcast_ACF','register_fields']);
        add_action('acf/save_post', ['Aipex_Podcast_Relationships','sync_post'], 20);
        add_action('admin_menu', ['Aipex_Podcast_Admin','menus'], 30);
        add_action('admin_menu', ['Aipex_Podcast_Settings','menu'], 31);
        add_action('admin_init', ['Aipex_Podcast_Admin','handle_actions']);
        add_action('admin_init', ['Aipex_Podcast_Settings','handle_save']);
        add_action('admin_init', ['Aipex_Podcast_Dropbox','handle_actions']);
        add_action('rest_api_init', ['Aipex_Podcast_Dropbox','register_rest_routes']);
        add_action('wp_enqueue_scripts', ['Aipex_Podcast_Core','assets']);
        add_action('wp_ajax_aipex_grid_load_more', ['Aipex_Podcast_Shortcodes','ajax_grid_load_more']);
        add_action('wp_ajax_nopriv_aipex_grid_load_more', ['Aipex_Podcast_Shortcodes','ajax_grid_load_more']);
        add_action('wp_ajax_aipex_relationship_grid_load_more', ['Aipex_Podcast_Shortcodes','ajax_relationship_grid_load_more']);
        add_action('wp_ajax_nopriv_aipex_relationship_grid_load_more', ['Aipex_Podcast_Shortcodes','ajax_relationship_grid_load_more']);
        add_action('wp_ajax_aipex_track_play', ['Aipex_Podcast_Analytics','ajax_track_play']);
        add_action('wp_ajax_nopriv_aipex_track_play', ['Aipex_Podcast_Analytics','ajax_track_play']);
        add_action('wp_ajax_aipex_like_post', ['Aipex_Podcast_Analytics','ajax_like_post']);
        add_action('wp_ajax_nopriv_aipex_like_post', ['Aipex_Podcast_Analytics','ajax_like_post']);
        add_action('wp_ajax_aipex_sc_test',           ['Aipex_Podcast_Soundcloud','ajax_test_connection']);
        add_action('wp_ajax_aipex_sc_import_start',   ['Aipex_Podcast_Soundcloud','ajax_import_start']);
        add_action('wp_ajax_aipex_sc_import_batch',   ['Aipex_Podcast_Soundcloud','ajax_import_batch']);
        add_action('wp_ajax_aipex_sc_create_draft',   ['Aipex_Podcast_Soundcloud','ajax_create_draft']);
        add_action('wp_ajax_aipex_csv_upload',        ['Aipex_Podcast_CSV_Importer','ajax_upload']);
        add_action('wp_ajax_aipex_csv_match_start',   ['Aipex_Podcast_CSV_Importer','ajax_match_start']);
        add_action('wp_ajax_aipex_csv_match_batch',   ['Aipex_Podcast_CSV_Importer','ajax_match_batch']);
        add_action('wp_ajax_aipex_csv_create_draft',  ['Aipex_Podcast_CSV_Importer','ajax_create_draft']);
        add_action('admin_init',                       ['Aipex_Podcast_Soundcloud','handle_oauth_callback']);
        add_action('wp_ajax_aipex_dropbox_start_scan', ['Aipex_Podcast_Dropbox','ajax_start_scan']);
        add_action('wp_ajax_aipex_dropbox_continue_scan', ['Aipex_Podcast_Dropbox','ajax_continue_scan']);
        add_action('admin_init', ['Aipex_Podcast_Core','maybe_flush_rewrites']);
        add_action('admin_init', ['Aipex_Podcast_Core','maybe_migrate_relationships']);
        add_action('wp_ajax_aipex_rel_sync_start', ['Aipex_Podcast_Core','ajax_rel_sync_start']);
        add_action('wp_ajax_aipex_rel_sync_batch', ['Aipex_Podcast_Core','ajax_rel_sync_batch']);
        add_action('wp_ajax_aipex_title_match_start', ['Aipex_Podcast_Admin','ajax_title_match_start']);
        add_action('wp_ajax_aipex_title_match_batch', ['Aipex_Podcast_Admin','ajax_title_match_batch']);
        add_action('init', ['Aipex_Podcast_Core','register_legacy_redirect'], 7);
        Aipex_Podcast_Elementor::init();
    }
    public static function register_legacy_redirect(){
        // Two slugs need to redirect to the current presenter permalink
        // (/radio-presenter/slug/):
        //  - /host/slug/  — used briefly by a conflicting duplicate post-type
        //    registration in v2 (since removed).
        //  - /presenter/slug/ — the slug used before this rename. On
        //    womensradiostation.com, WP Job Manager already owns a rewrite
        //    rule for plain "presenter" and is registered ahead of anything
        //    this plugin adds, so we can NOT rely on add_rewrite_rule() to
        //    win that race — Job Manager's rule will keep matching first and
        //    WordPress will keep 404'ing before our rule is ever reached.
        //    Instead we catch this on the 404 itself: if WordPress already
        //    gave up on the request, check whether the path looks like an
        //    old presenter URL and redirect, regardless of which rewrite
        //    rule "claimed" it first.
        add_action('template_redirect', [__CLASS__, 'maybe_redirect_legacy_presenter_url']);
    }
    public static function maybe_redirect_legacy_presenter_url(){
        if (!is_404()) return;
        $path = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        $home_path = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home_path && (strpos($path, $home_path . '/') === 0)) $path = substr($path, strlen($home_path) + 1);
        if (!preg_match('~^(?:host|presenter)/([^/]+)/?$~', $path, $m)) return;
        $slug = sanitize_title($m[1]);
        $post = get_page_by_path($slug, OBJECT, 'aipex_presenter');
        if ($post && $post->post_status === 'publish') wp_safe_redirect(get_permalink($post), 301);
        else wp_safe_redirect(get_post_type_archive_link('aipex_presenter') ?: home_url('/'), 301);
        exit;
    }
    public static function maybe_flush_rewrites(){
        $key='aipex_podcast_rewrite_version';
        if (get_option($key) !== AIPEX_PODCAST_VERSION) {
            Aipex_Podcast_Post_Types::register();
            flush_rewrite_rules(false);
            update_option($key, AIPEX_PODCAST_VERSION, false);
        }
    }
    /**
     * Creates the relationships join table and backfills it from existing
     * ACF data on first run after upgrading to a version that has it.
     * Re-running migrate_all() is safe (sync_post() is idempotent), so this
     * only needs to fire once per version bump rather than every request.
     */
    public static function maybe_migrate_relationships(){
        $key = 'aipex_relationships_schema_version';
        if (get_option($key) !== AIPEX_PODCAST_VERSION) {
            Aipex_Podcast_Relationships::migrate_all();
            Aipex_Podcast_Analytics::maybe_create_table();
            update_option($key, AIPEX_PODCAST_VERSION, false);
        }
    }

    public static function ajax_rel_sync_start(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_rel_sync','nonce');
        wp_send_json_success(Aipex_Podcast_Relationships::migrate_batch(50, true));
    }

    public static function ajax_rel_sync_batch(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_rel_sync','nonce');
        wp_send_json_success(Aipex_Podcast_Relationships::migrate_batch(50, false));
    }
    public static function assets(){
        wp_register_style('aipex-podcast', AIPEX_PODCAST_URL.'assets/podcast.css', [], AIPEX_PODCAST_VERSION);
        wp_add_inline_style('aipex-podcast', Aipex_Podcast_Settings::inline_css());
        wp_register_script('aipex-podcast', AIPEX_PODCAST_URL.'assets/podcast.js', ['jquery'], AIPEX_PODCAST_VERSION, true);
        $js_data = [
            'ajaxurl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('aipex_podcast'),
            'episode_id' => is_singular('aipex_podcast') ? get_the_ID() : 0,
        ];
        wp_localize_script('aipex-podcast', 'AipexPodcast', $js_data);
    }
}
