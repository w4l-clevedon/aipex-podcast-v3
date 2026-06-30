<?php
if (!defined('ABSPATH')) exit;

/**
 * Single concrete Elementor widget class, configured per-instance via its
 * constructor instead of generating ~24 separate subclasses with eval().
 *
 * The previous build used eval('class '.$class.' extends ...') to define a
 * new PHP class at runtime for every shortcode widget. No user input ever
 * reached that eval(), so it wasn't an injection vector, but it broke stack
 * traces/IDE tooling, tripped security scanners on some hosts, and added
 * complexity for no functional benefit over just passing constructor args.
 */
if (class_exists('\\Elementor\\Widget_Base')) {
    class Aipex_Podcast_Elementor_Shortcode_Widget extends \Elementor\Widget_Base {
        private $widget_name;
        private $widget_title;
        private $shortcode;

        public function __construct($data=[], $args=null, $widget_name='', $widget_title='', $shortcode=''){
            $this->widget_name = $widget_name;
            $this->widget_title = $widget_title;
            $this->shortcode = $shortcode;
            parent::__construct($data, $args);
        }

        public function get_name(){ return $this->widget_name; }
        public function get_title(){ return $this->widget_title; }
        public function get_icon(){ return 'eicon-play'; }
        public function get_categories(){ return ['aipex-podcast-system']; }

        protected function register_controls(){
            $this->start_controls_section('content', ['label' => 'Content']);
            $this->add_control('limit', ['label' => 'Limit', 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 12]);
            $this->end_controls_section();
        }

        protected function render(){
            $settings = $this->get_settings_for_display();
            echo do_shortcode('[' . $this->shortcode . ' limit="' . intval($settings['limit'] ?? 12) . '"]');
        }
    }
}

/**
 * Relationship Grid gets its own widget class rather than reusing the
 * generic shortcode-wrapper above, since it needs real controls (which
 * relationship, optional entity override) instead of just a limit field.
 */
if (class_exists('\\Elementor\\Widget_Base')) {
    class Aipex_Podcast_Elementor_Relationship_Grid extends \Elementor\Widget_Base {
        public function get_name(){ return 'aipex_widget_relationship_grid'; }
        public function get_title(){ return 'Relationship Grid'; }
        public function get_icon(){ return 'eicon-posts-grid'; }
        public function get_categories(){ return ['aipex-podcast-system']; }

        protected function register_controls(){
            $this->start_controls_section('content', ['label' => 'Content']);
            $this->add_control('relationship', [
                'label' => 'Relationship',
                'type' => \Elementor\Controls_Manager::SELECT,
                'default' => 'episodes',
                'options' => ['episodes' => 'Episodes', 'shows' => 'Shows', 'hosts' => 'Hosts', 'guests' => 'Guests', 'sponsors' => 'Sponsors'],
                'description' => 'What to show, relative to the current page (or the Entity ID below if set).',
            ]);
            $this->add_control('entity_id', [
                'label' => 'Entity ID (optional)',
                'type' => \Elementor\Controls_Manager::NUMBER,
                'default' => 0,
                'description' => 'Leave at 0 to use the entity the current page is about.',
            ]);
            $this->add_control('limit', ['label' => 'Limit', 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 12]);
            $this->end_controls_section();
        }

        protected function render(){
            $s = $this->get_settings_for_display();
            echo do_shortcode('[aipex_relationship_grid relationship="' . esc_attr($s['relationship'] ?? 'episodes') . '" entity_id="' . intval($s['entity_id'] ?? 0) . '" limit="' . intval($s['limit'] ?? 12) . '"]');
        }
    }
}

class Aipex_Podcast_Elementor {
    public static function init(){
        add_action('elementor/widgets/register', [__CLASS__, 'register']);
        add_action('elementor/elements/categories_registered', [__CLASS__, 'category']);
    }

    public static function category($manager){
        if (method_exists($manager, 'add_category')) {
            $manager->add_category('aipex-podcast-system', ['title' => 'Aipex Podcast System', 'icon' => 'fa fa-microphone']);
        }
    }

    public static function widget_definitions(){
        return [
            ['aipex_widget_player','Podcast Player','aipex_podcast_player'],
            ['aipex_widget_episode_grid','Podcast Episode Grid','aipex_podcast_grid'],
            ['aipex_widget_show_episodes','Show Episodes','aipex_show_podcasts'],
            ['aipex_widget_presenter_podcasts','Presenter Podcasts','aipex_presenter_podcasts'],
            ['aipex_widget_series_grid','Series Grid','aipex_series_grid'],
            ['aipex_widget_presenter_grid','Presenter Grid','aipex_presenter_grid'],
            ['aipex_widget_guest_grid','Guest Grid','aipex_guest_grid'],
            ['aipex_widget_summary','Episode Summary','aipex_podcast_summary'],
            ['aipex_widget_main_points','Main Points','aipex_podcast_main_points'],
            ['aipex_widget_transcript','Transcript','aipex_podcast_transcript'],
            ['aipex_widget_series_details','Series Details','aipex_series_details'],
            ['aipex_widget_series_main_points','Series Main Topics','aipex_series_main_points'],
            ['aipex_widget_series_episode_summaries','Series Episode Summaries','aipex_series_episode_summaries'],
            ['aipex_widget_presenter_about','Presenter About','aipex_presenter_about'],
            ['aipex_widget_presenter_box','Presenter Box','aipex_presenter_box'],
            ['aipex_widget_presenter_links','Presenter Links','aipex_presenter_links'],
            ['aipex_widget_subscribe','Subscribe Links','aipex_subscribe'],
            ['aipex_widget_sponsors','Sponsors','aipex_sponsors'],
            ['aipex_widget_sponsor_grid','Sponsor Grid','aipex_sponsor_grid'],
            ['aipex_widget_show_summary','Show Summary','aipex_show_summary'],
            ['aipex_widget_show_main_topics','Show Main Topics','aipex_show_main_topics'],
            ['aipex_widget_episode_series','Episode Series','aipex_episode_series'],
            ['aipex_widget_nav','Next Previous Navigation','aipex_next_previous'],
            ['aipex_widget_floating','Floating Player','aipex_floating_player'],
        ];
    }

    public static function register($widgets_manager){
        if (!class_exists('\\Elementor\\Widget_Base')) return;
        if (!class_exists('Aipex_Podcast_Elementor_Shortcode_Widget')) return;
        foreach (self::widget_definitions() as [$name, $title, $shortcode]) {
            $widgets_manager->register(new Aipex_Podcast_Elementor_Shortcode_Widget([], null, $name, $title, $shortcode));
        }
        if (class_exists('Aipex_Podcast_Elementor_Relationship_Grid')) {
            $widgets_manager->register(new Aipex_Podcast_Elementor_Relationship_Grid());
        }
    }
}
