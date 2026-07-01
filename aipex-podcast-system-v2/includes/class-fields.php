<?php
if (!defined('ABSPATH')) exit;

/**
 * Central field access layer for Aipex Podcast System.
 *
 * This class is the only place plugin code should need to know about ACF
 * field names, legacy aliases, serialized relationship formats, or fallback
 * WordPress post meta storage.
 */
class Aipex_Podcast_Fields {
    public static function aliases($key){
        $map = [
            'audio_file' => ['aipex_audio_file','ovau_audio_url','audio_url','audio_file'],
            'dropbox_url' => ['aipex_dropbox_url','dropbox_file_url','dropbox_url'],
            'soundcloud_url' => ['aipex_soundcloud_url','ovau_soundcloud_url','soundcloud_url'],
            'duration' => ['aipex_duration','duration','ovau_duration'],
            'episode_summary' => ['aipex_episode_summary','summary','episode_summary'],
            'main_points' => ['aipex_main_points','aipex_main_points_covered','main_points'],
            'transcript' => ['aipex_transcript','transcript'],
            'series' => ['aipex_series','podcast_series','series_id','series'],
            'presenters' => ['aipex_presenters','presenters','presenter','presenter_id','ovau_host_id'],
            'guests' => ['aipex_guests','guests','guest','guest_id'],
            'sponsors' => ['aipex_sponsors','sponsors','sponsor','sponsor_id'],
            'series_overview' => ['aipex_series_about','aipex_series_details','series_overview','show_details'],
            'series_main_points' => ['aipex_series_main_points','series_main_points'],
            'series_episode_summaries' => ['aipex_series_episode_summaries','series_episode_summaries'],
            'presenter_about' => ['aipex_presenter_about','ovau_host_about','presenter_about','about'],
            'website' => ['aipex_series_website','series_website','aipex_presenter_website','presenter_website','aipex_sponsor_website','website'],
            'rss_url' => ['aipex_series_rss','series_rss','rss_feed','rss','aipex_presenter_rss','rss_url'],
            'spotify_url' => ['aipex_series_spotify','series_spotify','spotify','aipex_presenter_spotify','spotify_url'],
            'apple_url' => ['aipex_series_apple','series_apple','apple_podcasts_url','apple','aipex_presenter_apple','apple_url'],
            'youtube_url' => ['aipex_series_youtube','series_youtube','youtube','aipex_presenter_youtube','youtube_url'],
            'amazon_url' => ['aipex_series_amazon','series_amazon','amazon_music_url','amazon','aipex_presenter_amazon','amazon_url'],
            'pocketcasts_url' => ['aipex_series_pocketcasts','series_pocketcasts','pocket_casts_url','pocketcasts','aipex_presenter_pocketcasts','pocketcasts_url'],
            'facebook' => ['facebook','facebook_url','aipex_facebook'],
            'instagram' => ['instagram','instagram_url','aipex_instagram'],
            'linkedin' => ['linkedin','linkedin_url','aipex_linkedin'],
        ];
        return $map[$key] ?? [];
    }

    public static function keys($key){
        return array_values(array_unique(array_merge([$key], self::aliases($key))));
    }

    public static function get($key, $post_id=null, $default=''){
        $post_id = $post_id ?: get_the_ID();
        foreach (self::keys($key) as $field_key) {
            if (function_exists('get_field')) {
                $value = get_field($field_key, $post_id);
                if (self::has_value($value)) return $value;
            }
            $value = get_post_meta($post_id, $field_key, true);
            if (self::has_value($value)) return $value;
        }
        return $default;
    }

    public static function update($key, $value, $post_id){
        $primary = $key;
        $updated = false;
        if (function_exists('update_field')) {
            $updated = update_field($primary, $value, $post_id);
        }
        if (!$updated) $updated = update_post_meta($post_id, $primary, $value);

        // Relationship-bearing fields keep the join table in sync regardless
        // of write path — admin edit screen (via acf/save_post, see
        // class-core.php), the v1→v2 migration tool, or programmatic
        // updates like the sponsor admin tools in class-admin.php. This is
        // intentionally a full re-sync of the post rather than a single
        // edge, since it's cheap (a handful of small DELETE/INSERTs) and
        // guarantees correctness without every call site needing to know
        // about the relationship layer.
        if (class_exists('Aipex_Podcast_Relationships') && in_array($key, ['series','presenters','guests','sponsors','series_sponsors'], true)) {
            Aipex_Podcast_Relationships::sync_post($post_id);
        }

        return $updated;
    }

    public static function has_value($value){
        return !($value === null || $value === false || $value === '' || $value === []);
    }

    public static function ids($key, $post_id=null){
        return self::normalise_ids(self::get($key, $post_id, []));
    }

    public static function normalise_ids($value){
        if (!$value && $value !== 0 && $value !== '0') return [];
        if (is_numeric($value)) return [(int)$value];
        if (is_object($value) && isset($value->ID)) return [(int)$value->ID];
        if (is_string($value)) {
            $maybe = maybe_unserialize($value);
            if ($maybe !== $value) return self::normalise_ids($maybe);
            if (preg_match_all('/\d+/', $value, $matches)) return array_values(array_unique(array_map('intval', $matches[0])));
            return [];
        }
        if (is_array($value)) {
            $ids = [];
            foreach ($value as $item) $ids = array_merge($ids, self::normalise_ids($item));
            return array_values(array_unique(array_filter(array_map('intval', $ids))));
        }
        return [];
    }

    public static function audio_url($post_id=null){
        $post_id = $post_id ?: get_the_ID();
        $file = self::get('audio_file', $post_id);
        if (is_array($file) && !empty($file['url'])) return $file['url'];
        if (is_object($file) && !empty($file->ID)) return wp_get_attachment_url((int)$file->ID);
        if (is_numeric($file)) return wp_get_attachment_url((int)$file);
        if (is_string($file) && preg_match('~^https?://~', $file)) return $file;
        $dropbox = self::get('dropbox_url', $post_id);
        if ($dropbox) return self::dropbox_direct($dropbox);
        $soundcloud = self::get('soundcloud_url', $post_id);
        return $soundcloud ?: '';
    }

    public static function dropbox_direct($url){
        $url = str_replace('www.dropbox.com', 'dl.dropboxusercontent.com', (string)$url);
        return preg_replace('~\?dl=0$~', '?raw=1', $url);
    }

    public static function relationship_meta_query($key, $id){
        $id = absint($id);
        $or = ['relation'=>'OR'];
        foreach (self::keys($key) as $meta_key) {
            $or[] = ['key'=>$meta_key,'value'=>'"'.$id.'"','compare'=>'LIKE'];
            $or[] = ['key'=>$meta_key,'value'=>'i:'.$id.';','compare'=>'LIKE'];
            $or[] = ['key'=>$meta_key,'value'=>'s:'.strlen((string)$id).':"'.$id.'";','compare'=>'LIKE'];
            $or[] = ['key'=>$meta_key,'value'=>(string)$id,'compare'=>'='];
        }
        return $or;
    }

    public static function presenter_ids($post_id=null){ return self::ids('presenters', $post_id); }
    public static function series_ids($post_id=null){ return self::ids('series', $post_id); }
    public static function guest_ids($post_id=null){ return self::ids('guests', $post_id); }
    public static function sponsor_ids($post_id=null){ return self::ids('sponsors', $post_id); }

    public static function duration($post_id=null){ return self::get('duration', $post_id, ''); }
    public static function summary($post_id=null){ return self::get('episode_summary', $post_id, ''); }
    public static function transcript($post_id=null){ return self::get('transcript', $post_id, ''); }
    public static function main_points($post_id=null){ return self::get('main_points', $post_id, []); }
    public static function presenter_about($post_id=null){ return self::get('presenter_about', $post_id, ''); }
    public static function series_overview($post_id=null){ return self::get('series_overview', $post_id, ''); }

    /**
     * --- Compatibility aliases ---
     * Older code referred to this layer as Aipex_Podcast_Utils with slightly
     * different method names. These aliases let every part of the plugin use
     * ONE field-access layer instead of three competing implementations.
     */
    public static function field($key, $post_id=null, $default=''){ return self::get($key, $post_id, $default); }
    public static function update_field($key, $value, $post_id){ return self::update($key, $value, $post_id); }

    public static function button($url, $text){
        return '<a class="aipex-btn" href="'.esc_url($url).'"><span class="aipex-btn-icon" aria-hidden="true">♫</span> '.esc_html($text).'</a>';
    }

    public static function current_context($type){
        if (is_singular($type)) return get_the_ID();
        $qid = get_queried_object_id();
        if ($qid && get_post_type($qid) === $type) return $qid;
        global $post;
        return ($post && $post->post_type === $type) ? $post->ID : 0;
    }

    public static function normalize($s){
        $s = strtolower(wp_strip_all_tags((string)$s));
        $s = preg_replace('/\.[a-z0-9]{2,5}$/', '', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim($s);
    }

    public static function match_score($a, $b){
        $a = self::normalize($a); $b = self::normalize($b);
        if (!$a || !$b) return 0;
        similar_text($a, $b, $percent);
        if ((strpos($a, $b) !== false) || (strpos($b, $a) !== false)) $percent = max($percent, 92);
        return (int)round($percent);
    }

    /**
     * Single canonical episode query used by every shortcode/widget/AJAX
     * handler. This is the ONLY place relationship matching for episodes
     * should live — do not re-implement this elsewhere.
     *
     * Filtering now goes through Aipex_Podcast_Relationships (a real join
     * table) instead of meta_query LIKE scans against serialized ACF data —
     * faster at scale and immune to the alias/serialization-format drift
     * that caused the presenter relationship bugs in earlier builds.
     */
    public static function query_episodes($args=[]){
        $post_in = null; // null = no relationship filter applied
        $filters = [
            'series_id' => Aipex_Podcast_Relationships::TYPE_SHOW,
            'presenter_id' => Aipex_Podcast_Relationships::TYPE_HOST,
            'guest_id' => Aipex_Podcast_Relationships::TYPE_GUEST,
            'sponsor_id' => Aipex_Podcast_Relationships::TYPE_SPONSOR,
        ];
        foreach ($filters as $arg_key => $entity_type) {
            if (empty($args[$arg_key])) continue;
            $ids = Aipex_Podcast_Relationships::episodes_for($entity_type, (int)$args[$arg_key]);
            $post_in = $post_in === null ? $ids : array_values(array_intersect($post_in, $ids));
            unset($args[$arg_key]);
        }
        $base = ['post_type'=>'aipex_podcast','posts_per_page'=>12,'post_status'=>'publish','orderby'=>'date','order'=>'DESC'];
        if ($post_in !== null) $base['post__in'] = $post_in ?: [0]; // [0] forces zero results rather than "no filter"
        return new WP_Query(array_merge($base, $args));
    }
}
