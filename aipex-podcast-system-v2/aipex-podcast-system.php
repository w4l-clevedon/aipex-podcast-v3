<?php
/**
 * Plugin Name: Aipex Podcast System
 * Description: Modular podcast CMS with ACF fields, shortcodes, Elementor widgets, scanners and Dropbox importer.
 * Version: 4.3.7
 * Author: Aipex
 */
if (!defined('ABSPATH')) exit;

define('AIPEX_PODCAST_VERSION','4.3.7');
define('AIPEX_PODCAST_FILE',__FILE__);
define('AIPEX_PODCAST_DIR',plugin_dir_path(__FILE__));
define('AIPEX_PODCAST_URL',plugin_dir_url(__FILE__));

require_once AIPEX_PODCAST_DIR.'includes/class-crypto.php';
require_once AIPEX_PODCAST_DIR.'includes/class-settings.php';
require_once AIPEX_PODCAST_DIR.'includes/class-relationships.php';
require_once AIPEX_PODCAST_DIR.'includes/class-analytics.php';
require_once AIPEX_PODCAST_DIR.'includes/class-soundcloud.php';
require_once AIPEX_PODCAST_DIR.'includes/class-csv-importer.php';
require_once AIPEX_PODCAST_DIR.'includes/class-transcription.php';
require_once AIPEX_PODCAST_DIR.'includes/class-episode-header.php';
require_once AIPEX_PODCAST_DIR.'includes/class-entity.php';
require_once AIPEX_PODCAST_DIR.'includes/class-core.php';
require_once AIPEX_PODCAST_DIR.'includes/class-post-types.php';
require_once AIPEX_PODCAST_DIR.'includes/class-acf.php';
require_once AIPEX_PODCAST_DIR.'includes/class-fields.php';
require_once AIPEX_PODCAST_DIR.'includes/class-shortcodes.php';
require_once AIPEX_PODCAST_DIR.'includes/class-admin.php';
require_once AIPEX_PODCAST_DIR.'includes/class-dropbox.php';
require_once AIPEX_PODCAST_DIR.'includes/class-elementor.php';

register_activation_hook(__FILE__, function(){
    Aipex_Podcast_Post_Types::register();
    flush_rewrite_rules();
    Aipex_Podcast_Relationships::migrate_all();
    Aipex_Podcast_Analytics::maybe_create_table();
});
register_deactivation_hook(__FILE__, function(){ flush_rewrite_rules(); });
add_action('plugins_loaded', ['Aipex_Podcast_Core','init']);
