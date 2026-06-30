<?php
if (!defined('ABSPATH')) exit;
class Aipex_Podcast_Admin {
    const TXT_REVIEW_OPTION = 'aipex_txt_content_review_items';
    const DUPLICATE_OPTION = 'aipex_podcast_duplicate_items';

    public static function menus(){
        add_submenu_page('edit.php?post_type=aipex_podcast','Podcast Dashboard','Dashboard','manage_options','aipex-podcast-dashboard',[__CLASS__,'dashboard']);
        add_submenu_page('edit.php?post_type=aipex_podcast','Shortcodes','Shortcodes','manage_options','aipex-podcast-shortcodes',[__CLASS__,'shortcodes']);
        add_submenu_page('edit.php?post_type=aipex_podcast','Dropbox Importer','Dropbox Importer','manage_options','aipex-podcast-dropbox',['Aipex_Podcast_Dropbox','page']);
        add_submenu_page('edit.php?post_type=aipex_podcast','V1 to V2 Migration','V1 to V2 Migration','manage_options','aipex-podcast-migration',['Aipex_Podcast_Migration','page']);
        add_submenu_page('edit.php?post_type=aipex_podcast','Tools & Scanners','Tools & Scanners','manage_options','aipex-podcast-tools',[__CLASS__,'tools']);
    }

    public static function handle_actions(){
        if(!current_user_can('manage_options')) return;
        if(isset($_POST['aipex_sync_dates'])){ check_admin_referer('aipex_tools'); $msg=self::sync_dates_durations(); self::notice($msg); }
        if(isset($_POST['aipex_scan_txt'])){ check_admin_referer('aipex_tools'); $msg=self::scan_txt_content(); self::notice($msg); }
        if(isset($_POST['aipex_apply_txt_review'])){ check_admin_referer('aipex_tools'); $msg=self::apply_txt_review($_POST['txt_review']??[]); self::notice($msg); }
        if(isset($_POST['aipex_replace_bad_audio'])){ check_admin_referer('aipex_tools'); $msg=self::replace_bad_audio_urls(); self::notice($msg); }
        if(isset($_POST['aipex_scan_duplicates'])){ check_admin_referer('aipex_tools'); $msg=self::scan_duplicates(); self::notice($msg); }
        if(isset($_POST['aipex_trash_duplicates'])){ check_admin_referer('aipex_tools'); $msg=self::trash_duplicates($_POST['duplicate_keep']??[], $_POST['duplicate_trash']??[]); self::notice($msg); }
        if(isset($_POST['aipex_apply_default_sponsor'])){ check_admin_referer('aipex_tools'); $msg=self::apply_default_sponsor((int)($_POST['default_sponsor_id']??Aipex_Podcast_Settings::get('default_sponsor_id')), !empty($_POST['replace_existing_sponsors'])); self::notice($msg); }
        if(isset($_POST['aipex_remove_default_sponsor'])){ check_admin_referer('aipex_tools'); $msg=self::remove_default_sponsor((int)($_POST['default_sponsor_id']??Aipex_Podcast_Settings::get('default_sponsor_id'))); self::notice($msg); }
    }
    public static function notice($msg){ set_transient('aipex_admin_notice', $msg, 60); }
    public static function show_notice(){ if($m=get_transient('aipex_admin_notice')){ echo '<div class="notice notice-success"><p>'.esc_html($m).'</p></div>'; delete_transient('aipex_admin_notice'); } }

    public static function dashboard(){ echo '<div class="wrap"><h1>Aipex Podcast System v2</h1><p>Modular podcast CMS is active.</p></div>'; }

    public static function shortcodes(){
        $items=['[aipex_podcast_player]','[aipex_floating_player limit="12"]','[aipex_podcast_grid limit="12"]','[aipex_latest_podcasts]','[aipex_series_podcasts]','[aipex_show_podcasts]','[aipex_presenter_podcasts]','[aipex_podcast_summary]','[aipex_podcast_main_points]','[aipex_podcast_transcript]','[aipex_series_grid limit="12"]','[aipex_presenter_grid limit="12"]','[aipex_guest_grid limit="12"]','[aipex_guests]','[aipex_guest id="123"]','[aipex_series_details]','[aipex_series_main_points]','[aipex_series_episode_summaries]','[aipex_presenter_about]','[aipex_presenter_box]','[aipex_presenter_links]','[aipex_subscribe]','[aipex_sponsors]','[aipex_sponsor id="123"]','[aipex_sponsor_grid]','[aipex_show_summary]','[aipex_show_main_topics]','[aipex_episode_series]','[aipex_next_previous]'];
        echo '<div class="wrap"><h1>Podcast Shortcodes</h1><table class="widefat striped"><tbody>'; foreach($items as $i) echo '<tr><td><code>'.esc_html($i).'</code></td><td><button class="button" onclick="navigator.clipboard.writeText(\''.esc_js($i).'\')">Copy</button></td></tr>'; echo '</tbody></table></div>';
    }

    public static function tools(){
        self::show_notice();
        echo '<div class="wrap"><h1>Tools & Scanners</h1>';
        echo '<form method="post">'; wp_nonce_field('aipex_tools');
        echo '<h2>Core Sync</h2><p><button class="button button-primary" name="aipex_sync_dates" value="1">Sync Published Dates & Durations</button></p>';
        echo '<h2>TXT Content Scanner</h2><p>Scans Media Library TXT files, imports transcripts, summaries, series overviews, main points and hashtags. Matches below 90% are held for review.</p><p><button class="button button-primary" name="aipex_scan_txt" value="1">Scan TXT Content</button></p>';
        echo '<h2>Bad Audio URL Replacement</h2><p>Replaces old/broken Google Storage MP3 URLs with matched Dropbox links from the latest Dropbox scan. Run <strong>Dropbox Importer → Start Batch Scan</strong> first.</p><p><button class="button button-primary" name="aipex_replace_bad_audio" value="1">Replace Bad Audio URLs With Dropbox Links</button></p>';
        echo '<h2>Duplicate Episodes</h2><p>Finds likely duplicate podcast episodes by normalised title and audio URL.</p><p><button class="button" name="aipex_scan_duplicates" value="1">Scan For Duplicates</button></p>';
        echo '<h2>Default Show Sponsor</h2><p>Set WRS or another sponsor as the default sponsor for all shows/series.</p>';
        echo '<p><label>Sponsor ID <input type="number" name="default_sponsor_id" value="'.esc_attr(Aipex_Podcast_Settings::get('default_sponsor_id')).'" min="0" style="width:120px"></label> <span class="description">Set the site default on the Settings page.</span></p>';
        echo '<p><label><input type="checkbox" name="replace_existing_sponsors" value="1"> Replace existing show sponsors instead of only filling blanks</label></p>';
        echo '<p><button class="button button-primary" name="aipex_apply_default_sponsor" value="1">Apply Default Sponsor To Shows</button> ';
        echo '<button class="button" name="aipex_remove_default_sponsor" value="1" onclick="return confirm(&quot;Remove this sponsor from all shows?&quot;)">Remove This Sponsor From Shows</button></p>';
        echo '</form>';
        self::render_txt_review();
        self::render_duplicates();
        echo '</div>';
    }

    public static function sync_dates_durations(){
        $q=new WP_Query(['post_type'=>'aipex_podcast','posts_per_page'=>-1,'post_status'=>'publish','fields'=>'ids']); $n=0;
        foreach($q->posts as $id){ Aipex_Podcast_Fields::update_field('publish_date', get_the_date('Y-m-d',$id), $id); $file=Aipex_Podcast_Fields::field('audio_file',$id); if(is_numeric($file)){ $m=wp_get_attachment_metadata((int)$file); if(!empty($m['length_formatted'])) Aipex_Podcast_Fields::update_field('duration',$m['length_formatted'],$id); } $n++; }
        return 'Podcast dates/durations synced for '.$n.' published episodes.';
    }

    public static function scan_txt_content(){
        $attachments=get_posts(['post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>-1,'post_mime_type'=>'text/plain','fields'=>'ids']);
        // Also catch TXT files with a missing/odd MIME type.
        $all=get_posts(['post_type'=>'attachment','post_status'=>'inherit','posts_per_page'=>-1,'fields'=>'ids','s'=>'.txt']);
        $attachments=array_values(array_unique(array_merge($attachments,$all)));
        $review=[]; $applied=0; $skipped=0;
        foreach($attachments as $att_id){
            $file=get_attached_file($att_id); if(!$file || !is_readable($file) || !preg_match('/\.txt$/i',$file)){ $skipped++; continue; }
            $raw=file_get_contents($file); if($raw===false || trim($raw)===''){ $skipped++; continue; }
            $name=basename($file); $type=self::detect_txt_type($name,$raw); $target=self::match_txt_target($name,$raw,$type);
            $item=['attachment_id'=>$att_id,'name'=>$name,'type'=>$type,'score'=>$target['score'],'target_id'=>$target['id'],'target_title'=>$target['title']];
            if($target['id'] && $target['score']>=90){ self::apply_txt_to_target($target['id'],$type,$raw,$name); $applied++; }
            else $review[]=$item;
        }
        update_option(self::TXT_REVIEW_OPTION,$review,false);
        return 'TXT scan complete. Applied: '.$applied.'. Needs review: '.count($review).'. Skipped: '.$skipped.'.';
    }
    public static function detect_txt_type($name,$text){
        if(stripos($text,'SERIES_OVERVIEW')!==false || stripos($text,'Series Overview')!==false || stripos($name,'series')!==false) return 'series_overview';
        if(preg_match('/summary/i',$name) || preg_match('/^\s*summary\s*:/im',$text) || stripos($text,'Main Topics Discussed')!==false) return 'episode_summary';
        return 'transcript';
    }
    public static function match_txt_target($name,$text,$type){
        $post_type = ($type==='series_overview') ? 'aipex_series' : 'aipex_podcast';
        $candidates=get_posts(['post_type'=>$post_type,'post_status'=>'any','posts_per_page'=>-1]);
        $needle=$name;
        if($type==='series_overview' && preg_match('/Generated:\s*Series Overview for\s*(.+)$/im',$text,$m)) $needle=$m[1];
        $needle=preg_replace('/^(summary|transcript|series[_\s-]*overview)\s*[:\-]?\s*/i','',$needle);
        $needle=preg_replace('/\.txt$/i','',$needle);
        $best=null; $score=0;
        foreach($candidates as $p){ $s=Aipex_Podcast_Fields::match_score($needle,$p->post_title); if($s>$score){ $score=$s; $best=$p; } }
        return ['id'=>$best?$best->ID:0,'title'=>$best?$best->post_title:'','score'=>$score];
    }
    public static function apply_txt_review($posted){
        $items=get_option(self::TXT_REVIEW_OPTION,[]); if(!is_array($items)) $items=[]; $new=[]; $applied=0;
        foreach($items as $i=>$item){
            $row=$posted[$i]??[]; if(empty($row['apply'])){ $new[]=$item; continue; }
            $target_id=(int)($row['target_id']??0); if(!$target_id){ $new[]=$item; continue; }
            $file=get_attached_file((int)$item['attachment_id']); if(!$file || !is_readable($file)){ $new[]=$item; continue; }
            self::apply_txt_to_target($target_id,$item['type'],file_get_contents($file),$item['name']); $applied++;
        }
        update_option(self::TXT_REVIEW_OPTION,array_values($new),false);
        return 'Applied '.$applied.' reviewed TXT imports.';
    }
    public static function apply_txt_to_target($post_id,$type,$raw,$name){
        $raw=self::clean_transcript_text($raw);
        if($type==='series_overview'){
            $parsed=self::parse_summary_like_text($raw, true, $name);
            Aipex_Podcast_Fields::update_field('series_overview',$parsed['summary'],$post_id);
            if($parsed['points']) Aipex_Podcast_Fields::update_field('series_main_points', array_map(fn($p)=>['point'=>$p],$parsed['points']), $post_id);
            if($parsed['episode_summaries']) Aipex_Podcast_Fields::update_field('series_episode_summaries', self::link_series_episode_summaries($parsed['episode_summaries'],$post_id), $post_id);
            return;
        }
        if($type==='episode_summary'){
            $parsed=self::parse_summary_like_text($raw, false, $name);
            Aipex_Podcast_Fields::update_field('episode_summary',$parsed['summary'],$post_id);
            if($parsed['points']) Aipex_Podcast_Fields::update_field('main_points', array_map(fn($p)=>['point'=>$p],$parsed['points']), $post_id);
            if($parsed['hashtags']) wp_set_post_terms($post_id,$parsed['hashtags'],'post_tag',true);
            return;
        }
        Aipex_Podcast_Fields::update_field('transcript',$raw,$post_id);
    }
    public static function clean_transcript_text($text){
        $text=str_replace(["\r\n","\r"],"\n",(string)$text);
        $remove=[
            '(Transcribed by TurboScribe.ai. Go Unlimited to remove this message.)',
            '(This file is longer than 30 minutes. Go Unlimited at TurboScribe.ai to transcribe files up to 10 hours long.)'
        ];
        $text=str_replace($remove,'',$text);
        $text=preg_replace('/\n{3,}/',"\n\n",$text);
        return trim($text);
    }
    public static function parse_summary_like_text($text,$series=false,$name=''){
        $text=str_replace(["\r\n","\r"],"\n",(string)$text);
        $text=preg_replace('/==+/', '', $text);
        $text=preg_replace('/^\s*Summary\s*:\s*.*?\.txt\s*$/im','',$text);
        $text=preg_replace('/^\s*Series Overview\s*$/im','',$text);
        $text=preg_replace('/^\s*SERIES_OVERVIEW\s*$/im','',$text);
        $text=preg_replace('/^\s*Generated\s*:\s*Series Overview for .*$/im','',$text);
        $points=[]; $hashtags=[]; $episode_summaries=[];
        $sections=[
            'points'=>'/(MAIN TOPICS COVERED IN SERIES:|Main Topics Covered In Series|Main Topics Discussed|Main Points Covered)\s*:?/i',
            'seo'=>'/(SEO KEYWORDS FOR SERIES:|SEO Keywords|SEO Keywords For Series)\s*:?/i',
            'hashtags'=>'/(HASHTAGS FOR SERIES:|Hashtags|Hashtags For Series)\s*:?/i',
            'file'=>'/(File Information|File Info)\s*:?/i',
            'episodes'=>'/(Episode Summaries|Episode Summaries In Series|EPISODE SUMMARIES)\s*:?/i',
        ];
        // Generic section stripper/extractor line-based.
        $lines=explode("\n",$text); $keep=[]; $mode='summary';
        foreach($lines as $line){
            $trim=trim($line);
            if($trim==='') { if($mode==='summary') $keep[]=$line; continue; }
            $newmode=null; foreach($sections as $m=>$rx){ if(preg_match($rx,$trim)){ $newmode=$m; break; } }
            if($newmode){ $mode=$newmode; continue; }
            if($mode==='points'){
                if(preg_match('/^\s*[-*•–—\d\.\)]+\s*(.+)$/u',$line,$m)) $points[]=trim($m[1]);
                elseif(strlen($trim)>3) $points[]=trim($trim);
                continue;
            }
            if($mode==='hashtags'){
                preg_match_all('/#([\pL\pN_\-]+)/u',$line,$m); foreach($m[1]??[] as $tag) $hashtags[]=trim(str_replace(['_','-'],' ',$tag));
                continue;
            }
            if($mode==='episodes'){
                if(preg_match('/^\s*[-*•–—]?\s*(.+?)(?:\.txt)?\s*[:\-–—]\s*(.+)$/u',$line,$m)) $episode_summaries[]=['episode_name'=>trim($m[1]),'summary'=>trim($m[2])];
                elseif(preg_match('/^\s*[-*•–—]\s*(.+)$/u',$line,$m)) $episode_summaries[]=['episode_name'=>preg_replace('/\.txt$/i','',trim($m[1])),'summary'=>''];
                continue;
            }
            if(in_array($mode,['seo','file'],true)) continue;
            $keep[]=$line;
        }
        $summary=trim(preg_replace('/\n{3,}/',"\n\n",implode("\n",$keep)));
        $points=array_values(array_unique(array_filter(array_map('trim',$points))));
        $hashtags=array_values(array_unique(array_filter(array_map('trim',$hashtags))));
        return ['summary'=>$summary,'points'=>$points,'hashtags'=>$hashtags,'episode_summaries'=>$episode_summaries];
    }
    public static function link_series_episode_summaries($rows,$series_id){
        $out=[];
        foreach($rows as $r){
            $name=preg_replace('/\.txt$/i','',trim($r['episode_name']??'')); if(!$name) continue;
            $episode_id=0; $q=Aipex_Podcast_Fields::query_episodes(['series_id'=>$series_id,'post_status'=>'any','posts_per_page'=>-1]);
            foreach($q->posts as $ep){ if(Aipex_Podcast_Fields::match_score($name,$ep->post_title)>=88){ $episode_id=$ep->ID; break; } }
            $out[]=['episode'=>$episode_id,'episode_name'=>$name,'summary'=>$r['summary']??''];
        }
        return $out;
    }

    public static function replace_bad_audio_urls(){
        $index=get_option('aipex_dropbox_mp3_index',[]); if(!is_array($index)||!$index) return 'No Dropbox MP3 index found. Run Podcasts → Dropbox Importer → Start Batch Scan first.';
        $q=new WP_Query(['post_type'=>'aipex_podcast','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids']); $fixed=0; $review=0;
        foreach($q->posts as $id){
            $url=Aipex_Podcast_Fields::audio_url($id); $drop=Aipex_Podcast_Fields::field('dropbox_url',$id);
            if($drop && stripos($drop,'wrs-audio.storage.googleapis.com')===false) continue;
            if(!$url || stripos($url,'wrs-audio.storage.googleapis.com')===false) continue;
            $base=basename(parse_url($url,PHP_URL_PATH)); $best=null; $score=0;
            foreach($index as $item){ $s=max(Aipex_Podcast_Fields::match_score($base,$item['name']??''), Aipex_Podcast_Fields::match_score(get_the_title($id),$item['name']??'')); if($s>$score){$score=$s;$best=$item;} }
            if($best && $score>=90){ $target=$best['url']??''; if(!$target && !empty($best['path']) && class_exists('Aipex_Podcast_Dropbox')){ $r=Aipex_Podcast_Dropbox::shared_url_result($best['path']); $target=$r['url']??''; }
                if($target){ Aipex_Podcast_Dropbox::attach_dropbox_url($id,$target); $fixed++; } else $review++;
            } else $review++;
        }
        return 'Bad audio URL replacement complete. Fixed: '.$fixed.'. Still needing review: '.$review.'.';
    }

    public static function scan_duplicates(){
        $eps=get_posts(['post_type'=>'aipex_podcast','post_status'=>'any','posts_per_page'=>-1]); $groups=[];
        foreach($eps as $ep){
            $title_key=Aipex_Podcast_Fields::normalize($ep->post_title);
            $audio=Aipex_Podcast_Fields::audio_url($ep->ID); $audio_key=$audio ? md5(strtolower(basename(parse_url($audio,PHP_URL_PATH) ?: $audio))) : '';
            $key=$audio_key ?: $title_key; if(!$key) continue;
            $groups[$key][]=['id'=>$ep->ID,'title'=>$ep->post_title,'date'=>$ep->post_date,'status'=>$ep->post_status,'audio'=>$audio];
        }
        $dupes=array_values(array_filter($groups,fn($g)=>count($g)>1)); update_option(self::DUPLICATE_OPTION,$dupes,false);
        return 'Duplicate scan complete. Found '.count($dupes).' duplicate groups.';
    }
    public static function trash_duplicates($keep,$trash){
        $trashed=0; foreach((array)$trash as $id){ $id=(int)$id; if($id && !in_array($id,array_map('intval',(array)$keep),true)){ wp_trash_post($id); $trashed++; } }
        self::scan_duplicates(); return 'Moved '.$trashed.' duplicate episodes to Trash.';
    }
    public static function normalise_relationship_ids($value){
        if (empty($value)) return [];
        if (is_numeric($value)) return [(int)$value];
        if (is_object($value) && isset($value->ID)) return [(int)$value->ID];
        if (is_array($value)) {
            $ids=[];
            foreach($value as $v){
                if (is_numeric($v)) $ids[]=(int)$v;
                elseif (is_object($v) && isset($v->ID)) $ids[]=(int)$v->ID;
                elseif (is_array($v) && isset($v['ID'])) $ids[]=(int)$v['ID'];
                elseif (is_array($v) && isset($v['id'])) $ids[]=(int)$v['id'];
            }
            return array_values(array_unique(array_filter($ids)));
        }
        return [];
    }
    public static function get_show_sponsors($series_id){
        $value=Aipex_Podcast_Fields::field('series_sponsors',$series_id);
        if (empty($value)) $value=Aipex_Podcast_Fields::field('sponsors',$series_id);
        return self::normalise_relationship_ids($value);
    }
    public static function set_show_sponsors($series_id,$ids){
        $ids=array_values(array_unique(array_map('intval',(array)$ids)));
        Aipex_Podcast_Fields::update_field('series_sponsors',$ids,$series_id);
        // Also mirror to sponsors for compatibility with any older V2 builds/shortcodes.
        Aipex_Podcast_Fields::update_field('sponsors',$ids,$series_id);
    }
    public static function apply_default_sponsor($sponsor_id=0,$replace=false){
        if(!$sponsor_id) $sponsor_id=(int)Aipex_Podcast_Settings::get('default_sponsor_id');
        if(!$sponsor_id || get_post_type($sponsor_id)!=='aipex_sponsor') return 'Sponsor ID '.$sponsor_id.' was not found as a sponsor.';
        $series=get_posts(['post_type'=>'aipex_series','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids']);
        $updated=0; $skipped=0;
        foreach($series as $series_id){
            $current=self::get_show_sponsors($series_id);
            if($replace){
                self::set_show_sponsors($series_id,[$sponsor_id]);
                $updated++;
                continue;
            }
            if(empty($current)){
                self::set_show_sponsors($series_id,[$sponsor_id]);
                $updated++;
            } else {
                $skipped++;
            }
        }
        return 'Default sponsor applied. Updated '.$updated.' shows. Skipped '.$skipped.' shows with existing sponsors.';
    }
    public static function remove_default_sponsor($sponsor_id=0){
        if(!$sponsor_id) $sponsor_id=(int)Aipex_Podcast_Settings::get('default_sponsor_id');
        $series=get_posts(['post_type'=>'aipex_series','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids']);
        $updated=0;
        foreach($series as $series_id){
            $current=self::get_show_sponsors($series_id);
            $new=array_values(array_diff($current,[(int)$sponsor_id]));
            if(count($new)!==count($current)){
                self::set_show_sponsors($series_id,$new);
                $updated++;
            }
        }
        return 'Removed sponsor '.$sponsor_id.' from '.$updated.' shows.';
    }
    public static function render_duplicates(){
        $groups=get_option(self::DUPLICATE_OPTION,[]); if(!$groups) return;
        echo '<hr><h2>Duplicate Episode Review</h2><form method="post">'; wp_nonce_field('aipex_tools');
        foreach($groups as $gi=>$group){ echo '<h3>Duplicate group '.((int)$gi+1).'</h3><table class="widefat striped"><thead><tr><th>Keep</th><th>Trash</th><th>Episode</th><th>Status</th><th>Date</th><th>Audio</th></tr></thead><tbody>'; foreach($group as $row){ $id=(int)$row['id']; echo '<tr><td><input type="radio" name="duplicate_keep['.(int)$gi.']" value="'.$id.'"></td><td><input type="checkbox" name="duplicate_trash[]" value="'.$id.'"></td><td><a href="'.esc_url(get_edit_post_link($id)).'">'.esc_html($row['title']).'</a></td><td>'.esc_html($row['status']).'</td><td>'.esc_html($row['date']).'</td><td><code>'.esc_html($row['audio']).'</code></td></tr>'; } echo '</tbody></table>'; }
        echo '<p><button class="button button-primary" name="aipex_trash_duplicates" value="1" onclick="return confirm(\'Move selected duplicate episodes to Trash?\')">Move Selected Duplicates To Trash</button></p></form>';
    }
    public static function render_txt_review(){
        $items=get_option(self::TXT_REVIEW_OPTION,[]); if(!$items) return;
        echo '<hr><h2>TXT Content Needs Review</h2><form method="post">'; wp_nonce_field('aipex_tools');
        echo '<table class="widefat striped"><thead><tr><th>Apply</th><th>TXT File</th><th>Type</th><th>Confidence</th><th>Suggested</th><th>Target</th></tr></thead><tbody>';
        foreach($items as $i=>$item){ $type=$item['type']==='series_overview'?'aipex_series':'aipex_podcast'; $posts=get_posts(['post_type'=>$type,'post_status'=>'any','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']); echo '<tr><td><input type="checkbox" name="txt_review['.(int)$i.'][apply]" value="1"></td><td>'.esc_html($item['name']).'</td><td>'.esc_html($item['type']).'</td><td>'.esc_html($item['score']).'%</td><td>'.esc_html($item['target_title']).'</td><td><select name="txt_review['.(int)$i.'][target_id]"><option value="0">— Select —</option>'; foreach($posts as $p) echo '<option value="'.(int)$p->ID.'" '.selected((int)$item['target_id'],(int)$p->ID,false).'>'.esc_html($p->post_title).'</option>'; echo '</select></td></tr>'; }
        echo '</tbody></table><p><button class="button button-primary" name="aipex_apply_txt_review" value="1">Apply Selected TXT Imports</button></p></form>';
    }
}
