<?php
/**
 * Change journal: what a tool call actually changed, so Restore session can put exactly that back.
 *
 * While a write tool runs, the journal watches every SQL write the call makes (the `query` filter runs just before
 * each one) and, the first time a write aims at something, reads how it is: a post, term, user or comment row, the
 * values of one meta key on one object, one option, the terms of one object in one taxonomy. When the call ends it
 * reads them again and keeps only what really differs — one changed alt text is one value, not a copy of the post.
 * Following the SQL rather than WordPress's hooks also catches the writes WordPress makes directly: children moved up
 * when a page is deleted, posts handed to another author, term counts.
 *
 * Undo (restore()) puts the kept values back only where the call's own result is still there. Something a person
 * changed since is left as it is and reported (the user's rule, 2026-11-26: Undo never overwrites a person's edit).
 * It writes the stored bytes back directly, without firing hooks: what plugins did in reaction to the call is in the
 * journal too and is put back with the rest — re-firing them would make them react a second time. What they derive
 * and keep elsewhere (term counts, Yoast's index, WooCommerce's lookup table, Elementor's CSS, caches) is rebuilt.
 *
 * What it can't put back makes the call's undo incomplete, with the reason: a write to a table it doesn't cover, a
 * write it can't follow, an email sent, a file deleted. Requests to outside services are listed but don't count
 * against it: they don't change the site.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Change_Journal {

    /**
     * Tools proven by tests/test-undo-journal.php: each one run, its session restored, and the database is as it was.
     * Listed as reversible to AICOMBase. A tool joins the list only with a passing case there.
     */
    const VERIFIED = [
        'wp.posts.create', 'wp.posts.update', 'wp.posts.trash', 'wp.posts.restore', 'wp.posts.delete',
        'wp.terms.create', 'wp.terms.update', 'wp.terms.delete', 'wp.terms.assign_to_post', 'wp.terms.remove_from_post',
        'wp.meta.set', 'wp.meta.set_many', 'wp.meta.delete', 'wp.options.set', 'wp.security.xmlrpc.set',
        'wp.menus.create', 'wp.menus.rename', 'wp.menus.delete', 'wp.menus.items.add', 'wp.menus.items.update',
        'wp.menus.items.remove', 'wp.menus.locations.set',
        'media.update_meta', 'media.attach_to_post', 'media.set_featured', 'a11y.set_image_alt',
        'wp.users.create', 'wp.users.update', 'wp.users.set_role', 'wp.users.add_role', 'wp.users.remove_role',
        'wp.roles.create', 'wp.roles.update_caps', 'wp.roles.delete',
        'wc.products.create', 'wc.products.update', 'wc.products.trash', 'wc.products.restore', 'wc.products.delete',
        'wc.products.set_price', 'wc.products.set_stock', 'wc.products.assign_terms',
        'wc.categories.create', 'wc.categories.update', 'wc.categories.delete', 'wc.settings.set',
        'pll.post.set_language', 'pll.post.link_translation', 'pll.post.unlink_translation', 'pll.term.set_language',
        'pll.term.link_translation', 'pll.string.set', 'pll.create_bilingual_pair',
        'seopress.post.set', 'seopress.post.social.set', 'seopress.term.set',
        'yoast.post.set', 'yoast.post.social.set', 'yoast.term.set',
        'ecs.color_schemes.save', 'ecs.color_schemes.delete', 'ecs.font_schemes.save', 'ecs.font_schemes.delete',
        'ecs.custom_css.save', 'ecs.custom_css.delete', 'ecs.custom_looks.save', 'ecs.custom_looks.delete',
        'ecs.custom_looks.toggle',
        'elementor.widget.update_field', 'elementor.page.bulk_update_texts', 'elementor.page.create_from_template',
        'elementor.template.set_conditions',
    ];

    /** Write tools not journaled: nothing a person would undo (a session, a cache, CSS Elementor regenerates). */
    private const NOT_JOURNALED = [ 'session.open', 'session.close', 'wp.cache.purge', 'elementor.page.regenerate_assets' ];

    /** Tools that change something outside the database, with the reason their undo is incomplete. */
    private const NOT_COVERED = [
        'files.upload'          => "It writes a file on disk, which Undo doesn't put back.",
        'files.delete'          => "It deletes a file on disk, which Undo doesn't bring back.",
        'wp.plugins.update_all' => "Updated plugin code isn't put back by Undo.",
    ];

    /** WordPress tables the journal follows (without the table prefix). */
    private const TRACKED = [ 'posts', 'postmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'options', 'users', 'usermeta', 'comments', 'commentmeta' ];

    /** Tables that only mirror data kept elsewhere and are rebuilt by their plugin, or AICOM's own bookkeeping. */
    private const DERIVED = '/^(wc_product_meta_lookup|wc_product_attributes_lookup|wc_category_lookup|wc_reserved_stock|woocommerce_sessions|actionscheduler_\w+|yoast_\w+|e_events|clautron_events|aicom_(logs|backups|base_events|idempotency_keys))$/';

    /** Bookkeeping and caches their plugin rebuilds: never journaled. */
    private const SKIP_META       = [ '_edit_lock', 'session_tokens', '_elementor_css', '_elementor_element_cache', '_elementor_page_assets', 'product_count_product_cat', 'product_count_product_tag' ];
    private const SKIP_OPTION     = '/^(_site)?_transient_|^(cron|rewrite_rules)$|^action_scheduler_|_children(_all)?$|\\.lock$/'; // {taxonomy}_children(_all): caches of the term tree
    private const SKIP_POST_TYPES = [ 'revision', 'oembed_cache' ];

    /** Columns left out: kept up to date by WordPress itself (a term's count), or never copied (password data). */
    private const SKIP_COLUMNS = [ 'term' => [ 'count' ], 'user' => [ 'user_pass', 'user_activation_key' ] ];

    /** What tells whether a post the call created was changed since (not post_modified: plugins touch it). */
    private const POST_IDENTITY = [ 'post_author', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order', 'post_type', 'post_password' ];

    private const CREATED_TABLE = [ 'post' => 'posts', 'term' => 'terms', 'user' => 'users', 'comment' => 'comments' ];

    private const MAX_ENTRIES = 5000;
    private const MAX_PAYLOAD = 8388608; // 8 MB of JSON per call

    /** The running call's journal, or null. */
    private static ?array $s = null;

    /** The last call's writes it couldn't follow (SQL, cut short) — for tests and debugging, never sent anywhere. */
    public static array $unfollowed = [];

    // ── recording ─────────────────────────────────────────────────────────

    /** Whether this tool's calls are journaled: write tools, except bridged abilities (they return their own undo). */
    public static function covers( string $tool_name, array $meta ): bool {
        return ! in_array( (string) ( $meta['class'] ?? 'read' ), AICOM_Tool_Registry::READ_ONLY_CLASSES, true )
            && empty( $meta['ability'] )
            && ! in_array( $tool_name, self::NOT_JOURNALED, true );
    }

    public static function start( string $tool_name ): void {
        self::$unfollowed = [];
        self::$s = [
            'tool' => $tool_name, 'entries' => [], 'index' => [], 'skip' => [], 'created' => [], 'pending' => [],
            'reasons' => [], 'outside' => [], 'tax_of' => [], 'busy' => false, 'hooks' => [],
        ];
        if ( isset( self::NOT_COVERED[ $tool_name ] ) ) {
            self::reason( self::NOT_COVERED[ $tool_name ] );
        }
        $hooks = [
            [ 'query', [ self::class, 'on_query' ], PHP_INT_MAX, 1 ], // after wpdb removes its placeholder escapes (priority 0)
            [ 'wp_insert_post', static function ( $id, $post = null, $update = false ) { if ( ! $update ) { self::created( 'post', (int) $id ); } }, 1, 3 ],
            [ 'add_attachment', static function ( $id ) { self::created( 'post', (int) $id ); }, 1, 1 ],
            [ 'created_term', static function ( $id ) { self::created( 'term', (int) $id ); }, 1, 1 ],
            [ 'user_register', static function ( $id ) { self::created( 'user', (int) $id ); }, 1, 1 ],
            [ 'wp_insert_comment', static function ( $id ) { self::created( 'comment', (int) $id ); }, 1, 1 ],
            [ 'pre_wp_mail', static function ( $r ) { self::reason( "It sent an email, which Undo can't take back." ); return $r; }, 1, 1 ],
            [ 'wp_delete_file', static function ( $file ) { self::deleted_file( (string) $file ); return $file; }, 1, 1 ],
            [ 'pre_http_request', static function ( $pre, $args = [], $url = '' ) { self::outside( (string) $url ); return $pre; }, 1, 3 ],
        ];
        foreach ( $hooks as [ $name, $cb, $priority, $args ] ) {
            add_filter( $name, $cb, $priority, $args );
            self::$s['hooks'][] = [ $name, $cb, $priority ];
        }
    }

    /**
     * End the call's journal: what changed, before and after.
     *
     * @return array{tool:string, entries:array, reasons:string[], outside:string[]}
     */
    public static function stop(): array {
        $s = self::$s;
        if ( $s === null ) {
            return [ 'tool' => '', 'entries' => [], 'reasons' => [], 'outside' => [] ];
        }
        foreach ( $s['hooks'] as [ $name, $cb, $priority ] ) {
            remove_filter( $name, $cb, $priority );
        }
        self::$s = null;

        $entries = [];
        foreach ( $s['entries'] as $e ) {
            $e = self::settle( $e, $s['created'] );
            if ( $e === null ) {
                continue;
            }
            if ( $e['k'] === 'user' && $e['op'] === 'delete' ) {
                // Its row would come back without the password: not brought back at all.
                $s['reasons'][] = "A deleted user can't be brought back by Undo.";
                continue;
            }
            $entries[] = $e;
        }
        foreach ( $s['pending'] as $table => $n ) {
            if ( $n > 0 ) {
                $s['reasons'][] = "It added rows to the {$table} table that Undo doesn't know about.";
            }
        }
        return [
            'tool'    => $s['tool'],
            'entries' => $entries,
            'reasons' => array_values( array_unique( $s['reasons'] ) ),
            'outside' => array_keys( $s['outside'] ),
        ];
    }

    /**
     * Keep a call's journal with the session's backups (target_type "journal") and say what Undo can do about it.
     *
     * @param int[] $supersedes Router snapshots of the same call; Restore session skips them once this is restored.
     * @return array The call's `undo`: {via, complete, changes, backup_id?, reasons?, outside?}.
     */
    public static function record( array $j, array $key_record, string $request_id, array $supersedes = [] ): array {
        global $wpdb;
        $undo = [ 'via' => 'restore_session', 'complete' => ! $j['reasons'], 'changes' => count( $j['entries'] ) ];
        if ( $j['entries'] ) {
            $payload = wp_json_encode( [
                'v'          => 1,
                'tool'       => $j['tool'],
                'entries'    => self::encode( $j['entries'] ),
                'reasons'    => $j['reasons'],
                'supersedes' => array_values( array_filter( array_map( 'intval', $supersedes ) ) ),
            ] );
            if ( ! $payload || strlen( $payload ) > self::MAX_PAYLOAD ) {
                $j['reasons'][]   = 'Its changes are too large for Undo to keep.';
                $undo['complete'] = false;
            } else {
                $labels = array_slice( array_values( array_unique( array_map( static fn( $e ) => self::target( $e ), $j['entries'] ) ) ), 0, 20 );
                $wpdb->insert( $wpdb->prefix . 'aicom_backups', [
                    'created_at'    => current_time( 'mysql', true ),
                    'request_id'    => substr( $request_id, 0, 64 ),
                    'session_id'    => AICOM_Tool_Router::$current_session_id ?: null,
                    'api_key_id'    => (int) ( $key_record['id'] ?? 0 ),
                    'tool_name'     => $j['tool'],
                    'target_type'   => 'journal',
                    'target_id'     => substr( (string) ( $labels[0] ?? $j['tool'] ), 0, 100 ),
                    'manifest_json' => wp_json_encode( [ 'tool' => $j['tool'], 'changes' => count( $j['entries'] ), 'complete' => ! $j['reasons'], 'targets' => $labels ] ),
                    'payload_json'  => $payload,
                ] );
                if ( $wpdb->insert_id ) {
                    $undo['backup_id'] = (int) $wpdb->insert_id;
                } else {
                    $j['reasons'][]   = "Undo couldn't save its record of these changes.";
                    $undo['complete'] = false;
                }
            }
        }
        if ( $j['reasons'] ) {
            $undo['reasons'] = array_values( array_unique( $j['reasons'] ) );
        }
        if ( $j['outside'] ) {
            $undo['outside'] = $j['outside'];
        }
        return $undo;
    }

    // ── following the SQL ─────────────────────────────────────────────────

    /** `query` filter: before each write runs, read what it aims at. */
    public static function on_query( $query ) {
        if ( self::$s === null || self::$s['busy'] || ! is_string( $query )
            || ! preg_match( '/^\s*(INSERT|REPLACE|UPDATE|DELETE|TRUNCATE|DROP|ALTER|CREATE|RENAME)\b/i', $query, $m ) ) {
            return $query;
        }
        self::$s['busy'] = true; // the reads below are queries too
        try {
            self::follow( strtoupper( $m[1] ), $query );
        } catch ( Throwable $e ) {
            self::unfollowed( $query, "Undo couldn't follow one of its database writes." );
        } finally {
            if ( self::$s !== null ) {
                self::$s['busy'] = false;
            }
        }
        return $query;
    }

    private static function follow( string $verb, string $query ): void {
        global $wpdb;
        [ $sql, $lits, $raws ] = self::tokenize( $query );
        $table = self::table_name( $verb, $sql );
        $short = ( $table !== '' && strpos( $table, $wpdb->prefix ) === 0 ) ? substr( $table, strlen( $wpdb->prefix ) ) : $table;

        if ( ! in_array( $short, self::TRACKED, true ) ) {
            if ( $table === '' ) {
                self::unfollowed( $query, "It changed the database in a way Undo can't follow." );
            } elseif ( ! preg_match( self::DERIVED, $short ) && stripos( $sql, 'TEMPORARY' ) === false
                && ! ( in_array( $verb, [ 'UPDATE', 'DELETE' ], true ) && self::matches_nothing( $table, $sql, $raws ) ) ) {
                self::unfollowed( $query, "It wrote to the {$table} table, which Undo doesn't cover." );
            }
            return;
        }
        // WordPress's own clean-up of expired transients (a multi-table DELETE on options).
        if ( $short === 'options' && strpos( $query, '_transient' ) !== false && ! preg_match( '/option_name`?\s*(=|IN)/i', $sql ) ) {
            return;
        }
        if ( ! in_array( $verb, [ 'INSERT', 'REPLACE', 'UPDATE', 'DELETE' ], true ) ) {
            self::unfollowed( $query, "It changed the structure of the {$table} table, which Undo doesn't put back." );
            return;
        }
        if ( $verb === 'INSERT' || $verb === 'REPLACE' ) {
            $rows = self::insert_rows( $sql, $lits );
            if ( $rows === null ) {
                self::unfollowed( $query, "It added to the {$table} table in a way Undo can't follow." );
                return;
            }
            self::follow_insert( $short, $rows );
            return;
        }
        if ( ! self::follow_change( $short, self::where( $sql, $lits ) ) ) {
            self::unfollowed( $query, "It changed the {$table} table in a way Undo can't follow." );
        }
    }

    private static function follow_insert( string $short, array $rows ): void {
        foreach ( $rows as $r ) {
            switch ( $short ) {
                case 'posts':
                case 'terms':
                case 'term_taxonomy':
                case 'users':
                case 'comments':
                    // A new row: the created_* hook that follows says which one. REPLACE with an id may overwrite one.
                    self::$s['pending'][ $short ] = ( self::$s['pending'][ $short ] ?? 0 ) + 1;
                    $kind = [ 'posts' => 'post', 'users' => 'user', 'comments' => 'comment' ][ $short ] ?? '';
                    $pk   = [ 'posts' => 'ID', 'users' => 'ID', 'comments' => 'comment_ID' ][ $short ] ?? '';
                    if ( $kind && ! empty( $r[ $pk ] ) ) {
                        self::touch_row( $kind, (int) $r[ $pk ] );
                    }
                    break;
                case 'options':
                    self::touch_option( (string) ( $r['option_name'] ?? '' ) );
                    break;
                case 'term_relationships':
                    self::touch_rel( (int) ( $r['object_id'] ?? 0 ), self::tax_of( (int) ( $r['term_taxonomy_id'] ?? 0 ) ) );
                    break;
                default: // the four meta tables
                    $type = substr( $short, 0, -4 );
                    self::touch_meta( $type, (int) ( $r[ $type . '_id' ] ?? 0 ), (string) ( $r['meta_key'] ?? '' ) );
            }
        }
    }

    /** An UPDATE or DELETE: read every row its WHERE can reach. False when the WHERE names nothing it knows. */
    private static function follow_change( string $short, array $where ): bool {
        global $wpdb;
        $in   = static fn( string $col ): array => $where[ $col ] ?? [];
        $list = static fn( array $v ): string => implode( ',', array_map( static fn( $x ) => "'" . esc_sql( (string) $x ) . "'", $v ) );

        switch ( $short ) {
            case 'posts':
                if ( $in( 'ID' ) ) {
                    array_map( static fn( $id ) => self::touch_row( 'post', (int) $id ), $in( 'ID' ) );
                    return true;
                }
                foreach ( [ 'post_parent', 'post_author' ] as $col ) { // children moved up, posts handed to another author
                    if ( $in( $col ) ) {
                        $ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE $col IN (" . $list( $in( $col ) ) . ')' );
                        array_map( static fn( $id ) => self::touch_row( 'post', (int) $id ), $ids );
                        return true;
                    }
                }
                return false;

            case 'postmeta':
            case 'termmeta':
            case 'usermeta':
            case 'commentmeta':
                $type = substr( $short, 0, -4 );
                [ $t, $col, $pk ] = self::meta_table( $type );
                if ( $in( $pk ) ) {
                    $rows = $wpdb->get_results( "SELECT $col AS o, meta_key AS k FROM $t WHERE $pk IN (" . $list( $in( $pk ) ) . ')', ARRAY_A );
                } elseif ( $in( $col ) ) {
                    $keys = $in( 'meta_key' );
                    $rows = $keys
                        ? array_merge( ...array_map( static fn( $o ) => array_map( static fn( $k ) => [ 'o' => $o, 'k' => $k ], $keys ), $in( $col ) ) )
                        : $wpdb->get_results( "SELECT DISTINCT $col AS o, meta_key AS k FROM $t WHERE $col IN (" . $list( $in( $col ) ) . ')', ARRAY_A );
                } elseif ( $in( 'meta_key' ) ) {
                    $rows = $wpdb->get_results( "SELECT DISTINCT $col AS o, meta_key AS k FROM $t WHERE meta_key IN (" . $list( $in( 'meta_key' ) ) . ') LIMIT ' . self::MAX_ENTRIES, ARRAY_A );
                } else {
                    return false;
                }
                foreach ( (array) $rows as $r ) {
                    self::touch_meta( $type, (int) $r['o'], (string) $r['k'] );
                }
                return true;

            case 'options':
                if ( ! $in( 'option_name' ) ) {
                    return false;
                }
                array_map( static fn( $n ) => self::touch_option( (string) $n ), $in( 'option_name' ) );
                return true;

            case 'terms':
                if ( ! $in( 'term_id' ) ) {
                    return false;
                }
                array_map( static fn( $id ) => self::touch_row( 'term', (int) $id ), $in( 'term_id' ) );
                return true;

            case 'term_taxonomy':
                if ( $in( 'term_taxonomy_id' ) ) {
                    $ids = $wpdb->get_col( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN (" . $list( $in( 'term_taxonomy_id' ) ) . ')' );
                } elseif ( $in( 'term_id' ) ) {
                    $ids = $in( 'term_id' );
                } elseif ( $in( 'parent' ) ) { // children moved up when their parent is deleted
                    $ids = $wpdb->get_col( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE parent IN (" . $list( $in( 'parent' ) ) . ')' );
                } else {
                    return false;
                }
                array_map( static fn( $id ) => self::touch_row( 'term', (int) $id ), $ids );
                return true;

            case 'term_relationships':
                if ( $in( 'object_id' ) ) {
                    $taxes = $in( 'term_taxonomy_id' )
                        ? array_map( static fn( $tt ) => self::tax_of( (int) $tt ), $in( 'term_taxonomy_id' ) )
                        : $wpdb->get_col( "SELECT DISTINCT tt.taxonomy FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id IN (" . $list( $in( 'object_id' ) ) . ')' );
                    foreach ( $in( 'object_id' ) as $o ) {
                        foreach ( array_unique( $taxes ) as $tax ) {
                            self::touch_rel( (int) $o, (string) $tax );
                        }
                    }
                    return true;
                }
                if ( $in( 'term_taxonomy_id' ) ) {
                    $rows = $wpdb->get_results( "SELECT tr.object_id AS o, tt.taxonomy AS t FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.term_taxonomy_id IN (" . $list( $in( 'term_taxonomy_id' ) ) . ') LIMIT ' . self::MAX_ENTRIES, ARRAY_A );
                    foreach ( (array) $rows as $r ) {
                        self::touch_rel( (int) $r['o'], (string) $r['t'] );
                    }
                    return true;
                }
                return false;

            case 'users':
                if ( ! $in( 'ID' ) ) {
                    return false;
                }
                array_map( static fn( $id ) => self::touch_row( 'user', (int) $id ), $in( 'ID' ) );
                return true;

            case 'comments':
                if ( $in( 'comment_ID' ) ) {
                    array_map( static fn( $id ) => self::touch_row( 'comment', (int) $id ), $in( 'comment_ID' ) );
                    return true;
                }
                foreach ( [ 'comment_post_ID', 'comment_parent', 'user_id' ] as $col ) {
                    if ( $in( $col ) ) {
                        $ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE $col IN (" . $list( $in( $col ) ) . ')' );
                        array_map( static fn( $id ) => self::touch_row( 'comment', (int) $id ), $ids );
                        return true;
                    }
                }
                return false;
        }
        return false;
    }

    // ── what a write aims at, read before it runs ─────────────────────────

    private static function add( string $key, array $entry ): void {
        if ( isset( self::$s['index'][ $key ] ) ) {
            return;
        }
        if ( count( self::$s['entries'] ) >= self::MAX_ENTRIES ) {
            self::reason( 'It changed more than Undo keeps for one call.' );
            return;
        }
        self::$s['index'][ $key ] = count( self::$s['entries'] );
        self::$s['entries'][]     = $entry;
    }

    private static function touch_row( string $kind, int $id ): void {
        if ( $id <= 0 || isset( self::$s['index'][ "$kind|$id" ] ) || isset( self::$s['skip'][ "$kind|$id" ] ) ) {
            return;
        }
        $row = self::read_row( $kind, $id );
        if ( $kind === 'post' && $row && in_array( $row['post_type'], self::SKIP_POST_TYPES, true ) ) {
            self::$s['skip'][ "post|$id" ] = true;
            return;
        }
        self::add( "$kind|$id", [ 'k' => $kind, 'id' => $id, 'b' => $row ] );
    }

    private static function touch_meta( string $type, int $id, string $key ): void {
        if ( $id <= 0 || $key === '' || in_array( $key, self::SKIP_META, true ) || isset( self::$s['index'][ "meta|$type|$id|$key" ] ) ) {
            return;
        }
        if ( $type === 'post' && ( isset( self::$s['skip'][ "post|$id" ] ) || in_array( get_post_type( $id ), self::SKIP_POST_TYPES, true ) ) ) {
            return;
        }
        self::add( "meta|$type|$id|$key", [ 'k' => 'meta', 't' => $type, 'id' => $id, 'key' => $key, 'b' => self::read_meta( $type, $id, $key ) ] );
    }

    private static function touch_option( string $name ): void {
        if ( $name === '' || preg_match( self::SKIP_OPTION, $name ) || isset( self::$s['index'][ "option|$name" ] ) ) {
            return;
        }
        self::add( "option|$name", [ 'k' => 'option', 'name' => $name, 'b' => self::read_option( $name ) ] );
    }

    private static function touch_rel( int $id, string $tax ): void {
        if ( $id <= 0 || $tax === '' || isset( self::$s['index'][ "rel|$id|$tax" ] ) || isset( self::$s['skip'][ "post|$id" ] ) ) {
            return;
        }
        self::add( "rel|$id|$tax", [ 'k' => 'rel', 'id' => $id, 'tax' => $tax, 'b' => self::read_rel( $id, $tax ) ] );
    }

    /** A created_* hook: the row its INSERT made. Whatever was read for it before then was the row being made. */
    private static function created( string $kind, int $id ): void {
        if ( self::$s === null || $id <= 0 ) {
            return;
        }
        foreach ( $kind === 'term' ? [ 'terms', 'term_taxonomy' ] : [ self::CREATED_TABLE[ $kind ] ] as $table ) {
            self::$s['pending'][ $table ] = ( self::$s['pending'][ $table ] ?? 0 ) - 1;
        }
        if ( $kind === 'post' && in_array( get_post_type( $id ), self::SKIP_POST_TYPES, true ) ) {
            self::$s['skip'][ "post|$id" ] = true;
            return;
        }
        $key = "$kind|$id";
        if ( isset( self::$s['index'][ $key ] ) ) {
            self::$s['entries'][ self::$s['index'][ $key ] ]['b'] = null;
        } else {
            self::add( $key, [ 'k' => $kind, 'id' => $id, 'b' => null ] );
        }
        self::$s['created'][ $key ] = true;
    }

    private static function deleted_file( string $file ): void {
        $tmp = wp_normalize_path( get_temp_dir() );
        if ( self::$s !== null && strpos( wp_normalize_path( $file ), $tmp ) !== 0 ) { // temp files the tool made for itself don't count
            self::reason( 'It deleted a file (' . wp_basename( $file ) . "), which Undo doesn't bring back." );
        }
    }

    private static function outside( string $url ): void {
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( self::$s !== null && $host !== '' && $host !== (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) {
            self::$s['outside'][ $host ] = true;
        }
    }

    private static function unfollowed( string $query, string $why ): void {
        self::$unfollowed[] = substr( $query, 0, 300 );
        self::reason( $why );
    }

    private static function reason( string $why ): void {
        if ( self::$s !== null ) {
            self::$s['reasons'][] = $why;
        }
    }

    private static function tax_of( int $tt_id ): string {
        global $wpdb;
        if ( ! isset( self::$s['tax_of'][ $tt_id ] ) ) {
            self::$s['tax_of'][ $tt_id ] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $tt_id ) );
        }
        return self::$s['tax_of'][ $tt_id ];
    }

    /** After the call: how it left each thing, and only what differs. Null when there is nothing to undo. */
    private static function settle( array $e, array $created ): ?array {
        switch ( $e['k'] ) {
            case 'meta':
                if ( isset( $created[ "{$e['t']}|{$e['id']}" ] ) ) {
                    return null; // part of something the call made: Undo removes the whole thing
                }
                $e['a'] = self::read_meta( $e['t'], $e['id'], $e['key'] );
                return self::same_list( $e['b'], $e['a'], true ) ? null : $e;

            case 'option':
                $e['a'] = self::read_option( $e['name'] );
                return $e['b'] === $e['a'] ? null : $e;

            case 'rel':
                if ( isset( $created[ "post|{$e['id']}" ] ) ) {
                    return null;
                }
                $e['a'] = self::read_rel( $e['id'], $e['tax'] );
                return $e['b'] === $e['a'] ? null : $e;
        }

        $a = self::read_row( $e['k'], $e['id'] );
        if ( $e['b'] === null ) {
            if ( ! $a ) {
                return null; // made and removed again
            }
            $e['op'] = 'create';
            $e['a']  = self::identity( $e['k'], $a );
            if ( $e['k'] === 'term' ) {
                $e['tax'] = $a['taxonomy'];
            }
            return $e;
        }
        if ( ! $a ) {
            $e['op'] = 'delete';
            return $e;
        }
        $diff = [];
        foreach ( $e['b'] as $col => $v ) {
            if ( (string) $v !== (string) ( $a[ $col ] ?? '' ) ) {
                $diff[ $col ] = true;
            }
        }
        if ( ! $diff ) {
            return null;
        }
        if ( $e['k'] === 'term' ) {
            $e['tt']  = (int) $e['b']['term_taxonomy_id'];
            $e['tax'] = (string) $e['b']['taxonomy'];
        }
        $e['op'] = 'update';
        $e['b']  = array_intersect_key( $e['b'], $diff );
        $e['a']  = array_intersect_key( $a, $diff );
        return $e;
    }

    // ── readers ───────────────────────────────────────────────────────────

    private static function read_row( string $kind, int $id ): ?array {
        global $wpdb;
        switch ( $kind ) {
            case 'post':
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
                break;
            case 'term':
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT t.*, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count FROM {$wpdb->terms} t LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id = %d LIMIT 1", $id ), ARRAY_A );
                break;
            case 'user':
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID = %d", $id ), ARRAY_A );
                break;
            case 'comment':
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->comments} WHERE comment_ID = %d", $id ), ARRAY_A );
                break;
            default:
                return null;
        }
        if ( ! $row ) {
            return null;
        }
        foreach ( self::SKIP_COLUMNS[ $kind ] ?? [] as $col ) {
            unset( $row[ $col ] );
        }
        return array_map( static fn( $v ) => $v === null ? null : (string) $v, $row );
    }

    /** @return array{0:string,1:string,2:string} [table, object column, id column] */
    private static function meta_table( string $type ): array {
        return [ (string) _get_meta_table( $type ), $type . '_id', $type === 'user' ? 'umeta_id' : 'meta_id' ];
    }

    /** The raw stored values of one key on one object, oldest first; null when there are none. */
    private static function read_meta( string $type, int $id, string $key ): ?array {
        global $wpdb;
        [ $t, $col, $pk ] = self::meta_table( $type );
        $vals = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM $t WHERE $col = %d AND meta_key = %s ORDER BY $pk", $id, $key ) );
        return $vals ? array_map( 'strval', $vals ) : null;
    }

    private static function read_option( string $name ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
        return $row ? [ 'v' => (string) $row['option_value'], 'al' => (string) $row['autoload'] ] : null;
    }

    /** @return int[] term ids of one object in one taxonomy, sorted */
    private static function read_rel( int $id, string $tax ): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT tt.term_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY tt.term_id", $id, $tax ) );
        return array_map( 'intval', $ids );
    }

    private static function identity( string $kind, array $row ): string {
        if ( $kind === 'post' ) {
            $row = array_intersect_key( $row, array_flip( self::POST_IDENTITY ) );
        }
        ksort( $row );
        return md5( serialize( $row ) );
    }

    private static function same_list( ?array $x, ?array $y, bool $ordered = false ): bool {
        if ( $x === null || $y === null ) {
            return $x === $y;
        }
        if ( ! $ordered ) {
            sort( $x );
            sort( $y );
        }
        return array_values( $x ) === array_values( $y );
    }

    // ── SQL parsing ───────────────────────────────────────────────────────

    /**
     * Quoted literals out of the way: [sql with \x01N\x01 in their place, the unescaped literals, the literals as
     * written]. The pattern is an unrolled loop: a megabyte of serialized data in one literal must not exhaust PCRE.
     */
    private static function tokenize( string $q ): array {
        $lits = [];
        $raws = [];
        $sql  = preg_replace_callback(
            '/\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'|"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"/s',
            static function ( $m ) use ( &$lits, &$raws ) {
                $raws[] = $m[0];
                $lits[] = strtr( substr( $m[0], 1, -1 ), [ '\\\\' => '\\', "\\'" => "'", '\\"' => '"', '\\n' => "\n", '\\r' => "\r", '\\0' => "\0", '\\Z' => "\x1a" ] );
                return "\x01" . ( count( $lits ) - 1 ) . "\x01";
            },
            $q
        );
        if ( $sql === null ) {
            throw new RuntimeException( 'unreadable query' );
        }
        return [ $sql, $lits, $raws ];
    }

    /** An UPDATE/DELETE on another table that changes no row at all (its WHERE matches nothing) changes nothing. */
    private static function matches_nothing( string $table, string $sql, array $raws ): bool {
        global $wpdb;
        if ( ! preg_match( '/^\s*(?:UPDATE\s+`?\w+`?(?:\s+(?:AS\s+)?`?(\w+)`?)?\s+SET|DELETE\s+FROM\s+`?\w+`?(?:\s+(?:AS\s+)?`?(?!WHERE\b)(\w+)`?)?\s+WHERE)\b/i', $sql, $head )
            || preg_match( '/\bJOIN\b/i', $sql ) || ! preg_match_all( '/\bWHERE\b/i', $sql, $m, PREG_OFFSET_CAPTURE ) ) {
            return false;
        }
        $alias = ( $head[1] ?? '' ) !== '' ? $head[1] : ( $head[2] ?? '' );
        $w = (string) preg_replace( '/\b(ORDER\s+BY|LIMIT)\b.*$/is', '', substr( $sql, end( $m[0] )[1] + 5 ) );
        $w = (string) preg_replace_callback( '/\x01(\d+)\x01/', static fn( $x ) => $raws[ (int) $x[1] ] ?? "''", $w );
        $quiet = $wpdb->suppress_errors( true );
        $n     = $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" . ( $alias !== '' ? " AS `$alias`" : '' ) . " WHERE $w" ); // the WHERE the write is about to run
        $wpdb->suppress_errors( $quiet );
        return $n !== null && (int) $n === 0;
    }

    private static function table_name( string $verb, string $sql ): string {
        $re = [
            'INSERT'   => '/^\s*INSERT\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?`?(\w+)`?/i',
            'REPLACE'  => '/^\s*REPLACE\s+(?:(?:LOW_PRIORITY|DELAYED)\s+)*(?:INTO\s+)?`?(\w+)`?/i',
            'UPDATE'   => '/^\s*UPDATE\s+(?:(?:LOW_PRIORITY|IGNORE)\s+)*`?(\w+)`?/i',
            'DELETE'   => '/^\s*DELETE\s+(?:(?:LOW_PRIORITY|QUICK|IGNORE)\s+)*(?:[\w`.]+(?:\s*,\s*[\w`.]+)*\s+)?FROM\s+`?(\w+)`?/i',
            'TRUNCATE' => '/^\s*TRUNCATE\s+(?:TABLE\s+)?`?(\w+)`?/i',
        ][ $verb ] ?? '/\b(?:TABLE|ON)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?(\w+)`?/i';
        return preg_match( $re, $sql, $m ) ? $m[1] : '';
    }

    private static function value( string $tok, array $lits ) {
        $tok = trim( $tok );
        if ( preg_match( '/^\x01(\d+)\x01$/', $tok, $m ) ) {
            return $lits[ (int) $m[1] ];
        }
        return preg_match( '/^-?\d+(\.\d+)?$/', $tok ) ? $tok : null;
    }

    /** The WHERE's `col = value` and `col IN (…)` conditions: col => values. */
    private static function where( string $sql, array $lits ): array {
        if ( ! preg_match_all( '/\bWHERE\b/i', $sql, $m, PREG_OFFSET_CAPTURE ) ) {
            return [];
        }
        $w   = substr( $sql, end( $m[0] )[1] + 5 );
        $w   = (string) preg_replace( '/\b(ORDER\s+BY|LIMIT|GROUP\s+BY)\b.*$/is', '', $w );
        $out = [];
        preg_match_all( '/(?:`?\w+`?\.)?`?(\w+)`?\s*=\s*(\x01\d+\x01|-?\d+)/', $w, $eq, PREG_SET_ORDER );
        foreach ( $eq as $x ) {
            $out[ $x[1] ][] = self::value( $x[2], $lits );
        }
        preg_match_all( '/(?:`?\w+`?\.)?`?(\w+)`?\s+IN\s*\(([^)]*)\)/i', $w, $ins, PREG_SET_ORDER );
        foreach ( $ins as $x ) {
            foreach ( explode( ',', $x[2] ) as $tok ) {
                $out[ $x[1] ][] = self::value( $tok, $lits );
            }
        }
        return array_map( static fn( $v ) => array_values( array_unique( array_filter( $v, static fn( $x ) => $x !== null ) ) ), $out );
    }

    /** An INSERT/REPLACE … (cols) VALUES (…), (…) as rows; null when it has another shape. */
    private static function insert_rows( string $sql, array $lits ): ?array {
        if ( ! preg_match( '/^\s*(?:INSERT|REPLACE)\b[^(]*?\(\s*([^)]*)\)\s*VALUES\s*(.*)$/is', $sql, $m ) ) {
            return null;
        }
        $cols   = array_map( static fn( $c ) => trim( $c, " `\t\r\n" ), explode( ',', $m[1] ) );
        $values = (string) preg_replace( '/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b.*$/is', '', $m[2] );
        preg_match_all( '/\(([^()]*)\)/', $values, $tuples );
        $rows = [];
        foreach ( $tuples[1] as $tuple ) {
            $vals = explode( ',', $tuple );
            if ( count( $vals ) !== count( $cols ) ) {
                return null;
            }
            $rows[] = array_combine( $cols, array_map( static fn( $v ) => self::value( $v, $lits ), $vals ) );
        }
        return $rows ?: null;
    }

    // ── undo ──────────────────────────────────────────────────────────────

    /**
     * Put a call's changes back: first what it deleted (with the same ids, so the rest can attach to it), then its
     * changed values newest first, then what it created. Something changed again since the call is kept as it is,
     * unless $force (a dry run that wrote anyway is put back at once, nobody can have changed it).
     *
     * @return array{restored:int, kept:string[], failed:string[]}
     */
    public static function restore( array $payload, bool $force = false ): array {
        $out     = [ 'restored' => 0, 'kept' => [], 'failed' => [] ];
        $done    = [];
        $refresh = [ 'post' => [], 'term' => [], 'user' => [], 'comment' => [], 'tt' => [], 'elementor' => [] ];
        $entries = self::decode( (array) ( $payload['entries'] ?? [] ) );
        $is_row  = static fn( array $e ): bool => in_array( $e['k'] ?? '', [ 'post', 'term', 'user', 'comment' ], true );

        $steps = [];
        foreach ( $entries as $e ) {
            if ( $is_row( $e ) && ( $e['op'] ?? '' ) === 'delete' ) {
                $steps[] = [ 'recreate', $e ];
            }
        }
        foreach ( array_reverse( $entries ) as $e ) {
            if ( ! $is_row( $e ) || ( $e['op'] ?? '' ) === 'update' ) {
                $steps[] = [ 'revert', $e ];
            }
        }
        foreach ( array_reverse( $entries ) as $e ) {
            if ( $is_row( $e ) && ( $e['op'] ?? '' ) === 'create' ) {
                $steps[] = [ 'remove', $e ];
            }
        }

        foreach ( $steps as [ $step, $e ] ) {
            try {
                $r = self::$step( $e, $force, $refresh );
            } catch ( Throwable $ex ) {
                $out['failed'][] = self::label( $e ) . ': ' . substr( $ex->getMessage(), 0, 200 );
                continue;
            }
            if ( $r === 'done' || $r === 'partly' ) {
                $done[ self::target( $e ) ] = true;
            }
            if ( $r === 'kept' || $r === 'partly' ) {
                $out['kept'][] = self::label( $e );
            }
            if ( $r === 'failed' ) {
                $out['failed'][] = self::label( $e );
            }
        }
        self::refresh( $refresh );
        $out['restored'] = count( $done );
        return $out;
    }

    private static function recreate( array $e, bool $force, array &$refresh ): string {
        global $wpdb;
        $b = (array) $e['b'];
        if ( $e['k'] === 'user' ) {
            return 'skip';
        }
        if ( self::read_row( $e['k'], (int) $e['id'] ) ) {
            return 'kept'; // something is there again
        }
        switch ( $e['k'] ) {
            case 'post':
                $ok = $wpdb->insert( $wpdb->posts, $b );
                $refresh['post'][ (int) $e['id'] ] = true;
                break;
            case 'term':
                $ok = $wpdb->insert( $wpdb->terms, array_intersect_key( $b, array_flip( [ 'term_id', 'name', 'slug', 'term_group' ] ) ) );
                if ( $ok && ! empty( $b['term_taxonomy_id'] ) ) {
                    $ok = $wpdb->insert( $wpdb->term_taxonomy, array_intersect_key( $b, array_flip( [ 'term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent' ] ) ) + [ 'count' => 0 ] );
                    $refresh['tt'][ (string) $b['taxonomy'] ][] = (int) $b['term_taxonomy_id'];
                }
                $refresh['term'][ (int) $e['id'] ] = (string) ( $b['taxonomy'] ?? '' );
                break;
            default:
                $ok = $wpdb->insert( $wpdb->comments, $b );
                $refresh['comment'][ (int) $e['id'] ] = true;
        }
        return $ok ? 'done' : 'failed';
    }

    private static function revert( array $e, bool $force, array &$refresh ): string {
        switch ( $e['k'] ) {
            case 'meta':
                if ( ! $force && ! self::same_list( self::read_meta( $e['t'], (int) $e['id'], $e['key'] ), $e['a'] ?? null ) ) {
                    return 'kept';
                }
                self::write_meta( $e['t'], (int) $e['id'], (string) $e['key'], $e['b'] ?? null );
                if ( $e['t'] === 'post' && $e['key'] === '_elementor_data' ) {
                    $refresh['elementor'][ (int) $e['id'] ] = true;
                }
                return 'done';

            case 'option':
                // The value, not autoload: WordPress may change that by itself.
                if ( ! $force && ( self::read_option( $e['name'] )['v'] ?? null ) !== ( $e['a']['v'] ?? null ) ) {
                    return 'kept';
                }
                self::write_option( (string) $e['name'], $e['b'] ?? null );
                return 'done';

            case 'rel':
                if ( ! $force && self::read_rel( (int) $e['id'], $e['tax'] ) !== ( $e['a'] ?? [] ) ) {
                    return 'kept';
                }
                self::write_rel( (int) $e['id'], (string) $e['tax'], array_map( 'intval', (array) $e['b'] ), $refresh );
                return 'done';
        }

        // A row the call changed: each column back, unless it was changed again since.
        global $wpdb;
        $cur = self::read_row( $e['k'], (int) $e['id'] );
        if ( ! $cur ) {
            return 'kept';
        }
        $set  = [];
        $kept = false;
        foreach ( (array) $e['b'] as $col => $v ) {
            if ( ! $force && (string) ( $cur[ $col ] ?? '' ) !== (string) ( $e['a'][ $col ] ?? '' ) ) {
                $kept = true;
                continue;
            }
            $set[ $col ] = $v;
        }
        if ( ! $set ) {
            return 'kept';
        }
        switch ( $e['k'] ) {
            case 'post':
                $wpdb->update( $wpdb->posts, $set, [ 'ID' => (int) $e['id'] ] );
                $refresh['post'][ (int) $e['id'] ] = true;
                break;
            case 'term':
                $t  = array_intersect_key( $set, array_flip( [ 'name', 'slug', 'term_group' ] ) );
                $tt = array_intersect_key( $set, array_flip( [ 'taxonomy', 'description', 'parent' ] ) );
                if ( $t ) {
                    $wpdb->update( $wpdb->terms, $t, [ 'term_id' => (int) $e['id'] ] );
                }
                if ( $tt ) {
                    $wpdb->update( $wpdb->term_taxonomy, $tt, [ 'term_taxonomy_id' => (int) $e['tt'] ] );
                }
                $refresh['term'][ (int) $e['id'] ] = (string) $e['tax'];
                break;
            case 'user':
                $wpdb->update( $wpdb->users, $set, [ 'ID' => (int) $e['id'] ] );
                $refresh['user'][ (int) $e['id'] ] = true;
                break;
            default:
                $wpdb->update( $wpdb->comments, $set, [ 'comment_ID' => (int) $e['id'] ] );
                $refresh['comment'][ (int) $e['id'] ] = true;
        }
        return $kept ? 'partly' : 'done';
    }

    /** Something the call created: away again, unless it was changed since. Posts go to the trash, as before. */
    private static function remove( array $e, bool $force, array &$refresh ): string {
        global $wpdb;
        $id  = (int) $e['id'];
        $cur = self::read_row( $e['k'], $id );
        if ( ! $cur ) {
            return 'skip';
        }
        if ( ! $force && self::identity( $e['k'], $cur ) !== $e['a'] ) {
            return 'kept';
        }
        switch ( $e['k'] ) {
            case 'post':
                if ( $cur['post_type'] === 'attachment' ) {
                    return wp_delete_attachment( $id, true ) ? 'done' : 'failed';
                }
                if ( $cur['post_type'] === 'nav_menu_item' || $force ) { // a menu item in the trash can't be brought back from anywhere
                    return wp_delete_post( $id, true ) ? 'done' : 'failed';
                }
                return ( $cur['post_status'] === 'trash' || wp_trash_post( $id ) ) ? 'done' : 'failed';
            case 'term':
                $r = wp_delete_term( $id, (string) $cur['taxonomy'] );
                return ( $r && ! is_wp_error( $r ) ) ? 'done' : 'failed';
            case 'comment':
                return wp_delete_comment( $id, true ) ? 'done' : 'failed';
            default:
                if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d", $id ) ) ) {
                    return 'kept'; // someone wrote as this user since
                }
                require_once ABSPATH . 'wp-admin/includes/user.php';
                return wp_delete_user( $id ) ? 'done' : 'failed';
        }
    }

    /** One meta key back to exactly these stored values (null = none). Never unserialized: written as stored. */
    private static function write_meta( string $type, int $id, string $key, ?array $vals ): void {
        global $wpdb;
        [ $t, $col ] = self::meta_table( $type );
        $wpdb->delete( $t, [ $col => $id, 'meta_key' => $key ] );
        foreach ( (array) $vals as $raw ) {
            $wpdb->insert( $t, [ $col => $id, 'meta_key' => $key, 'meta_value' => $raw ] );
        }
        wp_cache_delete( $id, $type . '_meta' );
    }

    /** One option back to exactly its stored value and autoload (null = it didn't exist). */
    private static function write_option( string $name, ?array $b ): void {
        global $wpdb;
        if ( $b === null ) {
            $wpdb->delete( $wpdb->options, [ 'option_name' => $name ] );
        } else {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
                $name, $b['v'], $b['al']
            ) );
        }
        wp_cache_delete( $name, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }

    /** One object's terms in one taxonomy back to exactly these (term ids); counts recomputed after. */
    private static function write_rel( int $id, string $tax, array $term_ids, array &$refresh ): void {
        global $wpdb;
        $old = $wpdb->get_col( $wpdb->prepare( "SELECT tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s", $id, $tax ) );
        if ( $old ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id IN (" . implode( ',', array_map( 'intval', $old ) ) . ')', $id ) );
        }
        $new = $term_ids ? $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_id IN (" . implode( ',', array_map( 'intval', $term_ids ) ) . ')', $tax ) ) : [];
        foreach ( $new as $tt ) {
            $wpdb->insert( $wpdb->term_relationships, [ 'object_id' => $id, 'term_taxonomy_id' => (int) $tt ] );
        }
        foreach ( array_unique( array_merge( $old, $new ) ) as $tt ) {
            $refresh['tt'][ $tax ][] = (int) $tt;
        }
        wp_cache_delete( $id, "{$tax}_relationships" );
    }

    /** Caches, counts, and what plugins build from the restored rows (search indexes, Elementor CSS). */
    private static function refresh( array $r ): void {
        foreach ( $r['tt'] as $tax => $tt_ids ) {
            if ( taxonomy_exists( $tax ) ) {
                wp_update_term_count_now( array_values( array_unique( $tt_ids ) ), $tax );
            }
        }
        foreach ( $r['term'] as $id => $tax ) {
            clean_term_cache( $id, $tax );
        }
        foreach ( array_keys( $r['comment'] ) as $id ) {
            clean_comment_cache( $id );
        }
        foreach ( array_keys( $r['user'] ) as $id ) {
            clean_user_cache( $id );
        }
        // No save_post: plugins would recompute and rewrite their own meta on top of what was just put back. Only the
        // derived data that is known to need it is rebuilt.
        foreach ( array_keys( $r['post'] ) as $id ) {
            clean_post_cache( $id );
            $type = (string) get_post_type( $id );
            foreach ( get_object_taxonomies( $type ?: 'post' ) as $tax ) { // a status put back changes what its terms count
                $tts = wp_get_object_terms( $id, $tax, [ 'fields' => 'tt_ids' ] );
                if ( $tts && ! is_wp_error( $tts ) ) {
                    wp_update_term_count_now( $tts, $tax );
                }
            }
            if ( in_array( $type, [ 'product', 'product_variation' ], true ) && function_exists( 'wc_delete_product_transients' ) ) {
                wc_delete_product_transients( $id );
                try {
                    WC_Data_Store::load( 'product' )->refresh_product_lookup_table( $id );
                } catch ( Throwable $e ) {
                    // an older WooCommerce: its lookup row is rebuilt at the product's next save
                }
            }
            self::rebuild_seo_index( 'post', $id );
        }
        foreach ( array_keys( $r['term'] ) as $id ) {
            self::rebuild_seo_index( 'term', (int) $id );
        }
        foreach ( array_keys( $r['elementor'] ) as $id ) {
            if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
                \Elementor\Core\Files\CSS\Post::create( $id )->delete(); // regenerated from the restored data on the next view
            }
        }
    }

    /** Yoast SEO keeps each post's and term's SEO data in its own index: rebuilt from what was put back. */
    private static function rebuild_seo_index( string $type, int $id ): void {
        if ( ! function_exists( 'YoastSEO' ) ) {
            return;
        }
        try {
            $repo    = YoastSEO()->classes->get( 'Yoast\WP\SEO\Repositories\Indexable_Repository' );
            $builder = YoastSEO()->classes->get( 'Yoast\WP\SEO\Builders\Indexable_Builder' );
            $builder->build_for_id_and_type( $id, $type, $repo->find_by_id_and_type( $id, $type, false ) );
        } catch ( Throwable $e ) {
            // Yoast rebuilds it on the next save
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** JSON can't hold bytes that aren't UTF-8: such values travel as {b64: …}. */
    private static function encode( array $entries ): array {
        $enc = static function ( $v ) use ( &$enc ) {
            if ( is_array( $v ) ) {
                return array_map( $enc, $v );
            }
            return ( is_string( $v ) && ! preg_match( '//u', $v ) ) ? [ 'b64' => base64_encode( $v ) ] : $v;
        };
        return array_map( $enc, $entries );
    }

    private static function decode( array $entries ): array {
        $dec = static function ( $v ) use ( &$dec ) {
            if ( is_array( $v ) ) {
                return ( count( $v ) === 1 && isset( $v['b64'] ) && is_string( $v['b64'] ) ) ? (string) base64_decode( $v['b64'] ) : array_map( $dec, $v );
            }
            return $v;
        };
        return array_map( $dec, $entries );
    }

    /** What an entry is about, e.g. "post 12", "option blogname". */
    private static function target( array $e ): string {
        switch ( $e['k'] ?? '' ) {
            case 'meta':
                return "{$e['t']} {$e['id']}";
            case 'option':
                return "option {$e['name']}";
            case 'rel':
                return "post {$e['id']}";
            default:
                return ( $e['k'] ?? '' ) . ' ' . ( $e['id'] ?? '' );
        }
    }

    /** What an entry changed, for the "kept" list, e.g. "post 12: post_title". */
    private static function label( array $e ): string {
        switch ( $e['k'] ?? '' ) {
            case 'meta':
                return self::target( $e ) . ": {$e['key']}";
            case 'option':
                return self::target( $e );
            case 'rel':
                return self::target( $e ) . ": {$e['tax']}";
            default:
                return self::target( $e ) . ( ( $e['op'] ?? '' ) === 'update' ? ': ' . implode( ', ', array_keys( (array) $e['b'] ) ) : '' );
        }
    }
}
