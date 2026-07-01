<?php
if (!defined('ABSPATH')) exit;

class Aipex_Podcast_Settings {
    const OPTION = 'aipex_podcast_settings';

    public static function defaults(){
        return ['default_sponsor_id'=>0,'brand_color'=>'#e4005a'];
    }

    public static function all(){
        return wp_parse_args(get_option(self::OPTION,[]),self::defaults());
    }

    public static function get($key){
        $all=self::all(); return $all[$key]??(self::defaults()[$key]??'');
    }

    public static function menu(){
        add_submenu_page('edit.php?post_type=aipex_podcast','Settings','Settings','manage_options','aipex-podcast-settings',[__CLASS__,'page']);
    }

    public static function handle_save(){
        if(!current_user_can('manage_options')) return;
        if(empty($_POST['aipex_save_settings'])) return;
        check_admin_referer('aipex_podcast_settings');
        $settings=self::all();
        $settings['default_sponsor_id']=(int)($_POST['default_sponsor_id']??0);
        $color=sanitize_hex_color(wp_unslash($_POST['brand_color']??''));
        if($color) $settings['brand_color']=$color;
        update_option(self::OPTION,$settings,false);

        // SoundCloud credentials — stored separately, client_id encrypted
        if(!empty($_POST['sc_client_id'])){
            $cid=sanitize_text_field(wp_unslash($_POST['sc_client_id']));
            if($cid) update_option('aipex_sc_client_id',Aipex_Podcast_Crypto::encrypt($cid),false);
        }
        if(isset($_POST['sc_username'])){
            update_option('aipex_sc_username',sanitize_text_field(wp_unslash($_POST['sc_username'])),false);
            delete_transient('aipex_sc_user_id');
        }

        set_transient('aipex_admin_notice','Settings saved.',60);
        wp_safe_redirect(admin_url('edit.php?post_type=aipex_podcast&page=aipex-podcast-settings'));
        exit;
    }

    public static function page(){
        if(!current_user_can('manage_options')) return;
        if(class_exists('Aipex_Podcast_Admin')) Aipex_Podcast_Admin::show_notice();
        $settings=self::all();
        $sc_username=get_option('aipex_sc_username','');
        $sc_set=(bool)get_option('aipex_sc_client_id','');
        echo '<div class="wrap"><h1>Aipex Podcast System Settings</h1>';
        echo '<form method="post">'; wp_nonce_field('aipex_podcast_settings');
        echo '<h2>General</h2><table class="form-table">';
        echo '<tr><th><label for="default_sponsor_id">Default Sponsor ID</label></th><td><input type="number" id="default_sponsor_id" name="default_sponsor_id" value="'.esc_attr($settings['default_sponsor_id']).'" min="0" style="width:120px"><p class="description">Pre-filled on Tools &amp; Scanners sponsor actions. 0 = no default.</p></td></tr>';
        echo '<tr><th><label for="brand_color">Brand Colour</label></th><td><input type="text" id="brand_color" name="brand_color" value="'.esc_attr($settings['brand_color']).'" class="regular-text" placeholder="#e4005a"><p class="description">Applied to cards, buttons, the floating player, and SoundCloud embeds.</p></td></tr>';
        echo '</table><h2>SoundCloud</h2><table class="form-table">';
        echo '<tr><th><label for="sc_client_id">Client ID</label></th><td><input type="password" id="sc_client_id" name="sc_client_id" value="" class="regular-text" autocomplete="new-password" placeholder="'.($sc_set?'(saved — paste to update)':'Enter SoundCloud Client ID').'"><p class="description">From soundcloud.com/you/apps. Stored encrypted. Leave blank to keep existing value.</p></td></tr>';
        echo '<tr><th><label for="sc_username">Username</label></th><td><input type="text" id="sc_username" name="sc_username" value="'.esc_attr($sc_username).'" class="regular-text" placeholder="womensradiostation"><p class="description">The part after soundcloud.com/ on your profile page.</p></td></tr>';
        echo '</table>';
        echo '<p><button class="button button-primary" name="aipex_save_settings" value="1">Save Settings</button></p>';
        echo '</form></div>';
    }

    public static function inline_css(){
        $color=sanitize_hex_color(self::get('brand_color'))?:'#e4005a';
        return ":root{--aipex-brand:{$color};}";
    }
}
