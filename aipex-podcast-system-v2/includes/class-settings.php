<?php
if (!defined('ABSPATH')) exit;

/**
 * Per-site configuration. Previously several values (the default sponsor ID,
 * the brand pink used throughout podcast.css) were hardcoded for one specific
 * install. Pulling them into options here is the first step toward this
 * plugin being installable on other WordPress sites rather than being
 * single-site bespoke code.
 */
class Aipex_Podcast_Settings {
    const OPTION = 'aipex_podcast_settings';

    public static function defaults(){
        return [
            'default_sponsor_id' => 0,
            'brand_color' => '#e4005a',
        ];
    }

    public static function all(){
        return wp_parse_args(get_option(self::OPTION, []), self::defaults());
    }

    public static function get($key){
        $all = self::all();
        return $all[$key] ?? (self::defaults()[$key] ?? '');
    }

    public static function menu(){
        add_submenu_page('edit.php?post_type=aipex_podcast', 'Settings', 'Settings', 'manage_options', 'aipex-podcast-settings', [__CLASS__, 'page']);
    }

    public static function handle_save(){
        if (!current_user_can('manage_options')) return;
        if (empty($_POST['aipex_save_settings'])) return;
        check_admin_referer('aipex_podcast_settings');
        $settings = self::all();
        $settings['default_sponsor_id'] = (int)($_POST['default_sponsor_id'] ?? 0);
        $color = sanitize_hex_color(wp_unslash($_POST['brand_color'] ?? ''));
        if ($color) $settings['brand_color'] = $color;
        update_option(self::OPTION, $settings);
        set_transient('aipex_admin_notice', 'Settings saved.', 60);
        wp_safe_redirect(admin_url('edit.php?post_type=aipex_podcast&page=aipex-podcast-settings'));
        exit;
    }

    public static function page(){
        if (!current_user_can('manage_options')) return;
        if (class_exists('Aipex_Podcast_Admin')) Aipex_Podcast_Admin::show_notice();
        $settings = self::all();
        echo '<div class="wrap"><h1>Aipex Podcast System Settings</h1>';
        echo '<form method="post">'; wp_nonce_field('aipex_podcast_settings');
        echo '<table class="form-table">';
        echo '<tr><th><label for="default_sponsor_id">Default Sponsor ID</label></th><td><input type="number" id="default_sponsor_id" name="default_sponsor_id" value="'.esc_attr($settings['default_sponsor_id']).'" min="0" style="width:120px"><p class="description">Used as the pre-filled value on Tools &amp; Scanners. 0 = no default sponsor configured for this site.</p></td></tr>';
        echo '<tr><th><label for="brand_color">Brand Colour</label></th><td><input type="text" id="brand_color" name="brand_color" value="'.esc_attr($settings['brand_color']).'" class="regular-text" placeholder="#e4005a"><p class="description">Applied to cards, buttons and the floating player. Defaults to the original brand pink.</p></td></tr>';
        echo '</table>';
        echo '<p><button class="button button-primary" name="aipex_save_settings" value="1">Save Settings</button></p>';
        echo '</form></div>';
    }

    /** Outputs a tiny CSS override so podcast.css doesn't need per-site edits. */
    public static function inline_css(){
        $color = sanitize_hex_color(self::get('brand_color')) ?: '#e4005a';
        return ":root{--aipex-brand:{$color};}";
    }
}
