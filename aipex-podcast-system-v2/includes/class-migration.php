<?php
if (!defined('ABSPATH')) exit;

class Aipex_Podcast_Migration {
    public static function page(){
        if(!current_user_can('manage_options')) return;
        $analysis=self::analyse();
        echo '<div class="wrap"><h1>V1 → V2 Migration Wizard</h1>';
        echo '<p>This safely copies legacy podcast data into the V2 fields. It does not delete V1 data.</p>';
        echo '<h2>Detected data</h2><table class="widefat striped" style="max-width:800px"><tbody>';
        foreach($analysis as $label=>$count) echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html($count).'</td></tr>';
        echo '</tbody></table>';
        echo '<form method="post" style="margin-top:20px">'; wp_nonce_field('aipex_migrate_v1');
        echo '<p><label><input type="checkbox" name="dry_run" value="1" checked> Analyse only / dry run</label></p>';
        echo '<p><button class="button button-primary" name="aipex_run_migration" value="1">Run Migration</button></p>';
        echo '</form>';
        if($r=get_transient('aipex_migration_report')){ echo '<h2>Migration report</h2><pre style="background:#fff;padding:12px;max-height:600px;overflow:auto;white-space:pre-wrap">'.esc_html($r).'</pre>'; delete_transient('aipex_migration_report'); }
        echo '</div>';
    }

    public static function handle_actions(){
        if(!current_user_can('manage_options') || empty($_POST['aipex_run_migration'])) return;
        check_admin_referer('aipex_migrate_v1');
        $dry=!empty($_POST['dry_run']);
        $report=self::run($dry);
        set_transient('aipex_migration_report',$report,120);
        wp_safe_redirect(admin_url('edit.php?post_type=aipex_podcast&page=aipex-podcast-migration')); exit;
    }

    public static function analyse(){
        return [
            'V2 Episodes already present (aipex_podcast)' => self::count_type('aipex_podcast'),
            'Legacy OVA Audio episodes (ova_audio)' => self::count_type('ova_audio'),
            'V2 Presenters already present (aipex_presenter)' => self::count_type('aipex_presenter'),
            'Legacy OVA Hosts (ova_audio_host)' => self::count_type('ova_audio_host'),
            'V2 Series already present (aipex_series)' => self::count_type('aipex_series'),
        ];
    }

    public static function count_type($type){
        $obj=get_post_type_object($type); if(!$obj && !post_type_exists($type)) return 0;
        $counts=wp_count_posts($type); if(!$counts) return 0;
        return array_sum(array_map('intval',(array)$counts));
    }

    public static function run($dry=true){
        $log=[]; $log[]=$dry?'DRY RUN - no data changed':'LIVE MIGRATION - data copied/updated';
        $host_map=self::migrate_hosts($dry,$log);
        self::migrate_ova_episodes($dry,$log,$host_map);
        self::normalise_v2_existing($dry,$log);
        $log[]='Done.';
        return implode("\n",$log);
    }

    public static function migrate_hosts($dry,&$log){
        $map=[]; $hosts=get_posts(['post_type'=>'ova_audio_host','posts_per_page'=>-1,'post_status'=>'any']);
        foreach($hosts as $h){
            $existing=self::find_by_title('aipex_presenter',$h->post_title);
            if($dry){ $log[]='HOST: '.$h->post_title.' → '.($existing?'existing presenter #'.$existing:'new presenter'); $map[$h->ID]=$existing; continue; }
            $pid=$existing ?: wp_insert_post(['post_type'=>'aipex_presenter','post_status'=>$h->post_status==='trash'?'draft':'publish','post_title'=>$h->post_title,'post_name'=>$h->post_name,'post_content'=>$h->post_content,'post_excerpt'=>$h->post_excerpt,'post_date'=>$h->post_date]);
            if(!is_wp_error($pid) && $pid){
                set_post_thumbnail($pid, get_post_thumbnail_id($h->ID));
                self::copy_first_meta($h->ID,$pid,['ovau_host_job','ovau_host_about','about','presenter_about'],'presenter_about');
                foreach(['website','facebook','instagram','linkedin','soundcloud','contact_email','contact_number'] as $field) self::copy_first_meta($h->ID,$pid,['ovau_host_'.$field,$field],$field);
                $map[$h->ID]=$pid; $log[]='HOST MIGRATED: '.$h->post_title.' → presenter #'.$pid;
            }
        }
        return $map;
    }

    public static function migrate_ova_episodes($dry,&$log,$host_map){
        $episodes=get_posts(['post_type'=>'ova_audio','posts_per_page'=>-1,'post_status'=>'any']);
        foreach($episodes as $e){
            $existing=self::find_by_title('aipex_podcast',$e->post_title);
            if($dry){ $log[]='EPISODE: '.$e->post_title.' → '.($existing?'existing episode #'.$existing:'new episode'); continue; }
            $pid=$existing ?: wp_insert_post(['post_type'=>'aipex_podcast','post_status'=>$e->post_status,'post_title'=>$e->post_title,'post_name'=>$e->post_name,'post_content'=>$e->post_content,'post_excerpt'=>$e->post_excerpt,'post_date'=>$e->post_date]);
            if(is_wp_error($pid) || !$pid){ $log[]='FAILED EPISODE: '.$e->post_title; continue; }
            set_post_thumbnail($pid, get_post_thumbnail_id($e->ID));
            wp_set_post_terms($pid, wp_get_post_terms($e->ID,'post_tag',['fields'=>'ids']), 'post_tag', false);
            self::copy_first_meta($e->ID,$pid,['ovau_audio_url','audio_file'],'audio_file');
            self::copy_first_meta($e->ID,$pid,['ovau_audio_url','dropbox_url'],'dropbox_url');
            self::copy_first_meta($e->ID,$pid,['ovau_soundcloud_url','soundcloud_url'],'soundcloud_url');
            self::copy_first_meta($e->ID,$pid,['ovau_episode_summary','episode_summary'],'episode_summary');
            self::copy_first_meta($e->ID,$pid,['ovau_transcript','transcript'],'transcript');
            self::copy_first_meta($e->ID,$pid,['ovau_duration','duration'],'duration');
            self::copy_first_meta($e->ID,$pid,['ovau_episode_number','episode_number'],'episode_number');
            Aipex_Podcast_Fields::update_field('publish_date', date('Y-m-d', strtotime($e->post_date)), $pid);
            $host_ids=(array)get_post_meta($e->ID,'ovau_host_id',true);
            $presenters=[]; foreach($host_ids as $hid){ if(isset($host_map[$hid]) && $host_map[$hid]) $presenters[]=$host_map[$hid]; }
            if($presenters) Aipex_Podcast_Fields::update_field('presenters', array_values(array_unique($presenters)), $pid);
            $log[]='EPISODE MIGRATED: '.$e->post_title.' → #'.$pid;
        }
    }

    public static function normalise_v2_existing($dry,&$log){
        $episodes=get_posts(['post_type'=>'aipex_podcast','posts_per_page'=>-1,'post_status'=>'any','fields'=>'ids']);
        $episode_fields=['audio_file','dropbox_url','soundcloud_url','episode_summary','main_points','transcript','series','presenters','guests','sponsors','duration','episode_number'];
        foreach($episodes as $id){
            $changes=[];
            foreach($episode_fields as $field){
                $current=get_post_meta($id,$field,true);
                $alias_value=Aipex_Podcast_Fields::field($field,$id,null);
                if(($current==='' || $current===[] || $current===null) && $alias_value!==null && $alias_value!=='' && $alias_value!==[]){
                    $changes[]=$field.' copied';
                    if(!$dry) Aipex_Podcast_Fields::update_field($field,$alias_value,$id);
                }
            }
            $src=Aipex_Podcast_Fields::field('audio_source_type',$id);
            if(!$src){
                if(Aipex_Podcast_Fields::field('dropbox_url',$id)) $src='dropbox';
                elseif(Aipex_Podcast_Fields::field('soundcloud_url',$id)) $src='soundcloud';
                elseif(Aipex_Podcast_Fields::field('audio_file',$id)) $src='upload';
                if($src){ $changes[]='audio_source_type='.$src; if(!$dry) Aipex_Podcast_Fields::update_field('audio_source_type',$src,$id); }
            }
            if(!Aipex_Podcast_Fields::field('publish_date',$id)){ $date=get_the_date('Y-m-d',$id); $changes[]='publish_date='.$date; if(!$dry) Aipex_Podcast_Fields::update_field('publish_date',$date,$id); }
            if($changes) $log[]='NORMALISED EPISODE #'.$id.' '.get_the_title($id).': '.implode(', ',$changes);
        }
        $series=get_posts(['post_type'=>'aipex_series','posts_per_page'=>-1,'post_status'=>'any','fields'=>'ids']);
        foreach($series as $id){
            $changes=[];
            foreach(['series_overview','series_main_points','series_episode_summaries','rss_url','spotify_url','apple_url','youtube_url','amazon_url','pocketcasts_url','website'] as $field){
                $current=get_post_meta($id,$field,true); $alias_value=Aipex_Podcast_Fields::field($field,$id,null);
                if(($current==='' || $current===[] || $current===null) && $alias_value!==null && $alias_value!=='' && $alias_value!==[]){ $changes[]=$field.' copied'; if(!$dry) Aipex_Podcast_Fields::update_field($field,$alias_value,$id); }
            }
            if($changes) $log[]='NORMALISED SERIES #'.$id.' '.get_the_title($id).': '.implode(', ',$changes);
        }
        $presenters=get_posts(['post_type'=>'aipex_presenter','posts_per_page'=>-1,'post_status'=>'any','fields'=>'ids']);
        foreach($presenters as $id){
            $changes=[];
            foreach(['presenter_about','website','facebook','instagram','linkedin','soundcloud','contact_email','contact_number','rss_url','spotify_url','apple_url','youtube_url','amazon_url','pocketcasts_url'] as $field){
                $current=get_post_meta($id,$field,true); $alias_value=Aipex_Podcast_Fields::field($field,$id,null);
                if(($current==='' || $current===[] || $current===null) && $alias_value!==null && $alias_value!=='' && $alias_value!==[]){ $changes[]=$field.' copied'; if(!$dry) Aipex_Podcast_Fields::update_field($field,$alias_value,$id); }
            }
            if($changes) $log[]='NORMALISED PRESENTER #'.$id.' '.get_the_title($id).': '.implode(', ',$changes);
        }
    }

    public static function find_by_title($type,$title){
        $posts=get_posts(['post_type'=>$type,'title'=>$title,'posts_per_page'=>1,'post_status'=>'any','fields'=>'ids']);
        return $posts ? (int)$posts[0] : 0;
    }

    public static function copy_first_meta($from,$to,$keys,$dest){
        foreach($keys as $key){
            $v=get_post_meta($from,$key,true);
            if($v!=='' && $v!==null && $v!==[]){ Aipex_Podcast_Fields::update_field($dest,$v,$to); return true; }
        }
        return false;
    }
}
