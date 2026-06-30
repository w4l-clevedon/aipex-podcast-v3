<?php
if (!defined('ABSPATH')) exit;
class Aipex_Podcast_Admin {
    const TXT_REVIEW_OPTION = 'aipex_txt_content_review_items';
    const DUPLICATE_OPTION = 'aipex_podcast_duplicate_items';

    public static function menus(){
        add_submenu_page('edit.php?post_type=aipex_podcast','Podcast Dashboard','Dashboard','manage_options','aipex-podcast-dashboard',[__CLASS__,'dashboard']);
        add_submenu_page('edit.php?post_type=aipex_podcast','Shortcodes','Shortcodes','manage_options','aipex-podcast-shortcodes',[__CLASS__,'shortcodes']);
        add_submenu_page('edit.php?post_type=aipex_podcast','Dropbox Importer','Dropbox Importer','manage_options','aipex-podcast-dropbox',['Aipex_Podcast_Dropbox','page']);
        add_submenu_page('edit.php?post_type=aipex_podcast','Tools & Scanners','Tools & Scanners','manage_options','aipex-podcast-tools',[__CLASS__,'tools']);
    }

    public static function handle_actions(){
        if(!current_user_can('manage_options')) return;
        if(isset($_POST['aipex_sync_dates'])){ check_admin_referer('aipex_tools'); $msg=self::sync_dates_durations(); self::notice($msg); }
        if(isset($_POST['aipex_scan_txt'])){ check_admin_referer('aipex_tools'); $msg=self::scan_txt_content(); self::notice($msg); }
        if(isset($_POST['aipex_apply_txt_review'])){ check_admin_referer('aipex_tools'); $msg=self::apply_txt_review($_POST['txt_review']??[]); self::notice($msg); }
        if(isset($_POST['aipex_scan_duplicates'])){ check_admin_referer('aipex_tools'); $msg=self::scan_duplicates(); self::notice($msg); }
        if(isset($_POST['aipex_trash_duplicates'])){ check_admin_referer('aipex_tools'); $msg=self::trash_duplicates($_POST['duplicate_keep']??[], $_POST['duplicate_trash']??[]); self::notice($msg); }
        if(isset($_POST['aipex_apply_title_match_review'])){ check_admin_referer('aipex_tools'); $msg=self::apply_title_match_review($_POST['title_match_review']??[]); self::notice($msg); }
        if(isset($_POST['aipex_clear_title_match'])){ check_admin_referer('aipex_tools'); delete_option('aipex_title_match_review'); self::notice('Title match review list cleared.'); }
        if(isset($_POST['aipex_apply_default_sponsor'])){ check_admin_referer('aipex_tools'); $msg=self::apply_default_sponsor((int)($_POST['default_sponsor_id']??Aipex_Podcast_Settings::get('default_sponsor_id')), !empty($_POST['replace_existing_sponsors'])); self::notice($msg); }
        if(isset($_POST['aipex_remove_default_sponsor'])){ check_admin_referer('aipex_tools'); $msg=self::remove_default_sponsor((int)($_POST['default_sponsor_id']??Aipex_Podcast_Settings::get('default_sponsor_id'))); self::notice($msg); }
    }
    public static function notice($msg){ set_transient('aipex_admin_notice', $msg, 60); }
    public static function show_notice(){ if($m=get_transient('aipex_admin_notice')){ echo '<div class="notice notice-success"><p>'.esc_html($m).'</p></div>'; delete_transient('aipex_admin_notice'); } }

    public static function dashboard(){
        self::show_notice();
        $entity_counts = [
            'Podcasts'          => self::published_count('aipex_podcast'),
            'Shows'             => self::published_count('aipex_series'),
            'Hosts / Presenters'=> self::published_count('aipex_presenter'),
            'Guests'            => self::published_count('aipex_guest'),
            'Sponsors'          => self::published_count('aipex_sponsor'),
        ];
        $play_stats  = Aipex_Podcast_Analytics::get_summary_stats();
        $countries   = Aipex_Podcast_Analytics::get_country_data(50);
        $browsers    = Aipex_Podcast_Analytics::get_browser_data();
        $devices     = Aipex_Podcast_Analytics::get_device_data();
        $top_eps     = Aipex_Podcast_Analytics::get_top_episodes(10);
        $monthly     = Aipex_Podcast_Analytics::get_plays_by_month(12);
        $total_plays = max(1, $play_stats['total']);

        $card_style = 'background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:18px 24px;min-width:140px;flex:1 1 140px';
        $section_style = 'background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:20px 24px;margin-top:16px';

        echo '<div class="wrap"><h1>Aipex Podcast System</h1>';

        // ── Entity counts ──
        echo '<h2 style="margin-top:20px">Content</h2>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:12px">';
        foreach ($entity_counts as $label => $count) {
            echo '<div style="'.$card_style.'">';
            echo '<div style="font-size:30px;font-weight:700;line-height:1;color:var(--aipex-brand,#e4005a)">'.esc_html(number_format($count)).'</div>';
            echo '<div style="color:#646970;margin-top:6px;font-size:13px">'.esc_html($label).'</div>';
            echo '</div>';
        }
        echo '</div>';

        // ── Play stats ──
        echo '<h2 style="margin-top:24px">Listener Stats</h2>';
        if ($play_stats['total'] === 0) {
            echo '<p style="color:#646970">No play data recorded yet. Stats appear here once listeners start playing episodes on the site.</p>';
        } else {
            echo '<div style="display:flex;flex-wrap:wrap;gap:12px">';
            $play_cards = [
                'Total Plays'      => number_format($play_stats['total']),
                'This Month'       => number_format($play_stats['this_month']),
                'This Week'        => number_format($play_stats['this_week']),
                'Countries Reached'=> number_format($play_stats['countries']),
            ];
            foreach ($play_cards as $label => $val) {
                echo '<div style="'.$card_style.'">';
                echo '<div style="font-size:30px;font-weight:700;line-height:1;color:var(--aipex-brand,#e4005a)">'.esc_html($val).'</div>';
                echo '<div style="color:#646970;margin-top:6px;font-size:13px">'.esc_html($label).'</div>';
                echo '</div>';
            }
            echo '</div>';

            // ── World map ──
            echo '<div style="'.$section_style.'">';
            echo '<h3 style="margin-top:0">Listener Locations</h3>';
            echo '<div id="aipex-geo-map" style="width:100%;height:420px"></div>';
            echo '<p style="color:#646970;font-size:12px;margin-bottom:0">IP addresses are anonymised before storage. Country-level data only.</p>';
            echo '</div>';

            // ── Browser + Device charts ──
            echo '<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:16px">';

            // Browser
            echo '<div style="'.$section_style.';flex:1 1 280px">';
            echo '<h3 style="margin-top:0">Browser</h3>';
            echo '<canvas id="aipex-browser-chart" style="max-height:220px"></canvas>';
            echo '<table style="width:100%;margin-top:12px;font-size:13px;border-collapse:collapse">';
            $browser_total = max(1, array_sum(array_column($browsers,'plays')));
            foreach ($browsers as $row) {
                $pct = round(100*$row['plays']/$browser_total);
                echo '<tr><td>'.esc_html($row['browser']).'</td><td style="text-align:right;color:#646970">'.esc_html(number_format($row['plays'])).' ('.esc_html($pct).'%)</td></tr>';
            }
            echo '</table></div>';

            // Device
            echo '<div style="'.$section_style.';flex:1 1 280px">';
            echo '<h3 style="margin-top:0">Device</h3>';
            echo '<canvas id="aipex-device-chart" style="max-height:220px"></canvas>';
            echo '<table style="width:100%;margin-top:12px;font-size:13px;border-collapse:collapse">';
            $device_total = max(1, array_sum(array_column($devices,'plays')));
            foreach ($devices as $row) {
                $pct = round(100*$row['plays']/$device_total);
                echo '<tr><td>'.esc_html($row['device_type']).'</td><td style="text-align:right;color:#646970">'.esc_html(number_format($row['plays'])).' ('.esc_html($pct).'%)</td></tr>';
            }
            echo '</table></div>';

            // Top countries
            echo '<div style="'.$section_style.';flex:2 1 320px">';
            echo '<h3 style="margin-top:0">Top Countries</h3>';
            echo '<table style="width:100%;font-size:13px;border-collapse:collapse">';
            echo '<thead><tr><th style="text-align:left;padding-bottom:8px">Country</th><th style="text-align:right;padding-bottom:8px">Plays</th><th style="text-align:right;padding-bottom:8px">%</th></tr></thead><tbody>';
            $top20 = array_slice($countries, 0, 20);
            foreach ($top20 as $row) {
                $pct = round(100 * $row['plays'] / $total_plays, 1);
                echo '<tr>';
                echo '<td style="padding:3px 0">'.esc_html($row['country_name'] ?: $row['country_code']).'</td>';
                echo '<td style="text-align:right;color:#646970">'.esc_html(number_format($row['plays'])).'</td>';
                echo '<td style="text-align:right;width:60px">';
                echo '<div style="background:#f0f0f1;border-radius:4px;overflow:hidden;height:14px;width:60px;display:inline-block;vertical-align:middle">';
                echo '<div style="background:var(--aipex-brand,#e4005a);height:14px;width:'.esc_attr(min(100,$pct)).'%"></div>';
                echo '</div> <span style="color:#646970">'.esc_html($pct).'%</span></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '</div>'; // flex row

            // ── Top Episodes ──
            if ($top_eps) {
                echo '<div style="'.$section_style.'">';
                echo '<h3 style="margin-top:0">Most Played Episodes</h3>';
                echo '<table class="widefat striped" style="font-size:13px"><thead><tr><th>Episode</th><th style="width:100px;text-align:right">Plays</th></tr></thead><tbody>';
                foreach ($top_eps as $row) {
                    $ep_id = (int)$row['episode_id'];
                    echo '<tr><td><a href="'.esc_url(get_edit_post_link($ep_id)).'">'.esc_html(get_the_title($ep_id)).'</a></td>';
                    echo '<td style="text-align:right">'.esc_html(number_format($row['plays'])).'</td></tr>';
                }
                echo '</tbody></table></div>';
            }

            // ── Chart.js + Google Charts scripts ──
            $brand = sanitize_hex_color(Aipex_Podcast_Settings::get('brand_color')) ?: '#e4005a';
            $palette = ["'$brand'","'#f9a8d4'","'#fdba74'","'#86efac'","'#93c5fd'","'#c4b5fd'","'#fde68a'"];
            $browser_labels = wp_json_encode(array_column($browsers,'browser'));
            $browser_data   = wp_json_encode(array_map('intval', array_column($browsers,'plays')));
            $device_labels  = wp_json_encode(array_column($devices,'device_type'));
            $device_data    = wp_json_encode(array_map('intval', array_column($devices,'plays')));
            $map_rows = [];
            foreach ($countries as $c) $map_rows[] = [esc_js($c['country_name']), (int)$c['plays']];
            $map_json = wp_json_encode($map_rows);
            ?>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
            <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
            <script>
            (function(){
                // Chart.js — browser
                var bCtx=document.getElementById('aipex-browser-chart');
                if(bCtx) new Chart(bCtx,{type:'doughnut',data:{labels:<?php echo $browser_labels;?>,datasets:[{data:<?php echo $browser_data;?>,backgroundColor:[<?php echo implode(',',$palette);?>],borderWidth:2}]},options:{plugins:{legend:{position:'bottom'}},responsive:true,maintainAspectRatio:true}});

                // Chart.js — device
                var dCtx=document.getElementById('aipex-device-chart');
                if(dCtx) new Chart(dCtx,{type:'doughnut',data:{labels:<?php echo $device_labels;?>,datasets:[{data:<?php echo $device_data;?>,backgroundColor:[<?php echo implode(',',$palette);?>],borderWidth:2}]},options:{plugins:{legend:{position:'bottom'}},responsive:true,maintainAspectRatio:true}});

                // Google Charts GeoChart — world map
                google.charts.load('current',{packages:['geochart']});
                google.charts.setOnLoadCallback(function(){
                    var mapData=[['Country','Plays']].concat(<?php echo $map_json;?>);
                    var data=google.visualization.arrayToDataTable(mapData);
                    var chart=new google.visualization.GeoChart(document.getElementById('aipex-geo-map'));
                    chart.draw(data,{colorAxis:{colors:['#fce7f3','<?php echo esc_js($brand);?>']},backgroundColor:'#f6f7f7',datalessRegionColor:'#e5e7eb',tooltip:{isHtml:true}});
                });
            })();
            </script>
            <?php
        }

        echo '<p style="margin-top:24px;color:#9ca3af;font-size:12px">Aipex Podcast System '.esc_html(AIPEX_PODCAST_VERSION).' · Play data collected from audio elements on the front end · IP addresses anonymised, never stored in full.</p>';
        echo '</div>';
    }

    public static function shortcodes(){
        $groups = [
            'Recommended' => [
                ['[aipex_relationship_grid relationship="episodes"]', 'One shortcode for every entity-to-entity grid. relationship can be episodes, shows, hosts, guests or sponsors; entity_id defaults to the current page. Covers pairings the dedicated shortcodes below don\'t, e.g. relationship="shows" on a host page, or relationship="guests" on a sponsor page.'],
            ],
            'Episode' => [
                ['[aipex_podcast_player]', 'Audio player for the current episode.'],
                ['[aipex_floating_player limit="12"]', 'Persistent floating player with an episode drawer. context="all" for unfiltered, or auto-detects series/presenter on those pages.'],
                ['[aipex_podcast_summary]', "Current episode's summary."],
                ['[aipex_podcast_main_points]', "Current episode's main points list."],
                ['[aipex_podcast_transcript]', "Current episode's transcript."],
                ['[aipex_next_previous]', 'Prev/next navigation within the current post type.'],
            ],
            'Episode grids' => [
                ['[aipex_podcast_grid limit="12"]', 'Episode grid. Auto-filters to the current series/presenter context unless context="all".'],
                ['[aipex_latest_podcasts]', 'Always unfiltered — every published episode, latest first. Use this only when you deliberately want a site-wide feed; on a show/presenter page use aipex_show_podcasts / aipex_presenter_podcasts instead, or it will show ALL episodes rather than that page\'s own.'],
                ['[aipex_series_podcasts]', "Episode grid filtered to the current show/series page."],
                ['[aipex_show_podcasts]', 'Alias of aipex_series_podcasts.'],
                ['[aipex_presenter_podcasts]', 'Episode grid filtered to the current presenter page.'],
            ],
            'Show / Series' => [
                ['[aipex_series_grid limit="12"]', 'Grid of all shows.'],
                ['[aipex_series_details]', "Current show's overview."],
                ['[aipex_show_summary]', 'Alias of aipex_series_details.'],
                ['[aipex_series_main_points]', "Current show's main topics."],
                ['[aipex_show_main_topics]', 'Alias of aipex_series_main_points.'],
                ['[aipex_series_episode_summaries]', "Current show's per-episode summaries list."],
                ['[aipex_episode_series]', 'Link back to the show an episode belongs to.'],
            ],
            'Presenter' => [
                ['[aipex_presenter_grid limit="12"]', 'Grid of all presenters.'],
                ['[aipex_presenter_about]', "Current presenter's bio."],
                ['[aipex_presenter_box]', 'Compact presenter card with photo and link.'],
                ['[aipex_presenter_links]', 'Social links plus contact email/phone, if set.'],
                ['[aipex_subscribe]', 'Subscribe links (falls back to the podcast RSS feed on a show page with no links set).'],
            ],
            'Guest' => [
                ['[aipex_guest_grid limit="12"]', 'Grid of all guests.'],
                ['[aipex_guests]', 'Alias of aipex_guest_grid.'],
                ['[aipex_guest id="123"]', 'A single guest card by ID (defaults to the current page).'],
            ],
            'Sponsor' => [
                ['[aipex_sponsors]', "Current episode/show's sponsor cards."],
                ['[aipex_sponsor id="123"]', 'A single sponsor card by ID (defaults to the current page).'],
                ['[aipex_sponsor_grid]', 'Grid of all sponsors.'],
            ],
        ];
        echo '<div class="wrap"><h1>Podcast Shortcodes</h1>';
        echo '<p>Grouped by what they do. If you\'re placing something new, start with <strong>aipex_relationship_grid</strong> in Recommended — it covers the most ground with the fewest shortcodes to remember.</p>';
        foreach ($groups as $group => $items) {
            echo '<h2>'.esc_html($group).'</h2><table class="widefat striped" style="margin-bottom:24px"><tbody>';
            foreach ($items as [$code, $desc]) {
                echo '<tr><td style="width:320px"><code>'.esc_html($code).'</code></td><td>'.esc_html($desc).'</td><td style="width:90px"><button class="button" onclick="navigator.clipboard.writeText(\''.esc_js($code).'\')">Copy</button></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    public static function tools(){
        self::show_notice();
        echo '<div class="wrap"><h1>Tools & Scanners</h1>';
        echo '<form method="post">'; wp_nonce_field('aipex_tools');
        echo '<h2>Core Sync</h2><p><button class="button button-primary" name="aipex_sync_dates" value="1">Sync Published Dates & Durations</button></p>';
        echo '<h2>TXT Content Scanner</h2><p>Scans Media Library TXT files, imports transcripts, summaries, series overviews, main points and hashtags. Matches below 90% are held for review.</p><p><button class="button button-primary" name="aipex_scan_txt" value="1">Scan TXT Content</button></p>';
        echo '<h2>Duplicate Episodes</h2><p>Finds likely duplicate podcast episodes by normalised title and audio URL.</p><p><button class="button" name="aipex_scan_duplicates" value="1">Scan For Duplicates</button></p>';
        echo '<h2>Episode → Show Matcher</h2>';
        echo '<p>Scans episodes that have no show assigned and matches them against show titles using the episode title. Episodes where the show name appears in the episode title will match confidently (e.g. "All Things Autism – Guest Name" → <em>All Things Autism</em>).</p>';
        echo '<p><strong>Auto-links</strong> anything scoring 90%+ (show name found in episode title). <strong>Holds for your review</strong> anything scoring 60–89% (uncertain) and below 60% (no confident match — you pick the show manually). Nothing is silently dropped.</p>';
        self::render_title_match_ui();
        echo '<h2>Relationship Index</h2>';
        echo '<p>Rebuilds the episode/show/host/guest/sponsor relationship table from current ACF data. Processes 50 posts per AJAX batch so it won\'t time out on a large catalogue. Safe to run at any time — no posts are modified.</p>';
        self::render_rel_sync_ui();
        echo '<h2>Default Show Sponsor</h2><p>Set WRS or another sponsor as the default sponsor for all shows/series.</p>';
        echo '<p><label>Sponsor ID <input type="number" name="default_sponsor_id" value="'.esc_attr(Aipex_Podcast_Settings::get('default_sponsor_id')).'" min="0" style="width:120px"></label> <span class="description">Set the site default on the Settings page.</span></p>';
        echo '<p><label><input type="checkbox" name="replace_existing_sponsors" value="1"> Replace existing show sponsors instead of only filling blanks</label></p>';
        echo '<p><button class="button button-primary" name="aipex_apply_default_sponsor" value="1">Apply Default Sponsor To Shows</button> ';
        echo '<button class="button" name="aipex_remove_default_sponsor" value="1" onclick="return confirm(&quot;Remove this sponsor from all shows?&quot;)">Remove This Sponsor From Shows</button></p>';
        echo '</form>';
        self::render_title_match_review();
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
    public static function render_rel_sync_ui(){
        $nonce = wp_create_nonce('aipex_rel_sync');
        ?>
        <div id="aipex-rel-sync-wrap" style="margin-top:12px;max-width:700px">
            <p>
                <button type="button" class="button button-primary" id="aipex-rel-sync-start">Start Relationship Sync</button>
                <button type="button" class="button" id="aipex-rel-sync-stop" style="display:none">Stop</button>
                <span id="aipex-rel-sync-status" style="margin-left:12px;color:#646970"></span>
            </p>
            <div style="height:16px;background:#f0f0f1;border-radius:20px;overflow:hidden;margin-bottom:8px">
                <div id="aipex-rel-sync-bar" style="height:16px;width:0%;background:var(--aipex-brand,#e4005a);border-radius:20px;transition:width .3s ease"></div>
            </div>
            <pre id="aipex-rel-sync-log" style="background:#f6f7f7;padding:10px;max-height:180px;overflow:auto;white-space:pre-wrap;font-size:12px"></pre>
        </div>
        <script>
        jQuery(function($){
            var running=false, nonce=<?php echo wp_json_encode($nonce); ?>;
            var $start=$('#aipex-rel-sync-start'),$stop=$('#aipex-rel-sync-stop');
            var $bar=$('#aipex-rel-sync-bar'),$status=$('#aipex-rel-sync-status'),$log=$('#aipex-rel-sync-log');
            function log(msg){ $log.text($log.text()?$log.text()+'\n'+msg:msg); $log.scrollTop($log[0].scrollHeight); }
            function step(action){
                if(!running) return;
                $.post(ajaxurl,{action:action,nonce:nonce},function(resp){
                    if(!resp||!resp.success){ running=false; $start.prop('disabled',false); $stop.hide(); log('ERROR: '+(resp&&resp.data&&resp.data.message?resp.data.message:'Unknown error')); return; }
                    var d=resp.data;
                    $bar.css('width',d.pct+'%');
                    $status.text('Synced '+d.done+' of '+d.total+' ('+d.pct+'%)');
                    log('Batch done — '+d.done+'/'+d.total+' synced');
                    if(d.finished){ running=false; $start.prop('disabled',false); $stop.hide(); $bar.css('width','100%'); $status.text('Complete. '+d.total+' posts synced. Refresh the page to confirm.'); log('Finished.'); }
                    else setTimeout(function(){ step('aipex_rel_sync_batch'); },200);
                }).fail(function(xhr){ running=false; $start.prop('disabled',false); $stop.hide(); log('AJAX failed: HTTP '+xhr.status); });
            }
            $start.on('click',function(){ running=true; $log.text(''); $bar.css('width','0%'); $status.text('Starting…'); $start.prop('disabled',true); $stop.show(); step('aipex_rel_sync_start'); });
            $stop.on('click',function(){ running=false; $start.prop('disabled',false); $stop.hide(); $status.text('Stopped.'); log('Stopped by user — click Start to resume from the beginning.'); });
        });
        </script>
        <?php
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

    // =========================================================================
    // Episode → Show title matcher
    // =========================================================================
    const TITLE_MATCH_REVIEW = 'aipex_title_match_review';
    const TITLE_MATCH_STATE  = 'aipex_title_match_state';

    public static function render_title_match_ui(){
        $nonce = wp_create_nonce('aipex_title_match');
        $review_count = count(get_option(self::TITLE_MATCH_REVIEW, []));
        ?>
        <div id="aipex-tm-wrap" style="max-width:700px;margin-top:10px">
            <p>
                <button type="button" class="button button-primary" id="aipex-tm-start">Start Scan</button>
                <button type="button" class="button" id="aipex-tm-stop" style="display:none">Stop</button>
                <?php if($review_count): ?><strong style="margin-left:12px"><?php echo esc_html($review_count); ?> episodes waiting in the review table below.</strong><?php endif; ?>
                <span id="aipex-tm-status" style="margin-left:12px;color:#646970"></span>
            </p>
            <div style="height:16px;background:#f0f0f1;border-radius:20px;overflow:hidden;margin-bottom:8px">
                <div id="aipex-tm-bar" style="height:16px;width:0%;background:var(--aipex-brand,#e4005a);border-radius:20px;transition:width .3s ease"></div>
            </div>
            <pre id="aipex-tm-log" style="background:#f6f7f7;padding:10px;max-height:200px;overflow:auto;white-space:pre-wrap;font-size:12px"></pre>
        </div>
        <script>
        jQuery(function($){
            var running=false, nonce=<?php echo wp_json_encode($nonce); ?>;
            var $start=$('#aipex-tm-start'),$stop=$('#aipex-tm-stop');
            var $bar=$('#aipex-tm-bar'),$status=$('#aipex-tm-status'),$log=$('#aipex-tm-log');
            function log(msg){ $log.text($log.text()?$log.text()+'\n'+msg:msg); $log.scrollTop($log[0].scrollHeight); }
            function step(action){
                if(!running) return;
                $.post(ajaxurl,{action:action,nonce:nonce},function(resp){
                    if(!resp||!resp.success){ running=false; $start.prop('disabled',false); $stop.hide(); log('ERROR: '+(resp&&resp.data&&resp.data.message?resp.data.message:'Unknown error')); return; }
                    var d=resp.data;
                    $bar.css('width',d.pct+'%');
                    $.each(d.log||[],function(_,l){ log(l); });
                    $status.text('Checked '+d.done+' of '+d.total+' (linked: '+d.linked+', review: '+d.review+', skipped: '+d.skipped+')');
                    if(d.finished){ running=false; $start.prop('disabled',false); $stop.hide(); $bar.css('width','100%'); log('Scan complete. Refresh this page to see the review table.'); }
                    else setTimeout(function(){ step('aipex_title_match_batch'); },200);
                }).fail(function(xhr){ running=false; $start.prop('disabled',false); $stop.hide(); log('AJAX failed: HTTP '+xhr.status); });
            }
            $start.on('click',function(){ running=true; $log.text(''); $bar.css('width','0%'); $status.text('Starting…'); $start.prop('disabled',true); $stop.show(); step('aipex_title_match_start'); });
            $stop.on('click',function(){ running=false; $start.prop('disabled',false); $stop.hide(); $status.text('Stopped — click Start to restart from the beginning.'); });
        });
        </script>
        <?php
    }

    /**
     * Returns all series posts as a cached lookup array keyed by ID for the
     * duration of a single batch request.
     */
    private static function get_series_lookup(){
        static $lookup = null;
        if ($lookup !== null) return $lookup;
        $lookup = [];
        $series = get_posts(['post_type'=>'aipex_series','post_status'=>'any','posts_per_page'=>-1,'fields'=>'all']);
        foreach ($series as $s) $lookup[$s->ID] = $s->post_title;
        return $lookup;
    }

    /**
     * For a single episode, finds the best-matching show by title similarity.
     * Always returns the best candidate found (even at very low scores) so
     * the review table can show a suggestion for every episode. Returns null
     * only if there are no series at all.
     */
    private static function best_show_match($episode_title){
        $series = self::get_series_lookup();
        if (!$series) return null;
        $best_id=0; $best_score=0; $best_title='';
        foreach ($series as $sid => $stitle) {
            $score = Aipex_Podcast_Fields::match_score($episode_title, $stitle);
            if ($score > $best_score) { $best_score=$score; $best_id=$sid; $best_title=$stitle; }
        }
        return ['show_id'=>$best_id,'score'=>$best_score,'show_title'=>$best_title];
    }

    /**
     * Applies a confirmed episode→show link: writes to the ACF field and
     * syncs the relationship table. Writing to ACF ensures it shows in the
     * admin editor, not just in the relationship query layer.
     */
    private static function link_episode_to_show($episode_id, $show_id){
        Aipex_Podcast_Fields::update('series', [$show_id], $episode_id);
        // Force a fresh sync so the relationship table reflects the write
        // even if ACF's field key differs from the plain meta key
        Aipex_Podcast_Relationships::sync_post($episode_id);
    }

    public static function ajax_title_match_start(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_title_match','nonce');

        // Reset review list and scan state
        delete_option(self::TITLE_MATCH_REVIEW);

        // Build the list of episodes that have NO show currently assigned
        $all_ids = get_posts(['post_type'=>'aipex_podcast','post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC']);
        $unassigned = [];
        foreach ($all_ids as $id) {
            $existing = Aipex_Podcast_Relationships::episodes_for(Aipex_Podcast_Relationships::TYPE_SHOW, $id);
            // episodes_for returns episodes-for-a-show; we need shows-for-an-episode
            $shows = Aipex_Podcast_Relationships::shows_for(Aipex_Podcast_Relationships::TYPE_EPISODE, $id);
            if (empty($shows)) $unassigned[] = $id;
        }

        $state = ['total'=>count($all_ids),'unassigned'=>count($unassigned),'ids'=>$unassigned,'offset'=>0,'done'=>0,'linked'=>0,'review'=>0,'skipped'=>0];
        set_transient(self::TITLE_MATCH_STATE, $state, HOUR_IN_SECONDS);

        wp_send_json_success(array_merge(self::run_title_match_batch($state), ['log'=>['Found '.count($unassigned).' episodes with no show assigned out of '.count($all_ids).' total. Starting scan…']]));
    }

    public static function ajax_title_match_batch(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_title_match','nonce');
        $state = get_transient(self::TITLE_MATCH_STATE);
        if (!$state || !is_array($state)) wp_send_json_error(['message'=>'No scan in progress. Click Start to begin.']);
        wp_send_json_success(self::run_title_match_batch($state));
    }

    private static function run_title_match_batch($state, $batch_size=30){
        $slice = array_slice($state['ids'], $state['offset'], $batch_size);
        $review = get_option(self::TITLE_MATCH_REVIEW, []); if(!is_array($review)) $review=[];
        $log = [];

        foreach ($slice as $episode_id) {
            $title = get_the_title($episode_id);
            $match = self::best_show_match($title);
            $state['done']++;

            if (!$match) {
                // No series exist at all — skip
                $state['skipped']++;
                continue;
            }

            if ($match['score'] >= 90) {
                // Confident — auto-link
                self::link_episode_to_show($episode_id, $match['show_id']);
                $state['linked']++;
                $log[] = 'LINKED '.$match['score'].'%: '.mb_substr($title,0,60).' → '.$match['show_title'];
            } elseif ($match['score'] >= 60) {
                // Uncertain but plausible — review with suggestion pre-selected
                $review[] = ['episode_id'=>$episode_id,'episode_title'=>$title,'show_id'=>$match['show_id'],'show_title'=>$match['show_title'],'score'=>$match['score']];
                $state['review']++;
                $log[] = 'REVIEW '.$match['score'].'%: '.mb_substr($title,0,60).' → '.$match['show_title'].'?';
            } else {
                // Low confidence — add to review with best guess shown but
                // no show pre-selected, so user must pick manually
                $review[] = ['episode_id'=>$episode_id,'episode_title'=>$title,'show_id'=>0,'show_title'=>$match['show_title'].' ('.$match['score'].'% — no confident match)','score'=>$match['score']];
                $state['review']++;
                $log[] = 'UNMATCHED '.$match['score'].'%: '.mb_substr($title,0,60).' (best guess: '.$match['show_title'].')';
            }
        }

        $state['offset'] += $batch_size;
        $finished = $state['offset'] >= count($state['ids']);

        update_option(self::TITLE_MATCH_REVIEW, $review, false);
        if ($finished) {
            delete_transient(self::TITLE_MATCH_STATE);
            $log[] = 'Done. Auto-linked: '.$state['linked'].'. Needs review: '.$state['review'].'. Skipped (no match): '.$state['skipped'].'.';
        } else {
            set_transient(self::TITLE_MATCH_STATE, $state, HOUR_IN_SECONDS);
        }

        $total = max(1, count($state['ids']));
        return [
            'done'     => $state['done'],
            'total'    => count($state['ids']),
            'linked'   => $state['linked'],
            'review'   => $state['review'],
            'skipped'  => $state['skipped'],
            'finished' => $finished,
            'pct'      => min(100, (int)round(100 * $state['offset'] / $total)),
            'log'      => $log,
        ];
    }

    public static function render_title_match_review(){
        $items = get_option(self::TITLE_MATCH_REVIEW, []);
        if (!$items) return;
        $series = get_posts(['post_type'=>'aipex_series','post_status'=>'any','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
        echo '<hr><h2>Episode → Show: Needs Review ('.count($items).' episodes)</h2>';
        echo '<p>';
        echo '<strong style="color:#b45309">⚠ 60–89%</strong> — uncertain match, show pre-selected, check it\'s correct. ';
        echo '<strong style="color:#b91c1c">✗ Below 60%</strong> — no confident match found, dropdown defaults to "— Skip —", you must pick the show manually. ';
        echo 'Leave unticked to skip for now.';
        echo '</p>';
        echo '<form method="post">'; wp_nonce_field('aipex_tools');
        echo '<p>';
        echo '<button class="button button-primary" name="aipex_apply_title_match_review" value="1">Apply Selected</button> ';
        echo '<button class="button" name="aipex_clear_title_match" value="1" onclick="return confirm(\'Clear the review list?\')">Clear List</button>';
        echo ' <label style="margin-left:16px"><input type="checkbox" id="aipex-tm-check-all"> Select all</label>';
        echo '</p>';
        echo '<table class="widefat striped"><thead><tr><th style="width:32px">Apply</th><th>Episode</th><th style="width:60px">Score</th><th>Assign to Show</th></tr></thead><tbody>';
        foreach ($items as $i => $item) {
            $episode_id = (int)$item['episode_id'];
            $score_color = $item['score'] >= 60 ? '#b45309' : '#b91c1c';
            $score_label = $item['score'] >= 60 ? $item['score'].'%' : $item['score'].'% ✗';
            echo '<tr>';
            echo '<td><input type="checkbox" class="aipex-tm-cb" name="title_match_review['.(int)$i.'][apply]" value="1"></td>';
            echo '<td><a href="'.esc_url(get_edit_post_link($episode_id)).'" target="_blank">'.esc_html($item['episode_title']).'</a></td>';
            echo '<td><strong style="color:'.esc_attr($score_color).'">'.esc_html($score_label).'</strong></td>';
            echo '<td><select name="title_match_review['.(int)$i.'][show_id]"><option value="0">— Skip —</option>';
            foreach ($series as $s) {
                $selected = ((int)$item['show_id'] === (int)$s->ID) ? 'selected' : '';
                echo '<option value="'.(int)$s->ID.'" '.$selected.'>'.esc_html($s->post_title).'</option>';
            }
            echo '</select></td></tr>';
        }
        echo '</tbody></table></form>';
        echo '<script>jQuery(function($){ $("#aipex-tm-check-all").on("change",function(){ $(".aipex-tm-cb").prop("checked",this.checked); }); });</script>';
    }

    public static function apply_title_match_review($posted){
        $items = get_option(self::TITLE_MATCH_REVIEW, []);
        $remaining = []; $done = 0;
        foreach ($items as $i => $item) {
            $row = $posted[$i] ?? [];
            if (empty($row['apply'])){ $remaining[] = $item; continue; }
            $show_id = (int)($row['show_id'] ?? 0);
            if (!$show_id){ $remaining[] = $item; continue; }
            self::link_episode_to_show((int)$item['episode_id'], $show_id);
            $done++;
        }
        update_option(self::TITLE_MATCH_REVIEW, array_values($remaining), false);
        return 'Applied '.$done.' episode→show links. '.count($remaining).' still in review.';
    }
}
