<?php
if (!defined('ABSPATH')) exit;

/**
 * [aipex_episode_header] shortcode + Elementor widget.
 *
 * Renders a cohesive header bar above the audio player:
 *   Row 1 — episode title
 *   Row 2 — series · duration · published date · ♥ likes · 💬 comments
 *   Row 3 — presenters · sponsors · guests (linked to their own pages)
 *   Row 4 — episode materials (download links) — only if set
 */
class Aipex_Podcast_Episode_Header {

    public static function register(){
        add_shortcode('aipex_episode_header', [__CLASS__, 'render']);
    }

    public static function render($atts=[]){
        $id = get_the_ID();
        if (!$id || get_post_type($id) !== 'aipex_podcast') return '';

        wp_enqueue_style('aipex-podcast');
        wp_enqueue_script('aipex-podcast');

        $title    = get_the_title($id);
        $duration = Aipex_Podcast_Fields::get('duration', $id);
        $date     = get_the_date('j M Y', $id);
        $likes    = class_exists('Aipex_Podcast_Analytics') ? Aipex_Podcast_Analytics::get_like_count($id) : 0;
        $comments = (int)get_comments_number($id);

        // Series
        $series_ids  = Aipex_Podcast_Relationships::shows_for(Aipex_Podcast_Relationships::TYPE_EPISODE, $id);
        // Hosts
        $host_ids    = Aipex_Podcast_Relationships::hosts_for(Aipex_Podcast_Relationships::TYPE_EPISODE, $id);
        // Guests
        $guest_ids   = Aipex_Podcast_Relationships::guests_for(Aipex_Podcast_Relationships::TYPE_EPISODE, $id);
        // Sponsors
        $sponsor_ids = Aipex_Podcast_Relationships::sponsors_for(Aipex_Podcast_Relationships::TYPE_EPISODE, $id);
        // Materials
        $materials   = get_field('episode_materials', $id) ?: [];

        $out  = '<div class="aipex-episode-header">';

        // ── Title ──
        $out .= '<h1 class="aipex-ep-title">'.esc_html($title).'</h1>';

        // ── Meta row ──
        $meta = [];
        if ($series_ids) {
            $series_link = '<a class="aipex-ep-series" href="'.esc_url(get_permalink($series_ids[0])).'">'.esc_html(get_the_title($series_ids[0])).'</a>';
            $meta[] = $series_link;
        }
        if ($duration) $meta[] = '<span class="aipex-ep-duration">'.esc_html($duration).'</span>';
        if ($date)     $meta[] = '<span class="aipex-ep-date">'.esc_html($date).'</span>';

        // Like button — hide at zero
        $like_html = '<button type="button" class="aipex-like-btn aipex-ep-like" data-post-id="'.esc_attr($id).'" aria-label="Like this episode">'
            .'<span class="aipex-like-heart">♥</span>'
            .'<span class="aipex-like-count">'.($likes > 0 ? esc_html(number_format($likes)) : 0).'</span>'
            .'</button>';
        if ($likes > 0) $meta[] = $like_html;
        else            $meta[] = $like_html; // always include, CSS hides zero state

        // Comment count — hide at zero
        if ($comments > 0) {
            $label = $comments === 1 ? '1 Comment' : number_format($comments).' Comments';
            $meta[] = '<a class="aipex-ep-comments" href="#comments">💬 '.esc_html($label).'</a>';
        }

        $out .= '<div class="aipex-ep-meta">'.implode('<span class="aipex-ep-sep">·</span>', $meta).'</div>';

        // ── Entity links row ──
        $entities = [];

        foreach ($host_ids as $hid) {
            $entities[] = '<a class="aipex-ep-entity aipex-ep-presenter" href="'.esc_url(get_permalink($hid)).'">👤 '.esc_html(get_the_title($hid)).'</a>';
        }
        foreach ($sponsor_ids as $sid) {
            $entities[] = '<a class="aipex-ep-entity aipex-ep-sponsor" href="'.esc_url(get_permalink($sid)).'">🏢 '.esc_html(get_the_title($sid)).'</a>';
        }
        foreach ($guest_ids as $gid) {
            $entities[] = '<a class="aipex-ep-entity aipex-ep-guest" href="'.esc_url(get_permalink($gid)).'">👥 '.esc_html(get_the_title($gid)).'</a>';
        }

        if ($entities) $out .= '<div class="aipex-ep-entities">'.implode('', $entities).'</div>';

        // ── Materials row ──
        if ($materials) {
            $out .= '<div class="aipex-ep-materials">';
            foreach ($materials as $mat) {
                $label = esc_html($mat['material_label'] ?? 'Download');
                // File takes priority over URL
                $url = !empty($mat['material_file']) ? $mat['material_file'] : ($mat['material_url'] ?? '');
                if ($url) $out .= '<a class="aipex-ep-material" href="'.esc_url($url).'" target="_blank" rel="noopener">📎 '.$label.'</a>';
            }
            $out .= '</div>';
        }

        $out .= '</div>';
        return $out;
    }
}
