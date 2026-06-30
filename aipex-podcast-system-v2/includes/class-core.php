<?php
if (!defined('ABSPATH')) exit;
class Aipex_Podcast_Core {
    public static function init(){
        add_action('init', ['Aipex_Podcast_Post_Types','register'], 5);
        add_action('init', ['Aipex_Podcast_Shortcodes','register'], 20);
        add_action('acf/init', ['Aipex_Podcast_ACF','register_fields']);
        add_action('admin_menu', ['Aipex_Podcast_Admin','menus'], 30);
        add_action('admin_menu', ['Aipex_Podcast_Settings','menu'], 31);
        add_action('admin_init', ['Aipex_Podcast_Admin','handle_actions']);
        add_action('admin_init', ['Aipex_Podcast_Settings','handle_save']);
        add_action('admin_init', ['Aipex_Podcast_Dropbox','handle_actions']);
        add_action('rest_api_init', ['Aipex_Podcast_Dropbox','register_rest_routes']);
        add_action('admin_init', ['Aipex_Podcast_Migration','handle_actions']);
        add_action('wp_enqueue_scripts', ['Aipex_Podcast_Core','assets']);
        add_action('wp_ajax_aipex_grid_load_more', ['Aipex_Podcast_Shortcodes','ajax_grid_load_more']);
        add_action('wp_ajax_nopriv_aipex_grid_load_more', ['Aipex_Podcast_Shortcodes','ajax_grid_load_more']);
        add_action('wp_ajax_aipex_dropbox_start_scan', ['Aipex_Podcast_Dropbox','ajax_start_scan']);
        add_action('wp_ajax_aipex_dropbox_continue_scan', ['Aipex_Podcast_Dropbox','ajax_continue_scan']);
        add_action('admin_init', ['Aipex_Podcast_Core','maybe_flush_rewrites']);
        add_action('init', ['Aipex_Podcast_Core','register_legacy_redirect'], 7);
        Aipex_Podcast_Elementor::init();
    }
    public static function register_legacy_redirect(){
        // v2 had a conflicting second post-type registration that briefly used
        // the /host/ slug instead of /presenter/. If any of those links were
        // ever indexed or shared, send them to the correct presenter URL
        // instead of letting them 404.
        add_rewrite_rule('^host/([^/]+)/?$', 'index.php?aipex_legacy_host_redirect=$matches[1]', 'top');
        add_filter('query_vars', function($vars){ $vars[] = 'aipex_legacy_host_redirect'; return $vars; });
        add_action('template_redirect', function(){
            $slug = get_query_var('aipex_legacy_host_redirect');
            if (!$slug) return;
            $post = get_page_by_path(sanitize_title($slug), OBJECT, 'aipex_presenter');
            if ($post) wp_safe_redirect(get_permalink($post), 301);
            else wp_safe_redirect(get_post_type_archive_link('aipex_presenter') ?: home_url('/'), 301);
            exit;
        });
    }
    public static function maybe_flush_rewrites(){
        $key='aipex_podcast_rewrite_version';
        if (get_option($key) !== AIPEX_PODCAST_VERSION) {
            Aipex_Podcast_Post_Types::register();
            flush_rewrite_rules(false);
            update_option($key, AIPEX_PODCAST_VERSION, false);
        }
    }
    public static function assets(){
        wp_register_style('aipex-podcast', AIPEX_PODCAST_URL.'assets/podcast.css', [], AIPEX_PODCAST_VERSION);
        wp_add_inline_style('aipex-podcast', Aipex_Podcast_Settings::inline_css());
        wp_register_script('aipex-podcast', AIPEX_PODCAST_URL.'assets/podcast.js', ['jquery'], AIPEX_PODCAST_VERSION, true);
        wp_localize_script('aipex-podcast','AipexPodcast',['ajaxurl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('aipex_podcast')]);
    }
}
