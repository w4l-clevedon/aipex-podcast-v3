<?php
if (!defined('ABSPATH')) exit;

// ============================================================================
// Base shortcode-wrapper widget — used for the simple cases where the only
// meaningful Elementor control is a limit number. The ~24 legacy wrappers in
// widget_definitions() use this; new widgets below have their own classes.
// ============================================================================
if (class_exists('\\Elementor\\Widget_Base')) {
    class Aipex_Podcast_Elementor_Shortcode_Widget extends \Elementor\Widget_Base {
        private $widget_name, $widget_title, $shortcode;
        public function __construct($data=[], $args=null, $widget_name='', $widget_title='', $shortcode=''){
            $this->widget_name=$widget_name; $this->widget_title=$widget_title; $this->shortcode=$shortcode;
            parent::__construct($data, $args);
        }
        public function get_name(){ return $this->widget_name; }
        public function get_title(){ return $this->widget_title; }
        public function get_icon(){ return 'eicon-play'; }
        public function get_categories(){ return ['aipex-podcast-system']; }
        protected function register_controls(){
            $this->start_controls_section('content',['label'=>'Content']);
            $this->add_control('limit',['label'=>'Limit','type'=>\Elementor\Controls_Manager::NUMBER,'default'=>12]);
            $this->end_controls_section();
        }
        protected function render(){
            $s=$this->get_settings_for_display();
            echo do_shortcode('['.$this->shortcode.' limit="'.intval($s['limit']??12).'"]');
        }
    }
}

// ============================================================================
// Helper trait — every proper widget below uses these; keeps the base class
// definition from becoming unwieldy.
// ============================================================================
if (class_exists('\\Elementor\\Widget_Base')) {
    abstract class Aipex_Podcast_Elementor_Widget extends \Elementor\Widget_Base {
        public function get_categories(){ return ['aipex-podcast-system']; }
        protected function content_section(){ $this->start_controls_section('content',['label'=>'Content']); }
        protected function end_content(){ $this->end_controls_section(); }
        protected function add_limit($default=12){
            $this->add_control('limit',['label'=>'Limit','type'=>\Elementor\Controls_Manager::NUMBER,'default'=>$default,'min'=>1,'max'=>100]);
        }
        protected function add_entity_override($label='Entity ID (optional)'){
            $this->add_control('entity_id',['label'=>$label,'type'=>\Elementor\Controls_Manager::NUMBER,'default'=>0,'description'=>'Leave at 0 to use the entity the current page is about.']);
        }
    }
}

// ============================================================================
// 1. AUDIO PLAYER
// ============================================================================
if (class_exists('Aipex_Podcast_Elementor_Widget')) {
    class Aipex_Widget_Audio_Player extends Aipex_Podcast_Elementor_Widget {
        public function get_name(){ return 'aipex_widget_audio_player'; }
        public function get_title(){ return 'Audio Player'; }
        public function get_icon(){ return 'eicon-play-o'; }
        protected function register_controls(){
            $this->content_section();
            $this->add_control('preload',['label'=>'Preload','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'none','options'=>['none'=>'None (recommended)','metadata'=>'Metadata only','auto'=>'Auto']]);
            $this->add_control('entity_id',['label'=>'Episode ID (optional)','type'=>\Elementor\Controls_Manager::NUMBER,'default'=>0,'description'=>'Leave at 0 to use the current episode.']);
            $this->end_content();
        }
        protected function render(){
            $s=$this->get_settings_for_display();
            $id=(int)($s['entity_id']??0) ?: get_the_ID();
            $url=Aipex_Podcast_Fields::audio_url($id);
            if(!$url){ echo '<p class="aipex-empty">No audio found.</p>'; return; }
            echo '<div class="aipex-player"><audio controls preload="'.esc_attr($s['preload']??'none').'" src="'.esc_url($url).'"></audio></div>';
            wp_enqueue_style('aipex-podcast'); wp_enqueue_script('aipex-podcast');
        }
    }
}

// ============================================================================
// 2. FLOATING PLAYER
// ============================================================================
if (class_exists('Aipex_Podcast_Elementor_Widget')) {
    class Aipex_Widget_Floating_Player extends Aipex_Podcast_Elementor_Widget {
        public function get_name(){ return 'aipex_widget_floating_player'; }
        public function get_title(){ return 'Floating Player'; }
        public function get_icon(){ return 'eicon-headphones'; }
        protected function register_controls(){
            $this->content_section();
            $this->add_limit(12);
            $this->add_control('context',['label'=>'Context','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'auto','options'=>['auto'=>'Auto (current show/presenter)','all'=>'All episodes (unfiltered)'],'description'=>'"Auto" shows episodes for the current show or presenter page; "All episodes" always shows the full global feed.']);
            $this->end_content();
        }
        protected function render(){
            $s=$this->get_settings_for_display();
            echo do_shortcode('[aipex_floating_player limit="'.intval($s['limit']??12).'" context="'.esc_attr($s['context']??'auto').'"]');
        }
    }
}

// ============================================================================
// 3. ENTITY HERO — title, featured image, excerpt for any entity type
// ============================================================================
if (class_exists('Aipex_Podcast_Elementor_Widget')) {
    class Aipex_Widget_Entity_Hero extends Aipex_Podcast_Elementor_Widget {
        public function get_name(){ return 'aipex_widget_entity_hero'; }
        public function get_title(){ return 'Entity Hero'; }
        public function get_icon(){ return 'eicon-single-post'; }
        protected function register_controls(){
            $this->content_section();
            $this->add_entity_override();
            $this->add_control('show_image',['label'=>'Show Image','type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes']);
            $this->add_control('image_size',['label'=>'Image Size','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'full','options'=>['thumbnail'=>'Thumbnail','medium'=>'Medium','medium_large'=>'Medium Large','large'=>'Large','full'=>'Full']]);
            $this->add_control('show_excerpt',['label'=>'Show Excerpt/Overview','type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes']);
            $this->add_control('excerpt_words',['label'=>'Excerpt Word Limit','type'=>\Elementor\Controls_Manager::NUMBER,'default'=>40,'min'=>10,'max'=>200]);
            $this->end_content();
        }
        protected function render(){
            $s=$this->get_settings_for_display();
            $id=(int)($s['entity_id']??0) ?: get_the_ID();
            $entity=Aipex_Podcast_Entity::load($id);
            if(!$entity) return;
            echo '<div class="aipex-entity-hero">';
            if($s['show_image']==='yes' && $img=$entity->image($s['image_size']??'full'))
                echo '<div class="aipex-entity-hero-image">'.$img.'</div>';
            echo '<div class="aipex-entity-hero-body">';
            echo '<h1 class="aipex-entity-title">'.esc_html($entity->title()).'</h1>';
            if($s['show_excerpt']==='yes' && $ex=$entity->excerpt((int)($s['excerpt_words']??40)))
                echo '<p class="aipex-entity-excerpt">'.esc_html($ex).'</p>';
            echo '</div></div>';
            wp_enqueue_style('aipex-podcast');
        }
    }
}

// ============================================================================
// 4. EPISODE METADATA BAR
// ============================================================================
if (class_exists('Aipex_Podcast_Elementor_Widget')) {
    class Aipex_Widget_Episode_Meta extends Aipex_Podcast_Elementor_Widget {
        public function get_name(){ return 'aipex_widget_episode_meta'; }
        public function get_title(){ return 'Episode Metadata'; }
        public function get_icon(){ return 'eicon-info-circle-o'; }
        protected function register_controls(){
            $this->content_section();
            $this->add_control('show_date',['label'=>'Show Date','type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes']);
            $this->add_control('show_duration',['label'=>'Show Duration','type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes']);
            $this->add_control('show_number',['label'=>'Show Episode Number','type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes']);
            $this->add_control('show_series',['label'=>'Show Series Link','type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes']);
            $this->end_content();
        }
        protected function render(){
            $s=$this->get_settings_for_display();
            echo do_shortcode('[aipex_episode_meta show_date="'.($s['show_date']==='yes'?1:0).'" show_duration="'.($s['show_duration']==='yes'?1:0).'" show_number="'.($s['show_number']==='yes'?1:0).'" show_series="'.($s['show_series']==='yes'?1:0).'"]');
        }
    }
}

// ============================================================================
// 5. SOCIAL & CONTACT LINKS
// ============================================================================
if (class_exists('Aipex_Podcast_Elementor_Widget')) {
    class Aipex_Widget_Social_Links extends Aipex_Podcast_Elementor_Widget {
        public function get_name(){ return 'aipex_widget_social_links'; }
        public function get_title(){ return 'Social & Contact Links'; }
        public function get_icon(){ return 'eicon-social-icons'; }
        protected function register_controls(){
            $this->content_section();
            $this->add_entity_override();
            $this->add_control('show_contact',['label'=>'Show Email & Phone','type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes','description'=>'Contact details only appear if set on the entity.']);
            $this->end_content();
        }
        protected function render(){
            $s=$this->get_settings_for_display();
            $id=(int)($s['entity_id']??0) ?: get_the_ID();
            echo do_shortcode('[aipex_presenter_links]');
            // if entity_id is explicitly set, render directly
            if((int)($s['entity_id']??0)){
                $entity=Aipex_Podcast_Entity::load($id);
                if(!$entity) return;
                $links=$entity->social_links();
                $out='';
                foreach($links as $link) $out.='<a class="aipex-icon-link" href="'.esc_url($link['url']).'" target="_blank" rel="noopener">'.esc_html($link['label']).'</a>';
                if($s['show_contact']==='yes'){
                    $contact=$entity->contact();
                    if(!empty($contact['email'])) $out.='<a class="aipex-icon-link" href="mailto:'.esc_attr($contact['email']).'">✉ Email</a>';
                    if(!empty($contact['phone'])) $out.='<a class="aipex-icon-link" href="tel:'.esc_attr(preg_replace('/[^0-9+]/','',$contact['phone'])).'">📞 '.esc_html($contact['phone']).'</a>';
                }
                if($out) echo '<div class="aipex-icon-links">'.$out.'</div>';
            }
            wp_enqueue_style('aipex-podcast');
        }
    }
}

// ============================================================================
// 6. TRANSCRIPT (with collapsible option)
// ============================================================================
if (class_exists('Aipex_Podcast_Elementor_Widget')) {
    class Aipex_Widget_Transcript extends Aipex_Podcast_Elementor_Widget {
        public function get_name(){ return 'aipex_widget_transcript'; }
        public function get_title(){ return 'Transcript'; }
        public function get_icon(){ return 'eicon-document-file'; }
        protected function register_controls(){
            $this->content_section();
            $this->add_control('collapsible',['label'=>'Collapsible','type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes','description'=>'Adds a toggle button so the transcript can be expanded/collapsed.']);
            $this->add_control('collapsed_label',['label'=>'Collapsed Button Label','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'Show Transcript']);
            $this->add_control('expanded_label',['label'=>'Expanded Button Label','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'Hide Transcript']);
            $this->end_content();
        }
        protected function render(){
            $s=$this->get_settings_for_display();
            $v=Aipex_Podcast_Fields::field('transcript');
            if(!$v) return;
            if($s['collapsible']==='yes'){
                $collapsed=esc_html($s['collapsed_label']??'Show Transcript');
                $expanded=esc_html($s['expanded_label']??'Hide Transcript');
                echo '<div class="aipex-transcript-wrap">';
                echo '<button class="aipex-btn aipex-transcript-toggle" type="button" data-collapsed="'.$collapsed.'" data-expanded="'.$expanded.'">'.$collapsed.'</button>';
                echo '<div class="aipex-transcript" hidden>'.wp_kses_post($v).'</div>';
                echo '</div>';
            } else {
                echo '<div class="aipex-transcript">'.wp_kses_post($v).'</div>';
            }
            wp_enqueue_style('aipex-podcast'); wp_enqueue_script('aipex-podcast');
        }
    }
}

// ============================================================================
// 7. BREADCRUMBS
// ============================================================================
if (class_exists('Aipex_Podcast_Elementor_Widget')) {
    class Aipex_Widget_Breadcrumbs extends Aipex_Podcast_Elementor_Widget {
        public function get_name(){ return 'aipex_widget_breadcrumbs'; }
        public function get_title(){ return 'Breadcrumbs'; }
        public function get_icon(){ return 'eicon-navigation-horizontal'; }
        protected function register_controls(){
            $this->content_section();
            $this->add_control('separator',['label'=>'Separator','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'›']);
            $this->add_control('show_archive',['label'=>'Show Archive Link','type'=>\Elementor\Controls_Manager::SWITCHER,'default'=>'yes','description'=>'Includes a link to the post type archive (e.g. All Shows) in the trail.']);
            $this->end_content();
        }
        protected function render(){
            $s=$this->get_settings_for_display();
            echo do_shortcode('[aipex_breadcrumbs separator="'.esc_attr($s['separator']??'›').'" show_archive="'.($s['show_archive']==='yes'?1:0).'"]');
        }
    }
}

// ============================================================================
// 8. EPISODE SEARCH
// ============================================================================
if (class_exists('Aipex_Podcast_Elementor_Widget')) {
    class Aipex_Widget_Search extends Aipex_Podcast_Elementor_Widget {
        public function get_name(){ return 'aipex_widget_search'; }
        public function get_title(){ return 'Episode Search'; }
        public function get_icon(){ return 'eicon-search'; }
        protected function register_controls(){
            $this->content_section();
            $this->add_control('placeholder',['label'=>'Placeholder Text','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'Search episodes…']);
            $this->add_limit(10);
            $this->end_content();
        }
        protected function render(){
            $s=$this->get_settings_for_display();
            echo do_shortcode('[aipex_search placeholder="'.esc_attr($s['placeholder']??'Search episodes…').'" limit="'.intval($s['limit']??10).'"]');
        }
    }
}

// ============================================================================
// 9. EPISODE FILTERS
// ============================================================================
if (class_exists('Aipex_Podcast_Elementor_Widget')) {
    class Aipex_Widget_Episode_Filters extends Aipex_Podcast_Elementor_Widget {
        public function get_name(){ return 'aipex_widget_episode_filters'; }
        public function get_title(){ return 'Episode Filters'; }
        public function get_icon(){ return 'eicon-filters'; }
        protected function register_controls(){
            $this->content_section();
            $this->add_control('filter_by',['label'=>'Filter By','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'shows','options'=>['shows'=>'Shows','presenters'=>'Presenters','both'=>'Shows & Presenters'],'description'=>'Renders filter buttons targeting the episode grid on the same page.']);
            $this->add_control('style',['label'=>'Style','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'buttons','options'=>['buttons'=>'Buttons','dropdown'=>'Dropdown']]);
            $this->end_content();
        }
        protected function render(){
            $s=$this->get_settings_for_display();
            echo do_shortcode('[aipex_episode_filters filter_by="'.esc_attr($s['filter_by']??'shows').'" style="'.esc_attr($s['style']??'buttons').'"]');
        }
    }
}

// ============================================================================
// 10. RELATIONSHIP GRID (moved from Phase 2; kept here with proper widget class)
// ============================================================================
if (class_exists('Aipex_Podcast_Elementor_Widget')) {
    class Aipex_Widget_Relationship_Grid extends Aipex_Podcast_Elementor_Widget {
        public function get_name(){ return 'aipex_widget_relationship_grid'; }
        public function get_title(){ return 'Relationship Grid'; }
        public function get_icon(){ return 'eicon-posts-grid'; }
        protected function register_controls(){
            $this->content_section();
            $this->add_control('relationship',['label'=>'Relationship','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'episodes','options'=>['episodes'=>'Episodes','shows'=>'Shows','hosts'=>'Hosts','guests'=>'Guests','sponsors'=>'Sponsors'],'description'=>'What to show relative to the current page (or the entity ID below if set).']);
            $this->add_entity_override();
            $this->add_limit(12);
            $this->end_content();
        }
        protected function render(){
            $s=$this->get_settings_for_display();
            echo do_shortcode('[aipex_relationship_grid relationship="'.esc_attr($s['relationship']??'episodes').'" entity_id="'.intval($s['entity_id']??0).'" limit="'.intval($s['limit']??12).'"]');
        }
    }
}

// ============================================================================
// Plugin manager — keeps all registration in one place
// ============================================================================
class Aipex_Podcast_Elementor {
    public static function init(){
        add_action('elementor/widgets/register',[__CLASS__,'register']);
        add_action('elementor/elements/categories_registered',[__CLASS__,'category']);
        // Transcript toggle JS (tiny, only needed when widget is rendered)
        add_action('wp_footer',[__CLASS__,'transcript_js']);
        // Entity Hero CSS additions
        add_action('wp_head',[__CLASS__,'entity_hero_css']);
    }

    public static function category($manager){
        if(method_exists($manager,'add_category'))
            $manager->add_category('aipex-podcast-system',['title'=>'Aipex Podcast System','icon'=>'fa fa-microphone']);
    }

    /**
     * Legacy shortcode-wrapper definitions. These stay as wrappers since
     * they're placed in existing Elementor templates and still work fine via
     * the base shortcode-wrapper class. New features get a proper widget class
     * above instead.
     */
    public static function widget_definitions(){
        return [
            ['aipex_widget_player_legacy','Podcast Player (legacy)','aipex_podcast_player'],
            ['aipex_widget_episode_grid','Episode Grid','aipex_podcast_grid'],
            ['aipex_widget_show_episodes','Show Episodes','aipex_show_podcasts'],
            ['aipex_widget_presenter_podcasts','Presenter Podcasts','aipex_presenter_podcasts'],
            ['aipex_widget_series_grid','Series Grid','aipex_series_grid'],
            ['aipex_widget_presenter_grid','Presenter Grid','aipex_presenter_grid'],
            ['aipex_widget_guest_grid','Guest Grid','aipex_guest_grid'],
            ['aipex_widget_summary','Episode Summary','aipex_podcast_summary'],
            ['aipex_widget_main_points','Main Points','aipex_podcast_main_points'],
            ['aipex_widget_transcript_legacy','Transcript (legacy)','aipex_podcast_transcript'],
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
            ['aipex_widget_nav','Next / Previous Navigation','aipex_next_previous'],
            ['aipex_widget_floating_legacy','Floating Player (legacy)','aipex_floating_player'],
        ];
    }

    /** New proper widget classes — each has real controls beyond just "Limit". */
    public static function proper_widget_classes(){
        return [
            'Aipex_Widget_Audio_Player',
            'Aipex_Widget_Floating_Player',
            'Aipex_Widget_Entity_Hero',
            'Aipex_Widget_Episode_Meta',
            'Aipex_Widget_Social_Links',
            'Aipex_Widget_Transcript',
            'Aipex_Widget_Breadcrumbs',
            'Aipex_Widget_Search',
            'Aipex_Widget_Episode_Filters',
            'Aipex_Widget_Relationship_Grid',
        ];
    }

    public static function register($widgets_manager){
        if(!class_exists('\\Elementor\\Widget_Base')) return;
        // Legacy shortcode wrappers
        if(class_exists('Aipex_Podcast_Elementor_Shortcode_Widget')){
            foreach(self::widget_definitions() as [$name,$title,$shortcode])
                $widgets_manager->register(new Aipex_Podcast_Elementor_Shortcode_Widget([],null,$name,$title,$shortcode));
        }
        // Proper widgets with real controls
        foreach(self::proper_widget_classes() as $class){
            if(class_exists($class)) $widgets_manager->register(new $class());
        }
    }

    public static function transcript_js(){
        if(!did_action('wp_enqueue_scripts')) return;
        ?>
        <script>
        (function($){$(document).on('click','.aipex-transcript-toggle',function(){var $t=$(this),$c=$t.siblings('.aipex-transcript');var open=$c.is('[hidden]');$c.attr('hidden',!open);$t.text(open?$t.data('expanded'):$t.data('collapsed'));});})($=window.jQuery||window.$);
        </script>
        <?php
    }

    public static function entity_hero_css(){
        echo '<style>.aipex-entity-hero{display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;margin:0 0 24px}.aipex-entity-hero-image{flex:0 0 auto;max-width:280px}.aipex-entity-hero-image img{width:100%;border-radius:12px}.aipex-entity-hero-body{flex:1 1 280px}.aipex-entity-title{margin:0 0 12px;font-size:clamp(22px,4vw,36px)}.aipex-entity-excerpt{color:#374151;line-height:1.65;margin:0}</style>';
    }
}
