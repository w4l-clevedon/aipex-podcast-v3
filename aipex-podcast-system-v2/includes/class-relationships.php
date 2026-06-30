<?php
if (!defined('ABSPATH')) exit;

/**
 * Single relationship graph for the plugin, backed by a real join table
 * instead of meta_query LIKE scans against serialized ACF data.
 *
 * Two kinds of edges:
 *  - DIRECT edges: come straight from an ACF relationship field (episode
 *    belongs to a show, episode has hosts/guests/sponsors, show has
 *    sponsors). These are kept in sync automatically whenever a post is
 *    saved — see sync_post().
 *  - DERIVED edges: pairs that have no ACF field of their own (e.g. a host
 *    has no direct "shows" field — a host's shows are whichever shows their
 *    episodes belong to). These are computed on read from the direct edges
 *    already in the table, not stored separately, so there's nothing extra
 *    to keep in sync.
 *
 * Nothing else in the plugin should query ACF relationship data directly —
 * every entity-to-entity lookup goes through this class.
 */
class Aipex_Podcast_Relationships {
    const TYPE_EPISODE = 'episode';
    const TYPE_SHOW = 'show';
    const TYPE_HOST = 'host';
    const TYPE_GUEST = 'guest';
    const TYPE_SPONSOR = 'sponsor';

    public static function post_type_for($type){
        $map = [
            self::TYPE_EPISODE => 'aipex_podcast',
            self::TYPE_SHOW => 'aipex_series',
            self::TYPE_HOST => 'aipex_presenter',
            self::TYPE_GUEST => 'aipex_guest',
            self::TYPE_SPONSOR => 'aipex_sponsor',
        ];
        return $map[$type] ?? '';
    }

    public static function entity_type_for_post_type($post_type){
        static $flipped = null;
        if ($flipped === null) $flipped = array_flip([
            self::TYPE_EPISODE => 'aipex_podcast',
            self::TYPE_SHOW => 'aipex_series',
            self::TYPE_HOST => 'aipex_presenter',
            self::TYPE_GUEST => 'aipex_guest',
            self::TYPE_SPONSOR => 'aipex_sponsor',
        ]);
        return $flipped[$post_type] ?? '';
    }

    private static function table(){
        global $wpdb;
        return $wpdb->prefix . 'aipex_relationships';
    }

    /**
     * Creates the join table if it doesn't already exist. Safe to call on
     * every page load — dbDelta() no-ops when the schema already matches.
     */
    public static function maybe_create_table(){
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            from_type VARCHAR(20) NOT NULL,
            from_id BIGINT UNSIGNED NOT NULL,
            to_type VARCHAR(20) NOT NULL,
            to_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY aipex_edge (from_type,from_id,to_type,to_id),
            KEY aipex_from (from_type,from_id),
            KEY aipex_to (to_type,to_id)
        ) {$charset_collate};";
        dbDelta($sql);
    }

    /** Adds an edge if it doesn't already exist (idempotent). */
    public static function add($from_type, $from_id, $to_type, $to_id){
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . self::table() . " (from_type, from_id, to_type, to_id, created_at) VALUES (%s,%d,%s,%d,%s)",
            $from_type, (int)$from_id, $to_type, (int)$to_id, current_time('mysql')
        ));
    }

    /** Removes every direct edge between this entity and a given target type — used before re-syncing on save. */
    public static function clear_direct_edges($entity_type, $entity_id, $target_type){
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE (from_type=%s AND from_id=%d AND to_type=%s) OR (to_type=%s AND to_id=%d AND from_type=%s)",
            $entity_type, (int)$entity_id, $target_type, $entity_type, (int)$entity_id, $target_type
        ));
    }

    /** Direct, one-hop lookup: every entity of $target_type directly linked to $entity. */
    public static function direct($entity_type, $entity_id, $target_type){
        global $wpdb;
        $table = self::table();
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT to_id FROM {$table} WHERE from_type=%s AND from_id=%d AND to_type=%s
             UNION
             SELECT from_id FROM {$table} WHERE to_type=%s AND to_id=%d AND from_type=%s",
            $entity_type, (int)$entity_id, $target_type,
            $entity_type, (int)$entity_id, $target_type
        ));
        return array_values(array_unique(array_map('intval', $ids ?: [])));
    }

    /**
     * Derived, two-hop lookup: every entity of $target_type connected to
     * $entity by way of a shared episode (e.g. a host's shows, a sponsor's
     * guests). Used automatically by the *_for() methods below whenever
     * there's no direct edge between the two types.
     */
    private static function via_episode($entity_type, $entity_id, $target_type){
        $episode_ids = self::direct($entity_type, $entity_id, self::TYPE_EPISODE);
        if ($entity_type === self::TYPE_EPISODE) $episode_ids = [(int)$entity_id];
        if (!$episode_ids) return [];
        $all = [];
        foreach ($episode_ids as $eid) $all = array_merge($all, self::direct(self::TYPE_EPISODE, $eid, $target_type));
        return array_values(array_unique($all));
    }

    /** True if these two entity types ever have a direct ACF-backed edge. */
    private static function has_direct_relationship($a, $b){
        $direct_pairs = [
            self::TYPE_EPISODE.'|'.self::TYPE_SHOW, self::TYPE_EPISODE.'|'.self::TYPE_HOST,
            self::TYPE_EPISODE.'|'.self::TYPE_GUEST, self::TYPE_EPISODE.'|'.self::TYPE_SPONSOR,
            self::TYPE_SHOW.'|'.self::TYPE_SPONSOR,
        ];
        return in_array($a.'|'.$b, $direct_pairs, true) || in_array($b.'|'.$a, $direct_pairs, true);
    }

    /** General-purpose entry point: every entity of $target_type related to $entity, direct or derived. */
    public static function related($entity_type, $entity_id, $target_type){
        if ($entity_type === $target_type) return [];
        if (self::has_direct_relationship($entity_type, $target_type)) {
            return self::direct($entity_type, $entity_id, $target_type);
        }
        return self::via_episode($entity_type, $entity_id, $target_type);
    }

    public static function episodes_for($entity_type, $entity_id){ return self::related($entity_type, $entity_id, self::TYPE_EPISODE); }
    public static function shows_for($entity_type, $entity_id){ return self::related($entity_type, $entity_id, self::TYPE_SHOW); }
    public static function hosts_for($entity_type, $entity_id){ return self::related($entity_type, $entity_id, self::TYPE_HOST); }
    public static function guests_for($entity_type, $entity_id){ return self::related($entity_type, $entity_id, self::TYPE_GUEST); }
    public static function sponsors_for($entity_type, $entity_id){ return self::related($entity_type, $entity_id, self::TYPE_SPONSOR); }

    /**
     * Re-derives every DIRECT edge for a single post from its current ACF
     * field values and writes it to the table. Called automatically on
     * save (see class-core.php) and by the one-time migration below.
     */
    public static function sync_post($post_id){
        $post_type = get_post_type($post_id);
        $entity_type = self::entity_type_for_post_type($post_type);
        if (!$entity_type) return;

        if ($entity_type === self::TYPE_EPISODE) {
            self::sync_edge($entity_type, $post_id, self::TYPE_SHOW, Aipex_Podcast_Fields::ids('series', $post_id));
            self::sync_edge($entity_type, $post_id, self::TYPE_HOST, Aipex_Podcast_Fields::ids('presenters', $post_id));
            self::sync_edge($entity_type, $post_id, self::TYPE_GUEST, Aipex_Podcast_Fields::ids('guests', $post_id));
            self::sync_edge($entity_type, $post_id, self::TYPE_SPONSOR, Aipex_Podcast_Fields::ids('sponsors', $post_id));
        } elseif ($entity_type === self::TYPE_SHOW) {
            self::sync_edge($entity_type, $post_id, self::TYPE_SPONSOR, Aipex_Podcast_Fields::ids('series_sponsors', $post_id));
        }
        // Hosts, guests and sponsors have no direct fields of their own —
        // their edges are written from the episode/show side above.
    }

    private static function sync_edge($entity_type, $entity_id, $target_type, $target_ids){
        self::clear_direct_edges($entity_type, $entity_id, $target_type);
        foreach ((array)$target_ids as $tid) {
            if ((int)$tid > 0) self::add($entity_type, $entity_id, $target_type, (int)$tid);
        }
    }

    /**
     * One-time backfill: rebuilds the whole table from existing ACF data.
     * Safe to run repeatedly (sync_post() is idempotent per post).
     * Only suitable for small catalogues — use migrate_batch() for anything
     * over a few hundred posts, since this runs in a single PHP execution.
     */
    public static function migrate_all(){
        self::maybe_create_table();
        $count = 0;
        foreach (['aipex_podcast', 'aipex_series'] as $post_type) {
            $ids = get_posts(['post_type' => $post_type, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']);
            foreach ($ids as $id) { self::sync_post($id); $count++; }
        }
        return $count;
    }

    /**
     * Batched AJAX sync — processes $batch_size posts per call, resuming
     * from the stored offset on each subsequent call. Built for 1,000+ post
     * catalogues where migrate_all() would time out in a single request.
     *
     * State is stored in a transient so it survives across requests.
     * Returns an array the AJAX handler passes straight to wp_send_json_success().
     */
    public static function migrate_batch($batch_size=50, $reset=false){
        self::maybe_create_table();
        $state_key = 'aipex_rel_sync_state';

        if ($reset || !($state = get_transient($state_key)) || !is_array($state)) {
            // Build the full ordered list of IDs once and store it
            $all = [];
            foreach (['aipex_podcast', 'aipex_series'] as $pt) {
                $ids = get_posts(['post_type'=>$pt,'post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC']);
                $all = array_merge($all, $ids);
            }
            $state = ['total'=>count($all),'offset'=>0,'done'=>0,'all'=>$all];
        }

        $slice = array_slice($state['all'], $state['offset'], $batch_size);
        foreach ($slice as $id) self::sync_post($id);

        $state['done'] += count($slice);
        $state['offset'] += $batch_size;
        $finished = $state['offset'] >= $state['total'];

        if ($finished) {
            delete_transient($state_key);
        } else {
            set_transient($state_key, $state, HOUR_IN_SECONDS);
        }

        return [
            'done'     => $state['done'],
            'total'    => $state['total'],
            'finished' => $finished,
            'pct'      => $state['total'] > 0 ? min(100, (int)round(100 * $state['done'] / $state['total'])) : 100,
        ];
    }
}
