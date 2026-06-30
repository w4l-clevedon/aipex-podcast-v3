<?php
if (!defined('ABSPATH')) exit;

/**
 * Thin, uniform wrapper around a single post that's part of the relationship
 * graph (episode, show, host, guest, sponsor). Built on top of
 * Aipex_Podcast_Relationships and Aipex_Podcast_Fields — this class adds no
 * new data access of its own, it just gives calling code (widgets, REST
 * endpoints, future Elementor controls) one consistent shape to work with
 * regardless of which entity type it's holding.
 *
 *   $entity = Aipex_Podcast_Entity::load($id);
 *   if ($entity) {
 *       echo $entity->title();
 *       foreach ($entity->episodes() as $episode) { ... }
 *   }
 */
class Aipex_Podcast_Entity {
    private $post;
    private $entity_type;

    private function __construct(WP_Post $post, $entity_type){
        $this->post = $post;
        $this->entity_type = $entity_type;
    }

    public static function load($id){
        $id = (int)$id;
        if (!$id) return null;
        $post = get_post($id);
        if (!$post) return null;
        $entity_type = Aipex_Podcast_Relationships::entity_type_for_post_type($post->post_type);
        if (!$entity_type) return null;
        return new self($post, $entity_type);
    }

    public static function load_current(){
        $id = get_the_ID();
        return $id ? self::load($id) : null;
    }

    public function id(){ return $this->post->ID; }
    public function type(){ return $this->entity_type; }
    public function post(){ return $this->post; }
    public function title(){ return get_the_title($this->post); }
    public function url(){ return get_permalink($this->post); }
    public function excerpt($words=24){ return wp_trim_words(wp_strip_all_tags(get_the_excerpt($this->post)), $words); }
    public function image($size='medium_large'){ return get_the_post_thumbnail($this->post, $size); }

    private function related_posts($target_type, $args=[]){
        $ids = Aipex_Podcast_Relationships::related($this->entity_type, $this->post->ID, $target_type);
        if (!$ids) return [];
        $defaults = [
            'post__in' => $ids,
            'post_type' => Aipex_Podcast_Relationships::post_type_for($target_type),
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'post__in',
        ];
        return get_posts(array_merge($defaults, $args));
    }

    public function episodes($args=[]){ return $this->related_posts(Aipex_Podcast_Relationships::TYPE_EPISODE, $args); }
    public function shows($args=[]){ return $this->related_posts(Aipex_Podcast_Relationships::TYPE_SHOW, $args); }
    public function hosts($args=[]){ return $this->related_posts(Aipex_Podcast_Relationships::TYPE_HOST, $args); }
    public function guests($args=[]){ return $this->related_posts(Aipex_Podcast_Relationships::TYPE_GUEST, $args); }
    public function sponsors($args=[]){ return $this->related_posts(Aipex_Podcast_Relationships::TYPE_SPONSOR, $args); }

    /** Web links (website, RSS, Spotify, social, etc.) — not contact details, see contact(). */
    public function social_links(){
        $map = ['website'=>'Website','rss_url'=>'RSS','spotify_url'=>'Spotify','apple_url'=>'Apple Podcasts','youtube_url'=>'YouTube','amazon_url'=>'Amazon','pocketcasts_url'=>'Pocket Casts','facebook'=>'Facebook','instagram'=>'Instagram','linkedin'=>'LinkedIn'];
        $out = [];
        foreach ($map as $key => $label) {
            $url = Aipex_Podcast_Fields::field($key, $this->post->ID);
            if ($url) $out[$key] = ['label' => $label, 'url' => $url];
        }
        return $out;
    }

    /** Direct contact details (host/presenter only, in practice — empty for other entity types). */
    public function contact(){
        return [
            'email' => Aipex_Podcast_Fields::field('contact_email', $this->post->ID),
            'phone' => Aipex_Podcast_Fields::field('contact_number', $this->post->ID),
        ];
    }
}
