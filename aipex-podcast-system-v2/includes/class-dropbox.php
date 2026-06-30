<?php
if (!defined('ABSPATH')) exit;

class Aipex_Podcast_Dropbox {
    const STATE_TRANSIENT_PREFIX = 'aipex_dropbox_oauth_state_';
    const REVIEW_OPTION = 'aipex_dropbox_review_items';
    const FAILED_OPTION = 'aipex_dropbox_failed_items';
    const SCAN_STATE_OPTION = 'aipex_dropbox_scan_state';
    const MP3_INDEX_OPTION = 'aipex_dropbox_mp3_index';

    /**
     * Secrets (app secret, manual token, OAuth access/refresh tokens) are
     * encrypted at rest with AES-256-GCM rather than stored as plaintext
     * wp_options. A wp_options/DB-table leak no longer hands over standing
     * Dropbox API access.
     */
    private static function get_secret($option){ return Aipex_Podcast_Crypto::decrypt(get_option($option, '')); }
    private static function set_secret($option, $value){ update_option($option, Aipex_Podcast_Crypto::encrypt($value)); }

    public static function page(){
        if(!current_user_can('manage_options')) return;
        $app_key=get_option('aipex_dropbox_app_key','');
        $app_secret=self::get_secret('aipex_dropbox_app_secret');
        $manual_token=self::get_secret('aipex_dropbox_token');
        $refresh=self::get_secret('aipex_dropbox_refresh_token');
        $path=get_option('aipex_dropbox_path','');
        $redirect=self::redirect_uri();
        $connected = !empty($refresh) || !empty($manual_token);

        echo '<div class="wrap"><h1>Dropbox Importer</h1>';
        echo '<p>Connect Dropbox, choose the root folder path, then scan recursively for MP3/TXT files and link confident MP3 matches to podcast episodes.</p>';
        echo $connected ? '<div class="notice notice-success inline"><p><strong>Dropbox status:</strong> Connected.</p></div>' : '<div class="notice notice-warning inline"><p><strong>Dropbox status:</strong> Not connected.</p></div>';
        echo '<form method="post">'; wp_nonce_field('aipex_dropbox');
        echo '<h2>Dropbox OAuth</h2><table class="form-table">';
        echo '<tr><th>Redirect URI</th><td><input readonly class="large-text code" value="'.esc_attr($redirect).'"><p class="description"><strong>Add this exact URL</strong> to your Dropbox app Redirect URIs. It must match character-for-character.</p></td></tr>';
        echo '<tr><th>Dropbox App Key</th><td><input name="app_key" value="'.esc_attr($app_key).'" class="regular-text"></td></tr>';
        echo '<tr><th>Dropbox App Secret</th><td><input type="password" name="app_secret" value="'.esc_attr($app_secret).'" class="regular-text" autocomplete="off"></td></tr>';
        echo '<tr><th>Root Folder Path</th><td><input name="path" value="'.esc_attr($path).'" class="regular-text" placeholder="/Podcasts"><p class="description">Example: /Radio/Podcasts. Leave blank to scan the Dropbox root.</p></td></tr>';
        echo '</table>';
        echo '<p><button class="button" name="aipex_dropbox_save" value="1">Save Settings</button> ';
        if($app_key && $app_secret){ echo '<a class="button button-primary" href="'.esc_url(self::connect_url()).'">Connect Dropbox</a> '; }
        else { echo '<button class="button button-primary" disabled>Connect Dropbox</button> <span class="description">Save your App Key and App Secret first.</span> '; }
        echo '<button type="button" class="button button-primary" id="aipex-dropbox-start-scan">Start Batch Scan</button> ';
        echo '<button type="button" class="button" id="aipex-dropbox-stop-scan" style="display:none">Stop Scan</button> ';
        echo '<span class="description">Scans in small AJAX batches to avoid 504 timeouts.</span> ';
        echo '<button class="button" name="aipex_dropbox_disconnect" value="1" onclick="return confirm(\'Disconnect Dropbox?\')">Disconnect</button> ';
        echo '<button class="button" name="aipex_dropbox_clear_review" value="1">Clear Review Lists</button></p>';
        echo '<h2>Temporary Access Token Fallback</h2><p class="description">Use only for testing. OAuth above is preferred.</p>';
        echo '<input type="password" name="token" value="'.esc_attr($manual_token).'" class="large-text" autocomplete="off">';
        echo '</form>';
        self::render_batch_scan_ui();

        if($r=get_transient('aipex_dropbox_last_result')){ echo '<h2>Last result</h2><pre style="background:#fff;padding:12px;max-height:360px;overflow:auto;white-space:pre-wrap">'.esc_html($r).'</pre>'; delete_transient('aipex_dropbox_last_result'); }
        self::render_review_tables();
        echo '<hr><h2>Dropbox app setup</h2><ol><li>Open your Dropbox app in the Developer Console.</li><li>Add the Redirect URI shown above exactly.</li><li>Enable <code>files.metadata.read</code>, <code>files.content.read</code> and <code>sharing.write</code>. The <code>sharing.write</code> permission is needed to create playable shared MP3 links.</li><li>Save changes in Dropbox, then click Connect Dropbox here.</li></ol>';
        echo '</div>';
    }

    public static function render_review_tables(){
        $review = get_option(self::REVIEW_OPTION, []);
        $failed = get_option(self::FAILED_OPTION, []);
        $episodes = get_posts(['post_type'=>'aipex_podcast','posts_per_page'=>-1,'post_status'=>'any','orderby'=>'title','order'=>'ASC']);
        $episode_select = function($name,$selected=0) use ($episodes){
            $html='<select name="'.esc_attr($name).'"><option value="0">— Select episode —</option>';
            foreach($episodes as $ep){ $html.='<option value="'.(int)$ep->ID.'" '.selected((int)$selected,(int)$ep->ID,false).'>'.esc_html($ep->post_title).'</option>'; }
            return $html.'</select>';
        };

        echo '<h2>Needs Review</h2>';
        if(!$review){ echo '<p>No uncertain matches waiting for review.</p>'; }
        else {
            echo '<form method="post">'; wp_nonce_field('aipex_dropbox_review');
            echo '<p><button class="button button-primary" name="aipex_dropbox_apply_review" value="1">Apply Selected Review Matches</button></p>';
            echo '<table class="widefat striped"><thead><tr><th>Apply</th><th>Dropbox MP3</th><th>Confidence</th><th>Suggested Episode</th><th>Choose Episode</th></tr></thead><tbody>';
            foreach($review as $i=>$item){
                echo '<tr><td><input type="checkbox" name="review['.(int)$i.'][apply]" value="1"></td><td><strong>'.esc_html($item['name']??'').'</strong><br><code>'.esc_html($item['path']??'').'</code></td><td>'.esc_html($item['score']??0).'%</td><td>'.esc_html($item['suggested_title']??'').'</td><td>'.$episode_select('review['.(int)$i.'][episode_id]', (int)($item['episode_id']??0)).'</td></tr>';
            }
            echo '</tbody></table></form>';
        }

        echo '<h2>Failed URL Creation</h2>';
        if(!$failed){ echo '<p>No failed Dropbox URL items.</p>'; }
        else {
            echo '<p>These files matched, but Dropbox did not return a playable shared URL. This is usually missing <code>sharing.write</code> permission, or the Dropbox app has not been reconnected after permissions changed. You can retry after fixing permissions, or paste a manual Dropbox URL.</p>';
            echo '<form method="post">'; wp_nonce_field('aipex_dropbox_failed');
            echo '<p><button class="button button-primary" name="aipex_dropbox_retry_failed" value="1">Retry Selected URL Creation</button> <button class="button" name="aipex_dropbox_apply_manual_failed" value="1">Apply Manual URLs</button></p>';
            echo '<table class="widefat striped"><thead><tr><th>Apply</th><th>Dropbox MP3</th><th>Error</th><th>Episode</th><th>Manual Dropbox URL</th></tr></thead><tbody>';
            foreach($failed as $i=>$item){
                echo '<tr><td><input type="checkbox" name="failed['.(int)$i.'][apply]" value="1"></td><td><strong>'.esc_html($item['name']??'').'</strong><br><code>'.esc_html($item['path']??'').'</code></td><td>'.esc_html($item['error']??'Unknown error').'</td><td>'.$episode_select('failed['.(int)$i.'][episode_id]', (int)($item['episode_id']??0)).'</td><td><input class="large-text" name="failed['.(int)$i.'][manual_url]" placeholder="https://www.dropbox.com/..." value=""></td></tr>';
            }
            echo '</tbody></table></form>';
        }
    }

    public static function redirect_uri(){ return rest_url('aipex-podcast/v1/dropbox/callback'); }
    public static function admin_page_url(){ return admin_url('edit.php?post_type=aipex_podcast&page=aipex-podcast-dropbox'); }

    public static function register_rest_routes(){
        register_rest_route('aipex-podcast/v1','/dropbox/callback',['methods'=>'GET','callback'=>[__CLASS__,'rest_callback'],'permission_callback'=>'__return_true']);
    }
    public static function create_state(){ $state=wp_generate_password(32,false,false); set_transient(self::STATE_TRANSIENT_PREFIX.$state,['created'=>time(),'user_id'=>get_current_user_id()],10*MINUTE_IN_SECONDS); return $state; }
    public static function verify_state($state){ $state=sanitize_text_field($state); if(!$state)return false; $data=get_transient(self::STATE_TRANSIENT_PREFIX.$state); delete_transient(self::STATE_TRANSIENT_PREFIX.$state); return !empty($data); }
    public static function connect_url(){ $key=get_option('aipex_dropbox_app_key',''); $state=self::create_state(); return add_query_arg(['client_id'=>$key,'response_type'=>'code','token_access_type'=>'offline','redirect_uri'=>self::redirect_uri(),'state'=>$state],'https://www.dropbox.com/oauth2/authorize'); }

    public static function rest_callback(WP_REST_Request $request){
        if($err=$request->get_param('error')){ set_transient('aipex_dropbox_last_result','Dropbox OAuth error: '.sanitize_text_field($err),60); wp_safe_redirect(self::admin_page_url()); exit; }
        $state=$request->get_param('state'); $code=$request->get_param('code');
        if(!$code || !$state || !self::verify_state($state)){ set_transient('aipex_dropbox_last_result','Dropbox OAuth failed: missing/invalid state or authorization code. Try connecting again.',60); wp_safe_redirect(self::admin_page_url()); exit; }
        set_transient('aipex_dropbox_last_result', self::exchange_code(sanitize_text_field($code)), 60); wp_safe_redirect(self::admin_page_url()); exit;
    }

    public static function handle_actions(){
        if(!current_user_can('manage_options')) return;
        if(!empty($_POST['aipex_dropbox_apply_review'])){ self::handle_review_apply(); return; }
        if(!empty($_POST['aipex_dropbox_retry_failed']) || !empty($_POST['aipex_dropbox_apply_manual_failed'])){ self::handle_failed_apply(); return; }
        if(empty($_POST) || (!isset($_POST['aipex_dropbox_save']) && !isset($_POST['aipex_dropbox_scan']) && !isset($_POST['aipex_dropbox_disconnect']) && !isset($_POST['aipex_dropbox_clear_review']))) return;
        check_admin_referer('aipex_dropbox');
        if(isset($_POST['aipex_dropbox_disconnect'])){ delete_option('aipex_dropbox_refresh_token'); delete_option('aipex_dropbox_access_token'); delete_option('aipex_dropbox_access_expires'); delete_option('aipex_dropbox_token'); set_transient('aipex_dropbox_last_result','Dropbox disconnected.',60); wp_safe_redirect(self::admin_page_url()); exit; }
        if(isset($_POST['aipex_dropbox_clear_review'])){ delete_option(self::REVIEW_OPTION); delete_option(self::FAILED_OPTION); set_transient('aipex_dropbox_last_result','Dropbox review and failed lists cleared.',60); wp_safe_redirect(self::admin_page_url()); exit; }
        update_option('aipex_dropbox_app_key', sanitize_text_field(wp_unslash($_POST['app_key']??'')));
        self::set_secret('aipex_dropbox_app_secret', sanitize_text_field(wp_unslash($_POST['app_secret']??'')));
        self::set_secret('aipex_dropbox_token', sanitize_text_field(wp_unslash($_POST['token']??'')));
        update_option('aipex_dropbox_path', sanitize_text_field(wp_unslash($_POST['path']??'')));
        if(isset($_POST['aipex_dropbox_save'])) set_transient('aipex_dropbox_last_result','Dropbox settings saved. Add the Redirect URI to Dropbox, then click Connect Dropbox.',60);
        if(isset($_POST['aipex_dropbox_scan'])) set_transient('aipex_dropbox_last_result', 'Use the Start Batch Scan button. The old one-request scanner has been disabled to prevent 504 gateway timeouts.', 60);
        wp_safe_redirect(self::admin_page_url()); exit;
    }

    public static function handle_review_apply(){
        check_admin_referer('aipex_dropbox_review');
        $items=get_option(self::REVIEW_OPTION,[]); $posted=wp_unslash($_POST['review']??[]); $new=[]; $done=0; $failed=get_option(self::FAILED_OPTION,[]);
        foreach($items as $i=>$item){
            $row=$posted[$i]??[]; if(empty($row['apply'])){ $new[]=$item; continue; }
            $episode_id=(int)($row['episode_id']??($item['episode_id']??0)); if(!$episode_id){ $new[]=$item; continue; }
            $result=self::shared_url_result($item['path']);
            if(!empty($result['url'])){ self::attach_dropbox_url($episode_id,$result['url']); $done++; }
            else { $item['episode_id']=$episode_id; $item['error']=$result['error']??'Could not create Dropbox URL'; $failed[]=$item; }
        }
        update_option(self::REVIEW_OPTION,array_values($new),false); update_option(self::FAILED_OPTION,array_values($failed),false);
        set_transient('aipex_dropbox_last_result','Applied '.$done.' reviewed Dropbox matches.',60); wp_safe_redirect(self::admin_page_url()); exit;
    }

    public static function handle_failed_apply(){
        check_admin_referer('aipex_dropbox_failed');
        $items=get_option(self::FAILED_OPTION,[]); $posted=wp_unslash($_POST['failed']??[]); $new=[]; $done=0;
        foreach($items as $i=>$item){
            $row=$posted[$i]??[]; if(empty($row['apply'])){ $new[]=$item; continue; }
            $episode_id=(int)($row['episode_id']??($item['episode_id']??0)); if(!$episode_id){ $new[]=$item; continue; }
            $url='';
            if(!empty($_POST['aipex_dropbox_apply_manual_failed']) && !empty($row['manual_url'])) $url=esc_url_raw($row['manual_url']);
            else { $result=self::shared_url_result($item['path']); if(!empty($result['url'])) $url=$result['url']; else $item['error']=$result['error']??'Could not create Dropbox URL'; }
            if($url){ self::attach_dropbox_url($episode_id,$url); $done++; }
            else $new[]=$item;
        }
        update_option(self::FAILED_OPTION,array_values($new),false); set_transient('aipex_dropbox_last_result','Resolved '.$done.' failed Dropbox URL items.',60); wp_safe_redirect(self::admin_page_url()); exit;
    }

    public static function exchange_code($code){
        $key=get_option('aipex_dropbox_app_key',''); $secret=self::get_secret('aipex_dropbox_app_secret'); if(!$key || !$secret) return 'Missing Dropbox App Key or App Secret.';
        $res=wp_remote_post('https://api.dropboxapi.com/oauth2/token',['timeout'=>45,'body'=>['code'=>$code,'grant_type'=>'authorization_code','client_id'=>$key,'client_secret'=>$secret,'redirect_uri'=>self::redirect_uri()]]);
        if(is_wp_error($res)) return $res->get_error_message(); $raw=wp_remote_retrieve_body($res); $body=json_decode($raw,true); if(empty($body['access_token'])) return 'Dropbox OAuth failed: '.$raw;
        self::set_secret('aipex_dropbox_access_token', sanitize_text_field($body['access_token'])); update_option('aipex_dropbox_access_expires',time()+intval($body['expires_in']??14400)-120); if(!empty($body['refresh_token'])) self::set_secret('aipex_dropbox_refresh_token', sanitize_text_field($body['refresh_token'])); return 'Dropbox connected successfully.';
    }

    public static function get_access_token(){
        $manual=self::get_secret('aipex_dropbox_token'); $refresh=self::get_secret('aipex_dropbox_refresh_token'); $access=self::get_secret('aipex_dropbox_access_token'); $expires=(int)get_option('aipex_dropbox_access_expires',0);
        if($refresh){ if($access && $expires>time()+60) return $access; $key=get_option('aipex_dropbox_app_key',''); $secret=self::get_secret('aipex_dropbox_app_secret'); $res=wp_remote_post('https://api.dropboxapi.com/oauth2/token',['timeout'=>45,'body'=>['grant_type'=>'refresh_token','refresh_token'=>$refresh,'client_id'=>$key,'client_secret'=>$secret]]); if(!is_wp_error($res)){ $body=json_decode(wp_remote_retrieve_body($res),true); if(!empty($body['access_token'])){ self::set_secret('aipex_dropbox_access_token', sanitize_text_field($body['access_token'])); update_option('aipex_dropbox_access_expires',time()+intval($body['expires_in']??14400)-120); return $body['access_token']; } } }
        return $manual;
    }
    public static function api($endpoint,$body){ $token=self::get_access_token(); if(!$token) return new WP_Error('no_token','No Dropbox connection/token set.'); return wp_remote_post('https://api.dropboxapi.com/2/'.$endpoint,['headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],'body'=>wp_json_encode($body),'timeout'=>45]); }

    public static function scan(){
        $path=get_option('aipex_dropbox_path',''); $log=[]; $entries=[]; $cursor='';
        $res=self::api('files/list_folder',['path'=>$path,'recursive'=>true,'include_media_info'=>false,'include_deleted'=>false,'limit'=>2000]);
        if(is_wp_error($res)) return $res->get_error_message(); $raw=wp_remote_retrieve_body($res); $data=json_decode($raw,true); if(empty($data['entries']) && empty($data['has_more'])) return 'No files found or Dropbox API returned no entries: '.$raw;
        $entries=array_merge($entries,$data['entries']??[]); $cursor=$data['cursor']??'';
        while(!empty($data['has_more']) && $cursor){ $res=self::api('files/list_folder/continue',['cursor'=>$cursor]); if(is_wp_error($res)) break; $data=json_decode(wp_remote_retrieve_body($res),true); $entries=array_merge($entries,$data['entries']??[]); $cursor=$data['cursor']??''; if(count($entries)>10000){ $log[]='Stopped at 10,000 files to avoid timeout. Narrow the folder path and rescan.'; break; } }
        $files=[]; foreach($entries as $e){ if(($e['.tag']??'')==='file' && preg_match('/\.mp3$/i',$e['name'])) $files[]=$e; }
        $episodes=get_posts(['post_type'=>'aipex_podcast','posts_per_page'=>-1,'post_status'=>'any']); $review=[]; $failed=[]; $linked=0;
        foreach($files as $f){ $best=null; $score=0; foreach($episodes as $ep){ $s=Aipex_Podcast_Fields::match_score($f['name'],$ep->post_title); if($s>$score){$score=$s;$best=$ep;} }
            $item=['name'=>$f['name'],'path'=>$f['path_lower']??$f['path_display']??'','score'=>$score,'episode_id'=>$best?$best->ID:0,'suggested_title'=>$best?$best->post_title:''];
            if($best && $score>=90){ $result=self::shared_url_result($item['path']); if(!empty($result['url'])){ self::attach_dropbox_url($best->ID,$result['url']); $linked++; $log[]='LINKED '.$score.'%: '.$f['name'].' → '.$best->post_title; } else { $item['error']=$result['error']??'Could not create Dropbox URL'; $failed[]=$item; $log[]='FAILED URL: '.$f['name'].' — '.$item['error']; } }
            else { $review[]=$item; $log[]='REVIEW '.$score.'%: '.$f['name'].($best?' → possible '.$best->post_title:''); }
        }
        update_option(self::REVIEW_OPTION,$review,false); update_option(self::FAILED_OPTION,$failed,false);
        array_unshift($log,'Scan complete. Linked: '.$linked.'. Needs review: '.count($review).'. Failed URL creation: '.count($failed).'.');
        return implode("\n",$log ?: ['Scan finished. No MP3 files found.']);
    }

    public static function attach_dropbox_url($episode_id,$url){ Aipex_Podcast_Fields::update_field('audio_source_type','dropbox',$episode_id); Aipex_Podcast_Fields::update_field('dropbox_url',Aipex_Podcast_Fields::dropbox_direct($url),$episode_id); }

    public static function shared_url_result($path){
        $res=self::api('sharing/create_shared_link_with_settings',['path'=>$path,'settings'=>['requested_visibility'=>'public']]);
        if(!is_wp_error($res)){ $raw=wp_remote_retrieve_body($res); $body=json_decode($raw,true); if(!empty($body['url'])) return ['url'=>Aipex_Podcast_Fields::dropbox_direct($body['url']),'error'=>'']; $err=self::dropbox_error($raw); }
        else $err=$res->get_error_message();
        $res2=self::api('sharing/list_shared_links',['path'=>$path,'direct_only'=>true]);
        if(!is_wp_error($res2)){ $raw2=wp_remote_retrieve_body($res2); $body=json_decode($raw2,true); if(!empty($body['links'][0]['url'])) return ['url'=>Aipex_Podcast_Fields::dropbox_direct($body['links'][0]['url']),'error'=>'']; if(empty($err)) $err=self::dropbox_error($raw2); }
        elseif(empty($err)) $err=$res2->get_error_message();
        return ['url'=>'','error'=>$err ?: 'Dropbox did not return a shared URL. Check sharing.write permission and reconnect Dropbox.'];
    }

    public static function dropbox_error($raw){
        $body=json_decode($raw,true); if(!$body) return substr((string)$raw,0,300);
        if(!empty($body['error_summary'])) return $body['error_summary'];
        if(!empty($body['error_description'])) return $body['error_description'];
        if(!empty($body['error'])) return is_string($body['error'])?$body['error']:wp_json_encode($body['error']);
        return substr($raw,0,300);
    }

    public static function shared_url($path){ $r=self::shared_url_result($path); return $r['url']??''; }

    public static function render_batch_scan_ui(){
        $nonce = wp_create_nonce('aipex_dropbox_batch');
        echo '<div id="aipex-dropbox-scan-panel" style="margin-top:18px;background:#fff;border:1px solid #ccd0d4;padding:14px;max-width:900px">';
        echo '<h2 style="margin-top:0">Batch Scan Progress</h2>';
        echo '<div id="aipex-dropbox-progress" style="height:18px;background:#f0f0f1;border-radius:20px;overflow:hidden"><div id="aipex-dropbox-progress-bar" style="height:18px;width:0%;background:#e4006d;border-radius:20px"></div></div>';
        echo '<p id="aipex-dropbox-progress-text">Not running.</p>';
        echo '<pre id="aipex-dropbox-log" style="background:#f6f7f7;padding:12px;max-height:260px;overflow:auto;white-space:pre-wrap"></pre>';
        echo '</div>';
        ?>
        <script>
        jQuery(function($){
            var running = false;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var $start = $('#aipex-dropbox-start-scan');
            var $stop = $('#aipex-dropbox-stop-scan');
            var $bar = $('#aipex-dropbox-progress-bar');
            var $text = $('#aipex-dropbox-progress-text');
            var $log = $('#aipex-dropbox-log');
            function appendLog(lines){
                if(!lines) return;
                if($.isArray(lines)) lines = lines.join('\n');
                $log.text(($log.text() ? $log.text() + '\n' : '') + lines);
                $log.scrollTop($log[0].scrollHeight);
            }
            function setProgress(res){
                var processed = parseInt(res.processed || 0, 10);
                var batches = parseInt(res.batches || 0, 10);
                var pct = res.done ? 100 : Math.min(95, batches * 3);
                $bar.css('width', pct + '%');
                $text.text((res.done ? 'Complete. ' : 'Running. ') + 'Processed Dropbox entries: ' + processed + '. Linked: ' + (res.linked||0) + '. Review: ' + (res.review||0) + '. Failed: ' + (res.failed||0) + '.');
            }
            function step(action){
                if(!running) return;
                $.post(ajaxurl, { action: action, nonce: nonce }, function(resp){
                    if(!resp || !resp.success){
                        running = false; $start.prop('disabled', false); $stop.hide();
                        appendLog('ERROR: ' + (resp && resp.data && resp.data.message ? resp.data.message : 'Unknown AJAX error'));
                        return;
                    }
                    var d = resp.data || {};
                    setProgress(d);
                    appendLog(d.log || []);
                    if(d.done){
                        running = false; $start.prop('disabled', false); $stop.hide();
                        $bar.css('width','100%');
                        appendLog('Finished. Refresh this page to review uncertain or failed items.');
                    } else {
                        setTimeout(function(){ step('aipex_dropbox_continue_scan'); }, 300);
                    }
                }).fail(function(xhr){
                    running = false; $start.prop('disabled', false); $stop.hide();
                    appendLog('AJAX FAILED: HTTP ' + xhr.status + '. Try reducing the batch size in the plugin if this persists.');
                });
            }
            $start.on('click', function(e){
                e.preventDefault(); running = true; $log.text(''); $bar.css('width','0%'); $text.text('Starting...');
                $start.prop('disabled', true); $stop.show(); step('aipex_dropbox_start_scan');
            });
            $stop.on('click', function(e){ e.preventDefault(); running = false; $start.prop('disabled', false); $stop.hide(); appendLog('Stopped by user.'); });
        });
        </script>
        <?php
    }

    public static function ajax_start_scan(){
        if(!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_dropbox_batch','nonce');
        delete_option(self::REVIEW_OPTION);
        delete_option(self::FAILED_OPTION);
        delete_option(self::MP3_INDEX_OPTION);
        $state = ['cursor'=>'','has_more'=>false,'processed'=>0,'linked'=>0,'review'=>0,'failed'=>0,'batches'=>0,'done'=>false];
        update_option(self::SCAN_STATE_OPTION, $state, false);
        self::run_scan_batch(true);
    }

    public static function ajax_continue_scan(){
        if(!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_dropbox_batch','nonce');
        self::run_scan_batch(false);
    }

    public static function run_scan_batch($first=false){
        $state = get_option(self::SCAN_STATE_OPTION, []);
        if(!is_array($state)) $state=[];
        $batch_size = max(5, min(50, (int)apply_filters('aipex_dropbox_batch_size', 10)));
        $log=[];
        if($first){
            $path = get_option('aipex_dropbox_path','');
            $res = self::api('files/list_folder', ['path'=>$path,'recursive'=>true,'include_media_info'=>false,'include_deleted'=>false,'limit'=>$batch_size]);
        } else {
            if(empty($state['has_more']) || empty($state['cursor'])){
                $state['done']=true; update_option(self::SCAN_STATE_OPTION,$state,false); self::send_scan_state($state, ['No more Dropbox entries to scan.']);
            }
            $res = self::api('files/list_folder/continue', ['cursor'=>$state['cursor']]);
        }
        if(is_wp_error($res)) wp_send_json_error(['message'=>$res->get_error_message()]);
        $raw = wp_remote_retrieve_body($res);
        $data = json_decode($raw, true);
        if(!is_array($data)) wp_send_json_error(['message'=>'Dropbox API returned an unreadable response: '.substr($raw,0,300)]);
        if(!empty($data['error_summary'])) wp_send_json_error(['message'=>'Dropbox API error: '.$data['error_summary']]);
        $entries = $data['entries'] ?? [];
        $counts = self::process_scan_entries($entries, $log);
        $state['cursor'] = $data['cursor'] ?? ($state['cursor'] ?? '');
        $state['has_more'] = !empty($data['has_more']);
        $state['processed'] = (int)($state['processed'] ?? 0) + count($entries);
        $state['linked'] = (int)($state['linked'] ?? 0) + $counts['linked'];
        $state['review'] = count(get_option(self::REVIEW_OPTION, []));
        $state['failed'] = count(get_option(self::FAILED_OPTION, []));
        $state['batches'] = (int)($state['batches'] ?? 0) + 1;
        $state['done'] = empty($state['has_more']);
        if(!$entries && !$state['done']) $log[] = 'No entries in this batch; continuing...';
        if($state['done']) $log[] = 'Scan complete.';
        update_option(self::SCAN_STATE_OPTION, $state, false);
        self::send_scan_state($state, $log);
    }

    public static function send_scan_state($state, $log=[]){
        wp_send_json_success([
            'done'=>!empty($state['done']),
            'processed'=>(int)($state['processed'] ?? 0),
            'linked'=>(int)($state['linked'] ?? 0),
            'review'=>(int)($state['review'] ?? 0),
            'failed'=>(int)($state['failed'] ?? 0),
            'batches'=>(int)($state['batches'] ?? 0),
            'log'=>$log,
        ]);
    }

    public static function process_scan_entries($entries, &$log){
        $linked = 0;
        $review = get_option(self::REVIEW_OPTION, []); if(!is_array($review)) $review=[];
        $failed = get_option(self::FAILED_OPTION, []); if(!is_array($failed)) $failed=[];
        $mp3_index = get_option(self::MP3_INDEX_OPTION, []); if(!is_array($mp3_index)) $mp3_index=[];
        $episodes = get_posts(['post_type'=>'aipex_podcast','posts_per_page'=>-1,'post_status'=>'any','fields'=>'all']);
        foreach($entries as $e){
            if(($e['.tag']??'') !== 'file') continue;
            $name = $e['name'] ?? '';
            if(!preg_match('/\.mp3$/i', $name)) continue;
            $path = $e['path_lower'] ?? ($e['path_display'] ?? '');
            $idx_key = md5($path ?: $name);
            if(empty($mp3_index[$idx_key])) $mp3_index[$idx_key] = ['name'=>$name,'path'=>$path,'url'=>''];
            $best=null; $score=0;
            foreach($episodes as $ep){
                $s = Aipex_Podcast_Fields::match_score($name, $ep->post_title);
                if($s>$score){ $score=$s; $best=$ep; }
            }
            $item=['name'=>$name,'path'=>$path,'score'=>$score,'episode_id'=>$best?$best->ID:0,'suggested_title'=>$best?$best->post_title:''];
            if($best && $score >= 90){
                $result = self::shared_url_result($path);
                if(!empty($result['url'])){
                    $mp3_index[$idx_key]['url'] = $result['url'];
                    self::attach_dropbox_url($best->ID, $result['url']);
                    $linked++;
                    $log[] = 'LINKED '.$score.'%: '.$name.' → '.$best->post_title;
                } else {
                    $item['error'] = $result['error'] ?? 'Could not create Dropbox URL';
                    $failed[] = $item;
                    $log[] = 'FAILED URL: '.$name.' — '.$item['error'];
                }
            } else {
                $review[] = $item;
                $log[] = 'REVIEW '.$score.'%: '.$name.($best?' → possible '.$best->post_title:'');
            }
        }
        update_option(self::REVIEW_OPTION, array_values($review), false);
        update_option(self::FAILED_OPTION, array_values($failed), false);
        update_option(self::MP3_INDEX_OPTION, array_values($mp3_index), false);
        return ['linked'=>$linked];
    }

}
