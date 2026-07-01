<?php
if (!defined('ABSPATH')) exit;

/**
 * CSV/TSV episode importer.
 *
 * Expected columns (tab or comma separated, case-insensitive headers):
 *   Podcast Title | Series | Presenter | soundcloud_url | Duration
 *
 * Duration is decimal minutes (e.g. 17.96 → stored as "17:58").
 *
 * Matching uses the Series column to narrow the episode search to posts
 * already linked to that show, which is far more accurate than doing a
 * full-catalogue title fuzzy-match. The Presenter column is used to
 * assign/verify the presenter relationship.
 *
 * Does NOT overwrite any field that already has a value on the matched post.
 */
class Aipex_Podcast_CSV_Importer {

    const ROWS_OPTION   = 'aipex_csv_rows';
    const REVIEW_OPTION = 'aipex_csv_review';
    const NEW_EP_OPTION = 'aipex_csv_new_eps';
    const STATE_KEY     = 'aipex_csv_state';

    // -------------------------------------------------------------------------
    // CSV parsing
    // -------------------------------------------------------------------------

    public static function parse($content){
        $content = str_replace(["\r\n", "\r"], "\n", trim($content));
        $lines   = explode("\n", $content);
        if (!$lines) return ['error'=>'Empty file'];

        // Detect separator from header line
        $header_line = array_shift($lines);
        $sep = (substr_count($header_line, "\t") >= 3) ? "\t" : ",";

        // Parse header and normalise keys
        $headers = str_getcsv($header_line, $sep);
        $headers = array_map(function($h){ return strtolower(trim(str_replace(['"',"'"], '', $h))); }, $headers);

        // Map normalised headers to our standard keys
        $col_map = [
            'title'         => ['podcast title','title','episode title','name'],
            'series'        => ['series','show','podcast series','series name'],
            'presenter'     => ['presenter','host','podcast presenter','presenter name'],
            'soundcloud_url'=> ['soundcloud_url','soundcloud url','sc_url','sc url','url'],
            'duration'      => ['duration','length','duration in minutes','duration (minutes)'],
        ];
        $col_index = [];
        foreach ($col_map as $key => $variants) {
            foreach ($variants as $v) {
                $pos = array_search($v, $headers);
                if ($pos !== false) { $col_index[$key] = $pos; break; }
            }
        }
        if (!isset($col_index['title'])) return ['error'=>'Could not find a "Podcast Title" column. Check headers: '.implode(', ', $headers)];

        $rows = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $cells = str_getcsv($line, $sep);
            $row   = [];
            foreach ($col_index as $key => $pos) $row[$key] = trim($cells[$pos] ?? '');
            if (!empty($row['title'])) $rows[] = $row;
        }
        return ['rows'=>$rows,'count'=>count($rows),'headers'=>$headers];
    }

    public static function format_duration($decimal_minutes){
        if (!is_numeric($decimal_minutes) || (float)$decimal_minutes <= 0) return '';
        $total_secs = (int)round((float)$decimal_minutes * 60);
        $h = (int)($total_secs / 3600);
        $m = (int)(($total_secs % 3600) / 60);
        $s = $total_secs % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    // -------------------------------------------------------------------------
    // Lookup helpers (statically cached per request)
    // -------------------------------------------------------------------------

    private static function series_lookup(){
        static $c = null;
        if ($c !== null) return $c;
        $c = [];
        foreach (get_posts(['post_type'=>'aipex_series','post_status'=>'any','posts_per_page'=>-1]) as $p)
            $c[$p->ID] = $p->post_title;
        return $c;
    }

    private static function presenter_lookup(){
        static $c = null;
        if ($c !== null) return $c;
        $c = [];
        foreach (get_posts(['post_type'=>'aipex_presenter','post_status'=>'any','posts_per_page'=>-1]) as $p)
            $c[$p->ID] = $p->post_title;
        return $c;
    }

    private static function find_by_name($name, $lookup, $min_score = 82){
        $name = strtolower(trim($name));
        // Exact match first (case-insensitive)
        foreach ($lookup as $id => $title) {
            if (strtolower(trim($title)) === $name) return ['id'=>(int)$id,'title'=>$title,'score'=>100];
        }
        // Fuzzy fallback
        $best_id = 0; $best_score = 0; $best_title = '';
        foreach ($lookup as $id => $title) {
            $score = Aipex_Podcast_Fields::match_score($name, $title);
            if ($score > $best_score) { $best_score = $score; $best_id = (int)$id; $best_title = $title; }
        }
        return $best_score >= $min_score ? ['id'=>$best_id,'title'=>$best_title,'score'=>$best_score] : null;
    }

    /**
     * Find the best matching episode post for a given title.
     * Searches within $episode_ids if provided (episodes for the matched
     * series), otherwise searches the entire catalogue.
     */
    private static function find_episode($title, $episode_ids = null){
        $args = ['post_type'=>'aipex_podcast','post_status'=>'any','posts_per_page'=>-1,'fields'=>'all'];
        if ($episode_ids !== null) {
            if (empty($episode_ids)) return null;
            $args['post__in'] = $episode_ids;
        }
        $posts = get_posts($args);
        $best = null; $best_score = 0;
        foreach ($posts as $p) {
            $score = Aipex_Podcast_Fields::match_score($title, $p->post_title);
            if ($score > $best_score) { $best_score = $score; $best = $p; }
        }
        return $best ? ['post'=>$best,'score'=>$best_score] : null;
    }

    // -------------------------------------------------------------------------
    // Apply fields to a matched post — never overwrites existing values
    // -------------------------------------------------------------------------

    private static function apply_to_post($post_id, $row, $series_id, $presenter_id){
        $applied = [];

        // soundcloud_url
        if (!empty($row['soundcloud_url']) && !Aipex_Podcast_Fields::get('soundcloud_url', $post_id)) {
            update_post_meta($post_id, 'soundcloud_url', esc_url_raw($row['soundcloud_url']));
            $applied[] = 'SC URL';
        }

        // duration
        if (!empty($row['duration']) && !Aipex_Podcast_Fields::get('duration', $post_id)) {
            $fmt = self::format_duration($row['duration']);
            if ($fmt) {
                Aipex_Podcast_Fields::update('duration', $fmt, $post_id);
                $applied[] = 'duration ('.$fmt.')';
            }
        }

        // series — only if episode has none
        $existing_shows = Aipex_Podcast_Relationships::shows_for(Aipex_Podcast_Relationships::TYPE_EPISODE, $post_id);
        if ($series_id && empty($existing_shows)) {
            Aipex_Podcast_Fields::update('series', [$series_id], $post_id);
            Aipex_Podcast_Relationships::add(Aipex_Podcast_Relationships::TYPE_EPISODE, $post_id, Aipex_Podcast_Relationships::TYPE_SHOW, $series_id);
            $applied[] = 'series';
        }

        // presenter — only if episode has none
        $existing_hosts = Aipex_Podcast_Relationships::hosts_for(Aipex_Podcast_Relationships::TYPE_EPISODE, $post_id);
        if ($presenter_id && empty($existing_hosts)) {
            Aipex_Podcast_Fields::update('presenters', [$presenter_id], $post_id);
            Aipex_Podcast_Relationships::add(Aipex_Podcast_Relationships::TYPE_EPISODE, $post_id, Aipex_Podcast_Relationships::TYPE_HOST, $presenter_id);
            $applied[] = 'presenter';
        }

        return $applied;
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    /** Step 1: receive CSV content from the browser FileReader, parse and store. */
    public static function ajax_upload(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_csv_upload','nonce');

        $content = wp_unslash($_POST['csv_content'] ?? '');
        if (!$content) wp_send_json_error(['message'=>'No CSV content received.']);

        $result = self::parse($content);
        if (isset($result['error'])) wp_send_json_error(['message'=>$result['error']]);

        delete_option(self::ROWS_OPTION);
        delete_option(self::REVIEW_OPTION);
        delete_option(self::NEW_EP_OPTION);
        delete_transient(self::STATE_KEY);
        update_option(self::ROWS_OPTION, $result['rows'], false);

        wp_send_json_success(['count'=>$result['count'],'headers'=>implode(', ',$result['headers'])]);
    }

    /** Step 2: batched matching. */
    public static function ajax_match_start(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_csv_match','nonce');

        $rows = get_option(self::ROWS_OPTION, []);
        if (!$rows) wp_send_json_error(['message'=>'No CSV data. Upload a file first.']);

        delete_option(self::REVIEW_OPTION);
        delete_option(self::NEW_EP_OPTION);
        delete_transient(self::STATE_KEY);

        $state = ['total'=>count($rows),'offset'=>0,'linked'=>0,'review'=>0,'new_ep'=>0,'skipped'=>0];
        set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
        wp_send_json_success(array_merge(self::run_batch($state,false), ['log'=>['Starting — '.count($rows).' rows to match…']]));
    }

    public static function ajax_match_batch(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_csv_match','nonce');
        $state = get_transient(self::STATE_KEY);
        if (!$state || !is_array($state)) wp_send_json_error(['message'=>'No match in progress.']);
        wp_send_json_success(self::run_batch($state, true));
    }

    private static function run_batch($state, $is_continuation, $batch_size = 20){
        $rows    = get_option(self::ROWS_OPTION, []);
        $review  = get_option(self::REVIEW_OPTION, []); if (!is_array($review)) $review = [];
        $new_eps = get_option(self::NEW_EP_OPTION, []); if (!is_array($new_eps)) $new_eps = [];
        $log     = [];
        $series_lookup    = self::series_lookup();
        $presenter_lookup = self::presenter_lookup();

        foreach (array_slice($rows, $state['offset'], $batch_size) as $row) {
            $title = $row['title'] ?? '';
            if (!$title) { $state['skipped']++; continue; }

            // Find series
            $series_match    = !empty($row['series'])    ? self::find_by_name($row['series'],    $series_lookup,    82) : null;
            $presenter_match = !empty($row['presenter']) ? self::find_by_name($row['presenter'], $presenter_lookup, 82) : null;
            $series_id    = $series_match    ? $series_match['id']    : 0;
            $presenter_id = $presenter_match ? $presenter_match['id'] : 0;

            // Get episode candidates (narrowed by series if found)
            $ep_ids = $series_id ? Aipex_Podcast_Relationships::episodes_for(Aipex_Podcast_Relationships::TYPE_SHOW, $series_id) : null;
            $ep     = self::find_episode($title, $ep_ids);

            if ($ep && $ep['score'] >= 90) {
                // Confident match — apply fields (no overwrite)
                $applied = self::apply_to_post($ep['post']->ID, $row, $series_id, $presenter_id);
                $state['linked']++;
                $log[] = 'LINKED '.$ep['score'].'%: '.mb_substr($title,0,50).' → '.mb_substr($ep['post']->post_title,0,40).(count($applied)?' ['.implode(', ',$applied).']':'');

            } elseif ($ep && $ep['score'] >= 60) {
                // Uncertain — queue for review
                $review[] = [
                    'row'           => $row,
                    'episode_id'    => $ep['post']->ID,
                    'episode_title' => $ep['post']->post_title,
                    'score'         => $ep['score'],
                    'series_id'     => $series_id,
                    'series_title'  => $series_match ? $series_match['title'] : '',
                    'presenter_id'  => $presenter_id,
                    'presenter_title' => $presenter_match ? $presenter_match['title'] : '',
                ];
                $state['review']++;
                $log[] = 'REVIEW '.$ep['score'].'%: '.mb_substr($title,0,50).' → '.mb_substr($ep['post']->post_title,0,40).'?';

            } else {
                // No match — new episode candidate
                $new_eps[] = [
                    'row'           => $row,
                    'series_id'     => $series_id,
                    'series_title'  => $series_match ? $series_match['title'] : ($row['series'] ?? ''),
                    'presenter_id'  => $presenter_id,
                    'presenter_title' => $presenter_match ? $presenter_match['title'] : ($row['presenter'] ?? ''),
                    'best_score'    => $ep ? $ep['score'] : 0,
                    'best_match'    => $ep ? $ep['post']->post_title : '',
                ];
                $state['new_ep']++;
                $log[] = 'NEW EP: '.mb_substr($title,0,60).($ep?' (best: '.$ep['score'].'% '.$ep['post']->post_title.')':'');
            }
        }

        update_option(self::REVIEW_OPTION, $review, false);
        update_option(self::NEW_EP_OPTION, $new_eps, false);
        $state['offset'] += $batch_size;
        $finished = $state['offset'] >= $state['total'];
        $pct      = min(100, (int)round(100 * $state['offset'] / max(1, $state['total'])));

        if ($finished) {
            delete_transient(self::STATE_KEY);
            delete_option(self::ROWS_OPTION); // free up storage
            $log[] = 'Done. Linked: '.$state['linked'].'. Review: '.$state['review'].'. New episode candidates: '.$state['new_ep'].'. Skipped: '.$state['skipped'].'.';
        } else {
            set_transient(self::STATE_KEY, $state, HOUR_IN_SECONDS);
        }

        return [
            'done'     => min($state['offset'], $state['total']),
            'total'    => $state['total'],
            'linked'   => $state['linked'],
            'review'   => $state['review'],
            'new_ep'   => $state['new_ep'],
            'skipped'  => $state['skipped'],
            'pct'      => $pct,
            'finished' => $finished,
            'log'      => $log,
        ];
    }

    /** Apply review selections — link chosen episodes and apply their fields. */
    public static function apply_review($posted){
        $items = get_option(self::REVIEW_OPTION, []);
        $remaining = []; $done = 0;
        foreach ($items as $i => $item) {
            $sel = $posted[$i] ?? [];
            if (empty($sel['apply'])){ $remaining[] = $item; continue; }
            $ep_id        = (int)($sel['episode_id'] ?? $item['episode_id']);
            $series_id    = (int)($sel['series_id']    ?? $item['series_id']);
            $presenter_id = (int)($sel['presenter_id'] ?? $item['presenter_id']);
            if (!get_post($ep_id)){ $remaining[] = $item; continue; }
            self::apply_to_post($ep_id, $item['row'], $series_id, $presenter_id);
            $done++;
        }
        update_option(self::REVIEW_OPTION, array_values($remaining), false);
        return 'Applied '.$done.' CSV row'.($done!==1?'s':'').'. '.count($remaining).' still in review.';
    }

    /** Create a draft episode from a new-episode candidate row. */
    public static function ajax_create_draft(){
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Permission denied.'], 403);
        check_ajax_referer('aipex_csv_draft','nonce');

        $index        = (int)($_POST['index'] ?? -1);
        $series_id    = (int)($_POST['series_id'] ?? 0);
        $presenter_id = (int)($_POST['presenter_id'] ?? 0);
        $new_eps      = get_option(self::NEW_EP_OPTION, []);
        if (!isset($new_eps[$index])) wp_send_json_error(['message'=>'Item not found.']);
        $item = $new_eps[$index];
        $row  = $item['row'];

        $post_id = wp_insert_post([
            'post_type'   => 'aipex_podcast',
            'post_status' => 'draft',
            'post_title'  => sanitize_text_field($row['title'] ?? 'Untitled'),
        ]);
        if (is_wp_error($post_id)) wp_send_json_error(['message'=>$post_id->get_error_message()]);

        $sid = $series_id    ?: (int)$item['series_id'];
        $pid = $presenter_id ?: (int)$item['presenter_id'];
        // apply_to_post won't overwrite, but since it's new it will set everything
        self::apply_to_post($post_id, $row, $sid, $pid);

        unset($new_eps[$index]);
        update_option(self::NEW_EP_OPTION, array_values($new_eps), false);
        wp_send_json_success(['post_id'=>$post_id,'edit_url'=>get_edit_post_link($post_id,'raw'),'title'=>$row['title']]);
    }

    // -------------------------------------------------------------------------
    // Admin UI
    // -------------------------------------------------------------------------

    public static function render_ui(){
        $upload_nonce = wp_create_nonce('aipex_csv_upload');
        $match_nonce  = wp_create_nonce('aipex_csv_match');
        $rows_ready   = (bool)get_option(self::ROWS_OPTION, []);
        $review_count = count(get_option(self::REVIEW_OPTION, []));
        $new_ep_count = count(get_option(self::NEW_EP_OPTION, []));
        ?>
        <div id="aipex-csv-wrap" style="max-width:760px">

        <h3 style="margin-top:0">Step 1 — Upload CSV</h3>
        <p>
            <input type="file" id="aipex-csv-file" accept=".csv,.tsv,.txt" style="margin-right:8px">
            <button type="button" class="button button-primary" id="aipex-csv-upload">Upload & Parse</button>
            <span id="aipex-csv-upload-result" style="margin-left:10px;color:#646970"></span>
        </p>

        <h3>Step 2 — Match against existing episodes</h3>
        <p>
            <button type="button" class="button button-primary" id="aipex-csv-match" <?php echo $rows_ready ? '' : 'disabled'; ?>>
                <?php echo $rows_ready ? 'Start Matching' : 'Upload a file first'; ?>
            </button>
            <button type="button" class="button" id="aipex-csv-stop" style="display:none">Stop</button>
            <?php if ($review_count || $new_ep_count): ?>
            <span style="margin-left:12px">
                <?php if ($review_count) echo '<strong>'.$review_count.' uncertain</strong> in review table. '; ?>
                <?php if ($new_ep_count) echo '<strong>'.$new_ep_count.' new episode candidates</strong> below.'; ?>
            </span>
            <?php endif; ?>
            <span id="aipex-csv-status" style="margin-left:10px;color:#646970"></span>
        </p>
        <div style="height:16px;background:#f0f0f1;border-radius:20px;overflow:hidden;margin-bottom:8px">
            <div id="aipex-csv-bar" style="height:16px;width:0%;background:var(--aipex-brand,#e4005a);border-radius:20px;transition:width .3s ease"></div>
        </div>
        <pre id="aipex-csv-log" style="background:#f6f7f7;padding:10px;max-height:220px;overflow:auto;white-space:pre-wrap;font-size:12px"></pre>

        </div>
        <script>
        jQuery(function($){
            var uploadNonce=<?php echo wp_json_encode($upload_nonce); ?>;
            var matchNonce =<?php echo wp_json_encode($match_nonce); ?>;

            // Upload step
            $('#aipex-csv-upload').on('click', function(){
                var file = $('#aipex-csv-file')[0].files[0];
                if (!file) { alert('Please select a CSV file first.'); return; }
                var $btn=$(this), $r=$('#aipex-csv-upload-result');
                $btn.prop('disabled',true); $r.text('Reading file…');
                var reader = new FileReader();
                reader.onload = function(e){
                    var content = e.target.result;
                    $r.text('Uploading…');
                    $.post(ajaxurl, {action:'aipex_csv_upload', nonce:uploadNonce, csv_content:content}, function(resp){
                        $btn.prop('disabled',false);
                        if (resp && resp.success) {
                            $r.css('color','green').text('✓ '+resp.data.count+' rows parsed. Headers: '+resp.data.headers);
                            $('#aipex-csv-match').prop('disabled',false).text('Start Matching');
                        } else {
                            $r.css('color','red').text('✗ '+(resp&&resp.data&&resp.data.message?resp.data.message:'Failed'));
                        }
                    }).fail(function(xhr){ $btn.prop('disabled',false); $r.css('color','red').text('HTTP '+xhr.status); });
                };
                reader.readAsText(file);
            });

            // Match step
            var running=false;
            function log(msg){ var $l=$('#aipex-csv-log'); $l.text($l.text()?$l.text()+'\n'+msg:msg); $l.scrollTop($l[0].scrollHeight); }
            function step(action){
                if(!running) return;
                $.post(ajaxurl,{action:action,nonce:matchNonce},function(resp){
                    if(!resp||!resp.success){ running=false; $('#aipex-csv-match').prop('disabled',false); $('#aipex-csv-stop').hide(); log('ERROR: '+(resp&&resp.data&&resp.data.message?resp.data.message:'Failed')); return; }
                    var d=resp.data;
                    $('#aipex-csv-bar').css('width',(d.pct||0)+'%');
                    $.each(d.log||[],function(_,l){ log(l); });
                    $('#aipex-csv-status').text(d.done+'/'+d.total+' — linked:'+d.linked+' review:'+d.review+' new:'+d.new_ep);
                    if(d.finished){ running=false; $('#aipex-csv-match').prop('disabled',false); $('#aipex-csv-stop').hide(); $('#aipex-csv-bar').css('width','100%'); $('#aipex-csv-status').text('Complete — scroll down to see review and new episode tables.'); }
                    else setTimeout(function(){ step('aipex_csv_match_batch'); },200);
                }).fail(function(xhr){ running=false; $('#aipex-csv-match').prop('disabled',false); $('#aipex-csv-stop').hide(); log('AJAX failed: HTTP '+xhr.status); });
            }
            $('#aipex-csv-match').on('click',function(){
                if($(this).prop('disabled')) return;
                running=true; $('#aipex-csv-log').text(''); $('#aipex-csv-bar').css('width','0%'); $(this).prop('disabled',true); $('#aipex-csv-stop').show();
                step('aipex_csv_match_start');
            });
            $('#aipex-csv-stop').on('click',function(){ running=false; $(this).hide(); $('#aipex-csv-match').prop('disabled',false); $('#aipex-csv-status').text('Stopped.'); });
        });
        </script>
        <?php
    }

    public static function render_review(){
        $items = get_option(self::REVIEW_OPTION, []);
        if (!$items) return;
        $series_all    = get_posts(['post_type'=>'aipex_series','post_status'=>'any','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
        $presenter_all = get_posts(['post_type'=>'aipex_presenter','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
        echo '<hr style="margin:32px 0 16px"><h2>CSV Import — Needs Review ('.count($items).' rows)</h2>';
        echo '<p>These rows scored 60–89% on episode title match. Verify the episode selection is correct, adjust if needed, then tick and apply. Untick to skip.</p>';
        echo '<form method="post">'; wp_nonce_field('aipex_tools');
        echo '<p><button class="button button-primary" name="aipex_apply_csv_review" value="1">Apply Selected</button> ';
        echo '<button class="button" name="aipex_clear_csv_review" value="1" onclick="return confirm(\'Clear?\')">Clear List</button>';
        echo ' <label style="margin-left:16px"><input type="checkbox" id="aipex-csv-cb-all"> Select all</label></p>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:32px">✓</th><th>CSV Title</th><th style="width:55px">Score</th><th>Matched Episode</th><th>Show</th><th>Presenter</th>';
        echo '</tr></thead><tbody>';
        foreach ($items as $i => $item) {
            $ep_id  = (int)$item['episode_id'];
            $color  = $item['score'] >= 75 ? '#b45309' : '#b91c1c';
            echo '<tr>';
            echo '<td><input type="checkbox" class="aipex-csv-cb" name="csv_review['.$i.'][apply]" value="1"></td>';
            echo '<td><strong>'.esc_html($item['row']['title']).'</strong>';
            if (!empty($item['row']['soundcloud_url'])) echo '<br><small><a href="'.esc_url($item['row']['soundcloud_url']).'" target="_blank">SC link</a></small>';
            echo '</td>';
            echo '<td><strong style="color:'.esc_attr($color).'">'.esc_html($item['score']).'%</strong></td>';
            // Episode picker
            echo '<td><input type="hidden" name="csv_review['.$i.'][episode_id]" value="'.esc_attr($ep_id).'">';
            echo '<a href="'.esc_url(get_edit_post_link($ep_id)).'" target="_blank">'.esc_html($item['episode_title']).'</a></td>';
            // Series picker
            echo '<td><select name="csv_review['.$i.'][series_id]" style="width:100%"><option value="0">— None —</option>';
            foreach ($series_all as $s) echo '<option value="'.esc_attr($s->ID).'" '.selected($s->ID,(int)$item['series_id'],false).'>'.esc_html($s->post_title).'</option>';
            echo '</select></td>';
            // Presenter picker
            echo '<td><select name="csv_review['.$i.'][presenter_id]" style="width:100%"><option value="0">— None —</option>';
            foreach ($presenter_all as $p) echo '<option value="'.esc_attr($p->ID).'" '.selected($p->ID,(int)$item['presenter_id'],false).'>'.esc_html($p->post_title).'</option>';
            echo '</select></td>';
            echo '</tr>';
        }
        echo '</tbody></table></form>';
        echo '<script>jQuery(function($){ $("#aipex-csv-cb-all").on("change",function(){ $(".aipex-csv-cb").prop("checked",this.checked); }); });</script>';
    }

    public static function render_new_episodes(){
        $items = get_option(self::NEW_EP_OPTION, []);
        if (!$items) return;
        $series_all    = get_posts(['post_type'=>'aipex_series','post_status'=>'any','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
        $presenter_all = get_posts(['post_type'=>'aipex_presenter','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
        $draft_nonce   = wp_create_nonce('aipex_csv_draft');
        echo '<hr style="margin:32px 0 16px"><h2>CSV Import — No Matching Episode Found ('.count($items).')</h2>';
        echo '<p>These CSV rows had no confident episode match. Review the best-guess shown, then click <strong>Create Draft</strong> to create a new draft episode with all fields pre-filled.</p>';
        echo '<table class="widefat striped"><thead><tr><th>CSV Title</th><th>SC URL</th><th style="width:55px">Best %</th><th>Best guess</th><th>Show</th><th>Presenter</th><th style="width:110px"></th></tr></thead><tbody>';
        foreach ($items as $i => $item) {
            $row = $item['row'];
            echo '<tr id="aipex-csv-new-'.esc_attr($i).'">';
            echo '<td><strong>'.esc_html($row['title']).'</strong>';
            if (!empty($row['duration'])) echo '<br><small>'.esc_html(self::format_duration($row['duration'])).'</small>';
            echo '</td>';
            echo '<td>';
            if (!empty($row['soundcloud_url'])) echo '<a href="'.esc_url($row['soundcloud_url']).'" target="_blank" style="font-size:12px">SC link ↗</a>';
            echo '</td>';
            echo '<td style="color:#b91c1c">'.esc_html($item['best_score'] ? $item['best_score'].'%' : '—').'</td>';
            echo '<td><small style="color:#646970">'.esc_html($item['best_match'] ?: '—').'</small></td>';
            echo '<td><select class="aipex-csv-series-sel" data-index="'.esc_attr($i).'" style="width:100%"><option value="0">— None —</option>';
            foreach ($series_all as $s) echo '<option value="'.esc_attr($s->ID).'" '.selected($s->ID,(int)$item['series_id'],false).'>'.esc_html($s->post_title).'</option>';
            echo '</select></td>';
            echo '<td><select class="aipex-csv-presenter-sel" data-index="'.esc_attr($i).'" style="width:100%"><option value="0">— None —</option>';
            foreach ($presenter_all as $p) echo '<option value="'.esc_attr($p->ID).'" '.selected($p->ID,(int)$item['presenter_id'],false).'>'.esc_html($p->post_title).'</option>';
            echo '</select></td>';
            echo '<td><button type="button" class="button button-primary aipex-csv-create-draft" data-index="'.esc_attr($i).'" data-nonce="'.esc_attr($draft_nonce).'">Create Draft</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<script>jQuery(function($){ $(document).on("click",".aipex-csv-create-draft",function(){ var $btn=$(this),idx=$btn.data("index"),nonce=$btn.data("nonce"),series_id=$(".aipex-csv-series-sel[data-index=\'"+idx+"\']").val()||0,presenter_id=$(".aipex-csv-presenter-sel[data-index=\'"+idx+"\']").val()||0; $btn.prop("disabled",true).text("Creating…"); $.post(ajaxurl,{action:"aipex_csv_create_draft",nonce:nonce,index:idx,series_id:series_id,presenter_id:presenter_id},function(resp){ if(resp&&resp.success){ $("#aipex-csv-new-"+idx).html(\'<td colspan="7" style="color:green">✓ Draft created: <a href="\'+resp.data.edit_url+\'" target="_blank">\'+resp.data.title+"</a></td>"); } else { $btn.prop("disabled",false).text("Create Draft"); alert("Error: "+(resp&&resp.data&&resp.data.message?resp.data.message:"Unknown")); } }).fail(function(){ $btn.prop("disabled",false).text("Create Draft"); }); }); });</script>';
    }
}
