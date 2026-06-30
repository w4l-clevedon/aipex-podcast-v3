<?php
if (!defined('ABSPATH')) exit;

class Aipex_Podcast_Shortcodes {
    public static function register(){
        $shortcodes = [
            'aipex_podcast_player','aipex_floating_player','aipex_podcast_grid','aipex_latest_podcasts',
            'aipex_series_podcasts','aipex_show_podcasts','aipex_presenter_podcasts',
            'aipex_podcast_summary','aipex_podcast_main_points','aipex_podcast_transcript',
            'aipex_series_grid','aipex_presenter_grid','aipex_presenters','aipex_guest_grid','aipex_guests','aipex_guest',
            'aipex_series_details','aipex_series_main_points','aipex_series_episode_summaries',
            'aipex_presenter_about','aipex_presenter_box','aipex_presenter_links','aipex_subscribe','aipex_next_previous',
            'aipex_sponsors','aipex_sponsor','aipex_sponsor_grid','aipex_show_summary','aipex_show_main_topics','aipex_episode_series',
            'aipex_relationship_grid',
            // Phase 3.5 — new proper shortcodes (each has a matching Elementor widget with real controls)
            'aipex_breadcrumbs','aipex_episode_meta','aipex_search','aipex_episode_filters',
        ];
        foreach ($shortcodes as $sc) add_shortcode($sc, [__CLASS__, $sc]);
    }

    private static function enqueue(){ wp_enqueue_style('aipex-podcast'); wp_enqueue_script('aipex-podcast'); }

    public static function aipex_podcast_player($atts=[]){
        self::enqueue();
        $id = get_the_ID();
        $url = Aipex_Podcast_Fields::audio_url($id);
        if (!$url) return '<p class="aipex-empty">No audio found.</p>';
        return '<div class="aipex-player"><audio controls preload="none" src="'.esc_url($url).'"></audio></div>';
    }

    public static function aipex_floating_player($atts=[]){
        $atts = shortcode_atts(['limit'=>12,'context'=>'auto'], $atts);
        self::enqueue();

        $query_args = ['posts_per_page'=>(int)$atts['limit']];
        if ($atts['context'] === 'auto') {
            $series_id = Aipex_Podcast_Fields::current_context('aipex_series');
            $presenter_id = Aipex_Podcast_Fields::current_context('aipex_presenter');
            if ($series_id) $query_args['series_id'] = $series_id;
            if ($presenter_id) $query_args['presenter_id'] = $presenter_id;
        }

        $q = Aipex_Podcast_Fields::query_episodes($query_args);
        $tracks = [];
        while ($q->have_posts()) { $q->the_post();
            $url = Aipex_Podcast_Fields::audio_url(get_the_ID());
            if (!$url) continue;
            $tracks[] = [
                'id'=>get_the_ID(),
                'title'=>get_the_title(),
                'url'=>$url,
                'link'=>get_permalink(),
                'date'=>get_the_date('Y-m-d'),
                'duration'=>Aipex_Podcast_Fields::field('duration', get_the_ID(), '')
            ];
        }
        wp_reset_postdata();
        if (!$tracks) return '';
        $first = $tracks[0];

        $out = '<div class="aipex-floating-player aipex-floating-player-v3" data-current="0">';
        $out .= '<div class="aipex-floating-bar">';
        $out .= '<button class="aipex-player-minimise" type="button" aria-label="Minimise player">🎧</button>';
        $out .= '<div class="aipex-floating-now"><span>Now playing</span><strong>'.esc_html($first['title']).'</strong></div>';
        $out .= '<audio id="aipex-floating-audio" controls preload="none" src="'.esc_url($first['url']).'"></audio>';
        $out .= '<div class="aipex-floating-controls"><button type="button" class="aipex-float-prev" aria-label="Previous episode">‹</button><button type="button" class="aipex-float-next" aria-label="Next episode">›</button></div>';
        $out .= '<button class="aipex-episode-drawer-toggle" type="button"><span class="aipex-btn-icon">☰</span> Episodes <span class="aipex-track-count">'.count($tracks).'</span> <span class="aipex-caret">⌄</span></button>';
        $out .= '</div>';

        $out .= '<div class="aipex-episode-drawer" hidden>';
        $out .= '<div class="aipex-episode-drawer-head"><strong>Choose an episode</strong><input type="search" class="aipex-episode-search" placeholder="Search episodes…"></div>';
        $out .= '<div class="aipex-episode-list">';
        foreach ($tracks as $i=>$t) {
            $meta = trim(($t['date'] ?? '') . (!empty($t['duration']) ? ' · '.$t['duration'] : ''));
            $out .= '<button type="button" class="aipex-floating-track'.($i===0?' is-active':'').'" data-index="'.esc_attr($i).'" data-audio="'.esc_url($t['url']).'" data-title="'.esc_attr($t['title']).'">';
            $out .= '<span class="aipex-track-play">▶</span><span class="aipex-track-text"><strong>'.esc_html($t['title']).'</strong>'.($meta?'<small>'.esc_html($meta).'</small>':'').'</span>';
            $out .= '</button>';
        }
        $out .= '</div></div></div>';
        return $out;
    }

    public static function grid($args=[]){
        self::enqueue();
        $limit = !empty($args['limit']) ? (int)$args['limit'] : 12;
        $page  = !empty($args['page']) ? (int)$args['page'] : 1;
        $q = Aipex_Podcast_Fields::query_episodes([
            'posts_per_page'=>$limit,
            'paged'=>$page,
            'series_id'=>$args['series_id']??0,
            'presenter_id'=>$args['presenter_id']??0,
        ]);
        $context=['kind'=>'episodes','series_id'=>(int)($args['series_id']??0),'presenter_id'=>(int)($args['presenter_id']??0),'limit'=>$limit];
        $out='<div class="aipex-card-grid aipex-episode-grid" data-context="'.esc_attr(wp_json_encode($context)).'">';
        while($q->have_posts()){ $q->the_post(); $out .= self::episode_card(get_the_ID()); }
        wp_reset_postdata();
        $out .= '</div>';
        if($q->max_num_pages > $page) $out .= self::load_more_button('episodes', $context, 'Load More Episodes');
        return $out;
    }

    private static function load_more_button($kind, $context, $label='Load More'){
        return '<p class="aipex-load-wrap"><button class="aipex-btn aipex-load-more" type="button" data-page="1" data-kind="'.esc_attr($kind).'" data-context="'.esc_attr(wp_json_encode($context)).'"><span class="aipex-btn-icon">＋</span> '.esc_html($label).'</button></p>';
    }

    public static function episode_card($id){
        $img = get_the_post_thumbnail($id,'medium_large');
        $sum = Aipex_Podcast_Fields::field('episode_summary',$id,get_the_excerpt($id));
        $date = get_the_date('Y-m-d', $id);
        $duration = Aipex_Podcast_Fields::field('duration',$id,'');
        $meta = trim($date . ($duration ? ' | '.$duration : ''));
        return '<article class="aipex-card aipex-episode-card">'.
            ($img?'<a class="aipex-card-image" href="'.esc_url(get_permalink($id)).'">'.$img.'</a>':'').
            '<div class="aipex-card-body"><h3><a href="'.esc_url(get_permalink($id)).'">'.esc_html(get_the_title($id)).'</a></h3>'.
            '<div class="aipex-card-rule"></div>'.
            ($meta?'<p class="aipex-card-meta">'.esc_html($meta).'</p>':'').
            ($sum?'<p>'.esc_html(wp_trim_words(wp_strip_all_tags($sum),22)).'</p>':'').
            Aipex_Podcast_Fields::button(get_permalink($id),'Listen To '.get_the_title($id)).
            '</div></article>';
    }

    public static function aipex_podcast_grid($atts=[]){ $a=shortcode_atts(['limit'=>12,'series_id'=>0,'presenter_id'=>0],$atts); return self::grid($a); }
    public static function aipex_latest_podcasts($atts=[]){ return self::aipex_podcast_grid($atts); }
    public static function aipex_series_podcasts($atts=[]){ $id=Aipex_Podcast_Fields::current_context('aipex_series'); $a=shortcode_atts(['limit'=>12],$atts); $a['series_id']=$id; return self::grid($a); }
    public static function aipex_show_podcasts($atts=[]){ return self::aipex_series_podcasts($atts); }
    public static function aipex_presenter_podcasts($atts=[]){ $id=Aipex_Podcast_Fields::current_context('aipex_presenter'); $a=shortcode_atts(['limit'=>12],$atts); $a['presenter_id']=$id; return self::grid($a); }

    public static function aipex_podcast_summary(){ $v=Aipex_Podcast_Fields::field('episode_summary'); return $v?'<div class="aipex-summary">'.wp_kses_post($v).'</div>':''; }
    public static function aipex_podcast_main_points(){ $rows=Aipex_Podcast_Fields::field('main_points',null,[]); if(!$rows)return ''; $out='<ul class="aipex-main-points">'; foreach($rows as $r){ $p=is_array($r)?($r['point']??reset($r)):$r; if($p)$out.='<li>'.esc_html($p).'</li>'; } return $out.'</ul>'; }
    public static function aipex_podcast_transcript(){ $v=Aipex_Podcast_Fields::field('transcript'); return $v?'<div class="aipex-transcript">'.wp_kses_post($v).'</div>':''; }

    public static function aipex_series_grid($atts=[]){ $a=shortcode_atts(['limit'=>12],$atts); return self::post_grid('aipex_series',(int)$a['limit'],'show'); }
    public static function aipex_presenter_grid($atts=[]){ $a=shortcode_atts(['limit'=>12],$atts); return self::post_grid('aipex_presenter',(int)$a['limit'],'presenter'); }
    public static function aipex_presenters($atts=[]){ return self::aipex_presenter_grid($atts); }
    public static function aipex_guest_grid($atts=[]){ $a=shortcode_atts(['limit'=>12],$atts); return self::post_grid('aipex_guest',(int)$a['limit'],'guest'); }
    public static function aipex_guests($atts=[]){ return self::aipex_guest_grid($atts); }
    public static function aipex_guest($atts=[]){ $a=shortcode_atts(['id'=>0],$atts); $id=(int)$a['id']; if(!$id) $id=get_the_ID(); return (get_post_type($id)==='aipex_guest') ? self::profile_card($id,'guest') : ''; }

    public static function post_grid($type,$limit,$label,$page=1){
        self::enqueue();
        $q = new WP_Query(['post_type'=>$type,'posts_per_page'=>$limit,'paged'=>$page,'post_status'=>'publish','orderby'=>'title','order'=>'ASC']);
        $kind = $type === 'aipex_series' ? 'series' : 'presenters';
        $context=['kind'=>$kind,'type'=>$type,'limit'=>$limit];
        $out='<div class="aipex-card-grid aipex-'.$kind.'-grid" data-context="'.esc_attr(wp_json_encode($context)).'">';
        while($q->have_posts()){ $q->the_post(); $out .= self::profile_card(get_the_ID(), $label); }
        wp_reset_postdata();
        $out.='</div>';
        if($q->max_num_pages > $page) $out .= self::load_more_button($kind, $context, 'Load More '.($kind==='series'?'Shows':'Presenters'));
        return $out;
    }

    public static function profile_card($id, $label){
        $name = get_the_title($id);
        $img = get_the_post_thumbnail($id,'medium_large');
        $excerpt = get_the_excerpt($id);
        if (!$excerpt) $excerpt = Aipex_Podcast_Fields::field($label==='show'?'series_overview':'presenter_about',$id,'');
        return '<article class="aipex-card aipex-profile-card aipex-'.$label.'-card">'.
            ($img?'<a class="aipex-card-image" href="'.esc_url(get_permalink($id)).'">'.$img.'</a>':'').
            '<div class="aipex-card-body"><h3><a href="'.esc_url(get_permalink($id)).'">'.esc_html($name).'</a></h3>'.
            '<div class="aipex-card-rule"></div>'.
            ($excerpt?'<p>'.esc_html(wp_trim_words(wp_strip_all_tags($excerpt),24)).'</p>':'').
            Aipex_Podcast_Fields::button(get_permalink($id),'Learn More About '.$name).
            '</div></article>';
    }

    public static function aipex_series_details(){ $v=Aipex_Podcast_Fields::field('series_overview'); return $v?'<div class="aipex-series-details">'.wp_kses_post($v).'</div>':''; }
    public static function aipex_show_summary(){ return self::aipex_series_details(); }
    public static function aipex_show_main_topics(){ return self::aipex_series_main_points(); }
    public static function aipex_episode_series(){ $ids=Aipex_Podcast_Fields::ids('series'); if(!$ids) return ''; $links=[]; foreach($ids as $id){ if(get_post_status($id)) $links[]='<a href="'.esc_url(get_permalink($id)).'">'.esc_html(get_the_title($id)).'</a>'; } return $links?'<div class="aipex-episode-series"><strong>Show:</strong> '.implode(', ',$links).'</div>':''; }
    public static function aipex_series_main_points(){ $rows=Aipex_Podcast_Fields::field('series_main_points',null,[]); if(!$rows)return ''; $out='<ul class="aipex-main-points">'; foreach($rows as $r){$p=is_array($r)?($r['point']??reset($r)):$r; if($p)$out.='<li>'.esc_html($p).'</li>'; } return $out.'</ul>'; }
    public static function aipex_series_episode_summaries(){ $rows=Aipex_Podcast_Fields::field('series_episode_summaries',null,[]); if(!$rows)return ''; $out='<div class="aipex-episode-summaries">'; foreach($rows as $r){ $eid=$r['episode']??0; $name=$r['episode_name']??($eid?get_the_title($eid):'Episode'); $sum=$r['summary']??''; $out.='<article><h3>'.($eid?'<a href="'.esc_url(get_permalink($eid)).'">'.esc_html($name).'</a>':esc_html($name)).'</h3><p>'.esc_html($sum).'</p></article>'; } return $out.'</div>'; }
    public static function aipex_presenter_about(){ $v=Aipex_Podcast_Fields::field('presenter_about'); if(!$v) $v=get_the_content(); return $v?'<div class="aipex-presenter-about">'.wp_kses_post(wpautop($v)).'</div>':''; }
    public static function aipex_presenter_box(){ $name=get_the_title(); return '<div class="aipex-presenter-box">'.get_the_post_thumbnail(get_the_ID(),'medium').'<h3>'.esc_html($name).'</h3>'.Aipex_Podcast_Fields::button(get_permalink(),'Learn More About '.$name).'</div>'; }

    public static function link_icons($id=null){
        $id=$id?:get_the_ID();
        $map=['website'=>'🌐 Website','rss_url'=>'RSS','spotify_url'=>'Spotify','apple_url'=>'Apple','youtube_url'=>'YouTube','amazon_url'=>'Amazon','pocketcasts_url'=>'Pocket Casts','facebook'=>'Facebook','instagram'=>'Instagram','linkedin'=>'LinkedIn'];
        $out='';
        foreach($map as $k=>$label){ $u=Aipex_Podcast_Fields::field($k,$id); if($u)$out.='<a class="aipex-icon-link" href="'.esc_url($u).'" target="_blank" rel="noopener">'.esc_html($label).'</a>'; }
        // Contact email/phone were captured by ACF but never rendered anywhere
        // on the front end — these aren't web URLs, so they need mailto:/tel:
        // hrefs rather than esc_url() against a plain address/number.
        $email = Aipex_Podcast_Fields::field('contact_email',$id);
        if ($email) $out .= '<a class="aipex-icon-link" href="mailto:'.esc_attr($email).'">✉ Email</a>';
        $phone = Aipex_Podcast_Fields::field('contact_number',$id);
        if ($phone) $out .= '<a class="aipex-icon-link" href="tel:'.esc_attr(preg_replace('/[^0-9+]/','',$phone)).'">📞 '.esc_html($phone).'</a>';
        return $out?'<div class="aipex-icon-links">'.$out.'</div>':'';
    }
    public static function aipex_presenter_links(){ return self::link_icons(); }
    public static function aipex_subscribe(){ $out=self::link_icons(); if(!$out && is_singular('aipex_series')) $out='<div class="aipex-icon-links"><a class="aipex-icon-link" href="'.esc_url(get_post_type_archive_link('aipex_podcast')).'feed/">RSS</a></div>'; return $out; }
    public static function aipex_next_previous(){ $type=get_post_type(); $prev=get_previous_post(); $next=get_next_post(); $out='<nav class="aipex-prev-next">'; if($prev&&$prev->post_type===$type)$out.='<a href="'.esc_url(get_permalink($prev)).'">← '.esc_html(get_the_title($prev)).'</a>'; if($next&&$next->post_type===$type)$out.='<a href="'.esc_url(get_permalink($next)).'">'.esc_html(get_the_title($next)).' →</a>'; return $out.'</nav>'; }



    public static function sponsor_card($id,$message='',$url=''){
        if(!$id || get_post_type($id)!=='aipex_sponsor') return '';
        $name=get_the_title($id);
        $img=get_the_post_thumbnail($id,'medium_large');
        $about=Aipex_Podcast_Fields::field('aipex_sponsor_about',$id,get_the_excerpt($id));
        $website=$url ?: Aipex_Podcast_Fields::field('website',$id);
        $out='<article class="aipex-card aipex-sponsor-card">';
        if($img) $out.='<a class="aipex-card-image" href="'.esc_url($website ?: get_permalink($id)).'">'.$img.'</a>';
        $out.='<div class="aipex-card-body"><h3>'.esc_html($name).'</h3><div class="aipex-card-rule"></div>';
        if($message) $out.='<p>'.esc_html($message).'</p>'; elseif($about) $out.='<p>'.esc_html(wp_trim_words(wp_strip_all_tags($about),24)).'</p>';
        if($website) $out.=Aipex_Podcast_Fields::button($website,'Visit '.$name);
        $out.='</div></article>';
        return $out;
    }

    public static function aipex_sponsor($atts=[]){
        $a=shortcode_atts(['id'=>0],$atts); $id=(int)$a['id']; if(!$id) $id=get_the_ID(); return self::sponsor_card($id);
    }

    public static function aipex_sponsors($atts=[]){
        self::enqueue();
        $post_id=get_the_ID(); $cards=[];
        $rows=Aipex_Podcast_Fields::field('sponsors',$post_id,[]);
        if(is_array($rows)){
            foreach($rows as $row){
                if(is_array($row) && isset($row['sponsor'])){ $sid=is_object($row['sponsor'])?$row['sponsor']->ID:(int)$row['sponsor']; $cards[]=self::sponsor_card($sid,$row['message']??'',$row['url']??''); }
                elseif(is_numeric($row)){ $cards[]=self::sponsor_card((int)$row); }
                elseif(is_object($row) && isset($row->ID)){ $cards[]=self::sponsor_card((int)$row->ID); }
            }
        }
        if(!$cards && get_post_type($post_id)==='aipex_podcast'){
            $series=Aipex_Podcast_Fields::ids('series',$post_id);
            foreach($series as $sid){
                $srows=Aipex_Podcast_Fields::field('aipex_series_sponsors',$sid,[]);
                if(is_array($srows)) foreach($srows as $r){ $sp=is_array($r)?($r['sponsor']??0):$r; $sp=is_object($sp)?$sp->ID:(int)$sp; if($sp) $cards[]=self::sponsor_card($sp, is_array($r)?($r['message']??''):'', is_array($r)?($r['url']??''):''); }
            }
        }
        $cards=array_filter($cards);
        return $cards?'<div class="aipex-card-grid aipex-sponsor-grid">'.implode('',$cards).'</div>':'';
    }
    public static function aipex_sponsor_grid($atts=[]){
        self::enqueue(); $a=shortcode_atts(['limit'=>12],$atts);
        $q=new WP_Query(['post_type'=>'aipex_sponsor','posts_per_page'=>(int)$a['limit'],'post_status'=>'publish','orderby'=>'title','order'=>'ASC']);
        $out='<div class="aipex-card-grid aipex-sponsor-grid">'; while($q->have_posts()){ $q->the_post(); $out.=self::sponsor_card(get_the_ID()); } wp_reset_postdata(); return $out.'</div>';
    }

    /**
     * Maps a relationship attribute value to the Aipex_Podcast_Relationships
     * target-type constant. Single place this mapping lives.
     */
    private static function relationship_target_type($relationship){
        $map = [
            'episodes' => Aipex_Podcast_Relationships::TYPE_EPISODE,
            'shows' => Aipex_Podcast_Relationships::TYPE_SHOW,
            'hosts' => Aipex_Podcast_Relationships::TYPE_HOST,
            'guests' => Aipex_Podcast_Relationships::TYPE_GUEST,
            'sponsors' => Aipex_Podcast_Relationships::TYPE_SPONSOR,
        ];
        return $map[$relationship] ?? '';
    }

    /** Single place that decides which card markup an entity type gets. */
    public static function render_entity_card($target_type, $id){
        switch ($target_type) {
            case Aipex_Podcast_Relationships::TYPE_SHOW: return self::profile_card($id, 'show');
            case Aipex_Podcast_Relationships::TYPE_HOST: return self::profile_card($id, 'presenter');
            case Aipex_Podcast_Relationships::TYPE_GUEST: return self::profile_card($id, 'guest');
            case Aipex_Podcast_Relationships::TYPE_SPONSOR: return self::sponsor_card($id);
            case Aipex_Podcast_Relationships::TYPE_EPISODE:
            default: return self::episode_card($id);
        }
    }

    /**
     * Generic relationship grid — one shortcode/widget covering every
     * entity-to-entity pairing instead of a separate shortcode per pairing.
     * Covers pairings no dedicated shortcode ever existed for (e.g. a
     * host's shows, a sponsor's guests) since those are now just another
     * "relationship" value on the same widget.
     *
     *   [aipex_relationship_grid relationship="shows"]
     *     — on a presenter page: that presenter's shows
     *   [aipex_relationship_grid relationship="guests" entity_id="42"]
     *     — guests linked to entity #42 (any entity type)
     */
    public static function aipex_relationship_grid($atts=[]){
        $a = shortcode_atts(['relationship'=>'episodes','entity_id'=>0,'entity_type'=>'','limit'=>12,'page'=>1], $atts);
        self::enqueue();
        $entity_id = (int)$a['entity_id'] ?: get_the_ID();
        $entity_type = sanitize_key($a['entity_type']) ?: Aipex_Podcast_Relationships::entity_type_for_post_type(get_post_type($entity_id));
        $target_type = self::relationship_target_type(sanitize_key($a['relationship']));
        if (!$entity_id || !$entity_type || !$target_type) return '';

        $ids = Aipex_Podcast_Relationships::related($entity_type, $entity_id, $target_type);
        if (!$ids) return '';

        $limit = max(1, (int)$a['limit']);
        $page = max(1, (int)$a['page']);
        $slice = array_slice($ids, ($page - 1) * $limit, $limit);

        $context = ['relationship'=>sanitize_key($a['relationship']),'entity_type'=>$entity_type,'entity_id'=>$entity_id,'limit'=>$limit];
        $out = '<div class="aipex-card-grid aipex-relationship-grid aipex-'.esc_attr(sanitize_key($a['relationship'])).'-grid" data-context="'.esc_attr(wp_json_encode($context)).'">';
        foreach ($slice as $id) $out .= self::render_entity_card($target_type, $id);
        $out .= '</div>';
        if (count($ids) > $page * $limit) $out .= self::load_more_button('relationship', $context, 'Load More');
        return $out;
    }

    public static function ajax_relationship_grid_load_more(){
        check_ajax_referer('aipex_podcast','nonce');
        $context = json_decode(stripslashes($_POST['context'] ?? '{}'), true);
        if (!is_array($context)) $context = [];
        $page = max(1, (int)($_POST['page'] ?? 1)) + 1;
        $relationship = sanitize_key($context['relationship'] ?? 'episodes');
        $entity_type = sanitize_key($context['entity_type'] ?? '');
        $entity_id = (int)($context['entity_id'] ?? 0);
        $limit = max(1, min(48, (int)($context['limit'] ?? 12)));
        $target_type = self::relationship_target_type($relationship);

        $html = ''; $has_more = false;
        if ($target_type && $entity_type && $entity_id) {
            $ids = Aipex_Podcast_Relationships::related($entity_type, $entity_id, $target_type);
            $slice = array_slice($ids, ($page - 1) * $limit, $limit);
            foreach ($slice as $id) $html .= self::render_entity_card($target_type, $id);
            $has_more = count($ids) > $page * $limit;
        }
        wp_send_json_success(['html'=>$html,'page'=>$page,'has_more'=>$has_more]);
    }

    public static function ajax_grid_load_more(){
        check_ajax_referer('aipex_podcast','nonce');
        $page = max(1, (int)($_POST['page'] ?? 1)) + 1;
        $context = json_decode(stripslashes($_POST['context'] ?? '{}'), true);
        if (!is_array($context)) $context = [];
        $kind = sanitize_key($context['kind'] ?? ($_POST['kind'] ?? 'episodes'));
        $limit = max(1, min(48, (int)($context['limit'] ?? 12)));
        $html = ''; $has_more = false;
        if ($kind === 'series' || $kind === 'presenters') {
            $type = $kind === 'series' ? 'aipex_series' : 'aipex_presenter';
            $label = $kind === 'series' ? 'show' : 'presenter';
            $q = new WP_Query(['post_type'=>$type,'posts_per_page'=>$limit,'paged'=>$page,'post_status'=>'publish','orderby'=>'title','order'=>'ASC']);
            while($q->have_posts()){ $q->the_post(); $html .= self::profile_card(get_the_ID(), $label); }
            $has_more = $q->max_num_pages > $page;
            wp_reset_postdata();
        } else {
            $q = Aipex_Podcast_Fields::query_episodes(['posts_per_page'=>$limit,'paged'=>$page,'series_id'=>(int)($context['series_id']??0),'presenter_id'=>(int)($context['presenter_id']??0)]);
            while($q->have_posts()){ $q->the_post(); $html .= self::episode_card(get_the_ID()); }
            $has_more = $q->max_num_pages > $page;
            wp_reset_postdata();
        }
        wp_send_json_success(['html'=>$html,'page'=>$page,'has_more'=>$has_more]);
    }

    // -------------------------------------------------------------------------
    // New Phase 3.5 shortcodes — each has a matching Elementor widget with real
    // controls; shortcodes exist so they also work in the classic editor.
    // -------------------------------------------------------------------------

    /**
     * [aipex_breadcrumbs separator="›" show_archive="1"]
     * Builds a breadcrumb trail based on the current entity type:
     *   Home → Shows → Show Name (on a series page)
     *   Home → Shows → Show Name → Episode Title (on a podcast page)
     *   Home → Presenters → Presenter Name (on a presenter page)
     *   Home → Guests / Sponsors (on those pages)
     */
    public static function aipex_breadcrumbs($atts=[]){
        $a = shortcode_atts(['separator'=>'›','show_archive'=>1], $atts);
        $sep = '<span class="aipex-crumb-sep" aria-hidden="true">'.esc_html($a['separator']).'</span>';
        $show_archive = (bool)(int)$a['show_archive'];
        $post_type = get_post_type();
        $crumbs = [];
        $crumbs[] = '<a href="'.esc_url(home_url('/')).'">Home</a>';
        switch ($post_type) {
            case 'aipex_podcast':
                if ($show_archive && ($arc = get_post_type_archive_link('aipex_podcast')))
                    $crumbs[] = '<a href="'.esc_url($arc).'">Episodes</a>';
                $series_ids = Aipex_Podcast_Relationships::shows_for(Aipex_Podcast_Relationships::TYPE_EPISODE, get_the_ID());
                if ($series_ids) $crumbs[] = '<a href="'.esc_url(get_permalink($series_ids[0])).'">'.esc_html(get_the_title($series_ids[0])).'</a>';
                $crumbs[] = '<span aria-current="page">'.esc_html(get_the_title()).'</span>';
                break;
            case 'aipex_series':
                if ($show_archive && ($arc = get_post_type_archive_link('aipex_series')))
                    $crumbs[] = '<a href="'.esc_url($arc).'">Shows</a>';
                $crumbs[] = '<span aria-current="page">'.esc_html(get_the_title()).'</span>';
                break;
            case 'aipex_presenter':
                if ($show_archive && ($arc = get_post_type_archive_link('aipex_presenter')))
                    $crumbs[] = '<a href="'.esc_url($arc).'">Presenters</a>';
                $crumbs[] = '<span aria-current="page">'.esc_html(get_the_title()).'</span>';
                break;
            case 'aipex_guest':
                if ($show_archive && ($arc = get_post_type_archive_link('aipex_guest')))
                    $crumbs[] = '<a href="'.esc_url($arc).'">Guests</a>';
                $crumbs[] = '<span aria-current="page">'.esc_html(get_the_title()).'</span>';
                break;
            case 'aipex_sponsor':
                if ($show_archive && ($arc = get_post_type_archive_link('aipex_sponsor')))
                    $crumbs[] = '<a href="'.esc_url($arc).'">Sponsors</a>';
                $crumbs[] = '<span aria-current="page">'.esc_html(get_the_title()).'</span>';
                break;
            default:
                return '';
        }
        return '<nav class="aipex-breadcrumbs" aria-label="Breadcrumb">'.implode($sep, $crumbs).'</nav>';
    }

    /**
     * [aipex_episode_meta show_date="1" show_duration="1" show_number="1" show_series="1"]
     * Compact metadata bar for the current episode.
     */
    public static function aipex_episode_meta($atts=[]){
        $a = shortcode_atts(['show_date'=>1,'show_duration'=>1,'show_number'=>1,'show_series'=>1], $atts);
        $id = get_the_ID();
        if (get_post_type($id) !== 'aipex_podcast') return '';
        $parts = [];
        if ((int)$a['show_number'] && $n = Aipex_Podcast_Fields::field('episode_number',$id))
            $parts[] = '<span class="aipex-meta-ep">Ep '.esc_html($n).'</span>';
        if ((int)$a['show_date'])
            $parts[] = '<span class="aipex-meta-date">'.esc_html(get_the_date('j F Y', $id)).'</span>';
        if ((int)$a['show_duration'] && $d = Aipex_Podcast_Fields::field('duration',$id))
            $parts[] = '<span class="aipex-meta-duration">'.esc_html($d).'</span>';
        if ((int)$a['show_series']) {
            $series_ids = Aipex_Podcast_Relationships::shows_for(Aipex_Podcast_Relationships::TYPE_EPISODE, $id);
            foreach ($series_ids as $sid)
                $parts[] = '<a class="aipex-meta-series" href="'.esc_url(get_permalink($sid)).'">'.esc_html(get_the_title($sid)).'</a>';
        }
        return $parts ? '<div class="aipex-episode-meta">'.implode('<span class="aipex-meta-sep">·</span>', $parts).'</div>' : '';
    }

    /**
     * [aipex_search placeholder="Search episodes…" limit="10"]
     * AJAX episode search. Results render below the input as episode cards.
     */
    public static function aipex_search($atts=[]){
        $a = shortcode_atts(['placeholder'=>'Search episodes…','limit'=>10], $atts);
        self::enqueue();
        $limit = max(1, min(48, (int)$a['limit']));
        return '<div class="aipex-search-wrap" data-limit="'.esc_attr($limit).'">'
            .'<input type="search" class="aipex-search-input" placeholder="'.esc_attr($a['placeholder']).'" autocomplete="off" aria-label="'.esc_attr($a['placeholder']).'">'
            .'<div class="aipex-search-results" aria-live="polite"></div>'
            .'</div>';
    }

    /**
     * [aipex_episode_filters filter_by="shows" style="buttons"]
     * Renders filter UI (buttons or dropdowns) that, when clicked, reloads
     * the nearest .aipex-episode-grid on the page via AJAX using the
     * relationship table. filter_by can be "shows", "presenters", or "both".
     */
    public static function aipex_episode_filters($atts=[]){
        $a = shortcode_atts(['filter_by'=>'shows','style'=>'buttons'], $atts);
        self::enqueue();
        $filter_by = sanitize_key($a['filter_by']);
        $style = sanitize_key($a['style']);
        $types = ($filter_by === 'both') ? ['shows','presenters'] : [$filter_by];
        $out = '<div class="aipex-filters" data-style="'.esc_attr($style).'">';
        foreach ($types as $type) {
            $post_type = $type === 'shows' ? 'aipex_series' : 'aipex_presenter';
            $entity_type = $type === 'shows' ? Aipex_Podcast_Relationships::TYPE_SHOW : Aipex_Podcast_Relationships::TYPE_HOST;
            $label = $type === 'shows' ? 'Show' : 'Presenter';
            $items = get_posts(['post_type'=>$post_type,'posts_per_page'=>-1,'post_status'=>'publish','orderby'=>'title','order'=>'ASC','fields'=>'ids']);
            if (!$items) continue;
            if ($style === 'dropdown') {
                $out .= '<select class="aipex-filter-select" data-entity-type="'.esc_attr($entity_type).'">';
                $out .= '<option value="0">All '.$label.'s</option>';
                foreach ($items as $id) $out .= '<option value="'.esc_attr($id).'">'.esc_html(get_the_title($id)).'</option>';
                $out .= '</select>';
            } else {
                $out .= '<div class="aipex-filter-buttons" data-entity-type="'.esc_attr($entity_type).'">';
                $out .= '<button class="aipex-filter-btn is-active" data-id="0">All '.$label.'s</button>';
                foreach ($items as $id) $out .= '<button class="aipex-filter-btn" data-id="'.esc_attr($id).'">'.esc_html(get_the_title($id)).'</button>';
                $out .= '</div>';
            }
        }
        return $out.'</div>';
    }

    /**
     * AJAX handler for [aipex_search]. Returns rendered episode cards matching
     * the search term, reading from both post_title and episode_summary.
     */
    public static function ajax_search(){
        check_ajax_referer('aipex_podcast','nonce');
        $term = sanitize_text_field(wp_unslash($_POST['term'] ?? ''));
        $limit = max(1, min(48, (int)($_POST['limit'] ?? 10)));
        $html = '';
        if (strlen($term) >= 2) {
            $q = new WP_Query(['post_type'=>'aipex_podcast','post_status'=>'publish','posts_per_page'=>$limit,'s'=>$term,'orderby'=>'relevance']);
            while ($q->have_posts()) { $q->the_post(); $html .= self::episode_card(get_the_ID()); }
            wp_reset_postdata();
        }
        wp_send_json_success(['html'=>$html,'found'=>$html !== '']);
    }
}
