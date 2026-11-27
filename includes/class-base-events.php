<?php
/**
 * Events pipeline (PROTOCOL §5): local durable queue → batched signed upload.
 *
 * - Producers (audit logger / sessions / lock manager / a few WP hooks) only INSERT a small
 *   row into wp_aicom_base_events — no network on the request path, ever.
 * - The heartbeat tick flushes ≤200 events per batch to /api/v1/site/events; rows are deleted
 *   only after AICOMBase accepted the batch, otherwise attempts++ and they retry later.
 * - Nothing credential-shaped is ever queued: only tool names, ids, coarse summaries
 *   (never request params or results), and AICOM_Base_Client::scrub() runs again on send.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base_Events {

    const BATCH        = 200;
    const QUEUE_CAP    = 5000;
    const MAX_ATTEMPTS = 40;

    /** Plugin folder → [tool dependency, name]: deactivating it takes that module's tools away. */
    private const DEPENDENCY_PLUGINS = [
        'woocommerce'                => [ 'woocommerce', 'WooCommerce' ],
        'elementor'                  => [ 'elementor', 'Elementor' ],
        'polylang'                   => [ 'polylang', 'Polylang' ],
        'polylang-pro'               => [ 'polylang', 'Polylang Pro' ],
        'ele-custom-skin'            => [ 'ecs', 'Ele Custom Skin' ],
        'ele-custom-skin-pro'        => [ 'ecs_pro', 'Ele Custom Skin Pro' ],
        'clautron'                   => [ 'clautron', 'Clautron' ],
        'wordpress-seo'              => [ 'yoast', 'Yoast SEO' ],
        'wp-seopress'                => [ 'seopress', 'SEOPress' ],
        'sitepress-multilingual-cms' => [ 'wpml', 'WPML' ],
    ];

    /** @var array<int,array{session_id:string,task_target_id:string}> local session id → remote context */
    private static array $remote_sessions = [];

    // ── wiring ────────────────────────────────────────────────────────────

    public static function register(): void {
        add_action( 'aicom_audit_logged',        [ __CLASS__, 'on_audit_logged' ], 10, 2 );
        add_action( 'aicom_session_opened',      [ __CLASS__, 'on_session_opened' ], 10, 1 );
        add_action( 'aicom_session_closed',      [ __CLASS__, 'on_session_closed' ], 10, 2 );
        add_action( 'aicom_sessions_closed_stale', [ __CLASS__, 'on_sessions_closed_stale' ], 10, 2 );
        add_action( 'aicom_lock_changed',        [ __CLASS__, 'on_lock_changed' ], 10, 0 );
        add_action( 'activated_plugin',          [ __CLASS__, 'on_plugin_activated' ], 10, 1 );
        add_action( 'deactivated_plugin',        [ __CLASS__, 'on_plugin_deactivated' ], 10, 1 );
        add_action( 'switch_theme',              [ __CLASS__, 'on_switch_theme' ], 10, 1 );
        add_action( 'user_register',             [ __CLASS__, 'on_user_register' ], 10, 1 );
        add_action( 'set_user_role',             [ __CLASS__, 'on_set_user_role' ], 10, 3 );
        add_action( 'update_option_siteurl',     [ __CLASS__, 'on_site_url_change' ], 10, 2 );
        add_action( 'update_option_home',        [ __CLASS__, 'on_site_url_change' ], 10, 2 );
    }

    // ── remote session context (set by the Executor) ──────────────────────

    /** @var array{session_id:string,task_target_id:string}|null Set right before the executor opens its session. */
    private static ?array $pending_remote = null;

    /** The next session opened in this process belongs to an AICOMBase task. */
    public static function expect_remote_session( string $base_session_id, string $task_target_id ): void {
        self::$pending_remote = [ 'session_id' => $base_session_id, 'task_target_id' => $task_target_id ];
    }

    public static function bind_remote_session( int $local_id, string $base_session_id, string $task_target_id ): void {
        self::$remote_sessions[ $local_id ] = [ 'session_id' => $base_session_id, 'task_target_id' => $task_target_id ];
    }

    public static function unbind_remote_session( int $local_id ): void {
        unset( self::$remote_sessions[ $local_id ] );
    }

    private static function session_ref( int $local_id ): string {
        if ( isset( self::$remote_sessions[ $local_id ]['session_id'] ) ) {
            return self::$remote_sessions[ $local_id ]['session_id'];
        }
        // Sessions opened by AICOMBase carry its id permanently (not just for the current request).
        $base = AICOM_Sessions::base_id_of( $local_id );
        return $base !== '' ? $base : 'local-' . $local_id;
    }

    /** A task AICOMBase sent is running inside its session — lets AICOMBase mark the task target running. */
    public static function task_started( string $base_session_id, string $task_target_id, string $title ): void {
        self::enqueue( 'session.started', [
            'session_id'     => $base_session_id,
            'title'          => substr( $title, 0, 300 ),
            'initiator'      => 'ai',
            'task_target_id' => $task_target_id,
            'started_at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
        ] );
    }

    /**
     * Result of a `restore_session` command.
     *
     * @param string[] $kept   Changes left as they are because someone changed them after the AI.
     * @param string[] $failed   Changes Undo could not put back (a notice, for an AICOMBase that reads them).
     * @param string[] $password Deleted users brought back with a new password (a notice too).
     */
    public static function session_restored( string $base_session_id, int $restored, string $error = '', array $kept = [], array $failed = [], array $password = [] ): void {
        $e = [ 'session_id' => $base_session_id, 'restored' => $restored, 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ];
        if ( $error !== '' ) {
            $e['error'] = substr( $error, 0, 500 );
        }
        if ( $kept ) {
            $e['kept'] = array_slice( array_map( static fn( $k ) => substr( (string) $k, 0, 200 ), $kept ), 0, 50 );
        }
        $notices = [];
        if ( $failed ) {
            /* translators: %d: number of changes */
            $notices[] = AICOM_Notices::make( 'error', 'restore.failed', sprintf( _n( 'Undo could not put back %d change.', 'Undo could not put back %d changes.', count( $failed ), 'aicom' ), count( $failed ) ), $failed );
        }
        if ( $password ) {
            /* translators: %d: number of users */
            $notices[] = AICOM_Notices::make( 'warn', 'restore.user_password', sprintf( _n( '%d deleted user is back, with a new password: they set theirs again with "Lost your password?", or an admin sends them a reset link from Users.', '%d deleted users are back, with a new password: they set theirs again with "Lost your password?", or an admin sends them a reset link from Users.', count( $password ), 'aicom' ), count( $password ) ), $password );
        }
        if ( $notices ) {
            $e['notices'] = $notices;
        }
        self::enqueue( 'session.restored', $e );
    }

    /**
     * Something the owner should hear about that isn't tied to one call (PROTOCOL §12 `site.notice`): AICOMBase shows
     * it in Activity and sends it as a notification. Only to an AICOMBase that lists the feature, and each code at most
     * once an hour (AICOMBase's own rule), so a stable code per kind is what keeps it from repeating.
     *
     * @param array{title?:string,items?:string[],category?:string,session_id?:string} $extra session_id: AICOMBase's.
     */
    public static function site_notice( string $level, string $code, string $text, array $extra = [] ): void {
        if ( ! AICOM_Base_State::is_connected() || ! AICOM_Base_State::base_supports( 'site.notice' ) ) {
            return;
        }
        $n = AICOM_Notices::clean_one( [ 'level' => $level, 'code' => $code, 'text' => $text, 'items' => $extra['items'] ?? [] ] );
        if ( ! $n || ! isset( $n['code'] ) ) {
            return;
        }
        $key = 'aicom_base_notice_' . md5( $code );
        if ( get_transient( $key ) ) {
            return;
        }
        set_transient( $key, 1, HOUR_IN_SECONDS );
        $title = AICOM_Notices::plain( $extra['title'] ?? '', 140 );
        if ( $title !== '' ) {
            $n['title'] = $title;
        }
        if ( in_array( $extra['category'] ?? '', [ 'tasks', 'security', 'connections', 'monitoring' ], true ) ) {
            $n['category'] = $extra['category'];
        }
        if ( ! empty( $extra['session_id'] ) ) {
            $n['session_id'] = (string) $extra['session_id'];
        }
        self::enqueue( 'site.notice', $n );
    }

    // ── queue primitives ──────────────────────────────────────────────────

    /** Add an event. No-ops when not connected. */
    public static function enqueue( string $type, array $fields ): void {
        if ( ! AICOM_Base_State::is_connected() ) {
            return;
        }
        global $wpdb;
        // AICOMBase validates strictly: optional fields must be absent, never null; `data` must be an object.
        $fields = array_filter( $fields, static fn( $v ) => $v !== null );
        // Any event may carry notices (PROTOCOL §12) — only to an AICOMBase that reads them.
        if ( array_key_exists( 'notices', $fields ) ) {
            $notices = AICOM_Base_State::base_supports( 'notices' ) ? AICOM_Notices::clean( $fields['notices'] ) : [];
            unset( $fields['notices'] );
            if ( $notices ) {
                $fields['notices'] = $notices;
            }
        }
        if ( isset( $fields['data'] ) && is_array( $fields['data'] ) && ! $fields['data'] ) {
            $fields['data'] = new stdClass();
        }
        $event = array_merge( [ 'type' => $type, 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ], $fields );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( $wpdb->prefix . 'aicom_base_events', [
            'event_type' => substr( $type, 0, 32 ),
            'payload'    => (string) wp_json_encode( $event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
            'created_at' => current_time( 'mysql', true ),
            'attempts'   => 0,
        ] );
        // Bound the backlog when AICOMBase is unreachable for a long time.
        if ( $wpdb->insert_id % 250 === 0 ) {
            self::trim();
        }
    }

    private static function trim(): void {
        global $wpdb;
        $t     = $wpdb->prefix . 'aicom_base_events';
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( $count > self::QUEUE_CAP ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM $t ORDER BY id ASC LIMIT %d", $count - self::QUEUE_CAP + 500 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    public static function pending_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_base_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    }

    public static function purge(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}aicom_base_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    }

    /**
     * Send queued events in batches of ≤200. Stops at the first failed batch.
     *
     * @return array{sent:int,failed:bool}
     */
    public static function flush( int $max_batches = 3 ): array {
        global $wpdb;
        $t    = $wpdb->prefix . 'aicom_base_events';
        $sent = 0;
        for ( $i = 0; $i < $max_batches; $i++ ) {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, payload, attempts FROM $t ORDER BY id ASC LIMIT %d", self::BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            if ( ! $rows ) {
                break;
            }
            $events = [];
            $ids    = [];
            foreach ( $rows as $r ) {
                $e = json_decode( $r['payload'] ); // objects stay objects: an empty `data:{}` must not become `[]`
                $ids[] = (int) $r['id'];
                if ( $e instanceof \stdClass ) {
                    $events[] = $e;
                }
            }
            $res = $events
                ? AICOM_Base_Client::site_request( 'POST', '/api/v1/site/events', [ 'events' => $events ], [ 'timeout' => 15 ] )
                : [ 'ok' => true ];
            $in = implode( ',', array_map( 'intval', $ids ) );
            if ( $res['ok'] ) {
                $wpdb->query( "DELETE FROM $t WHERE id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
                $sent += count( $ids );
                continue;
            }
            // A 4xx validation rejection would otherwise loop forever on the same rows: age them out.
            $wpdb->query( "UPDATE $t SET attempts = attempts + 1 WHERE id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE attempts >= %d", self::MAX_ATTEMPTS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            return [ 'sent' => $sent, 'failed' => true ];
        }
        return [ 'sent' => $sent, 'failed' => false ];
    }

    // ── typed producers ───────────────────────────────────────────────────

    public static function security( string $kind, string $severity, array $data = [], bool $throttle = false ): void {
        if ( $throttle ) {
            $key = 'aicom_base_sec_' . md5( $kind . wp_json_encode( $data ) );
            if ( get_transient( $key ) ) {
                return;
            }
            set_transient( $key, 1, 60 );
        }
        self::enqueue( 'security', [ 'kind' => $kind, 'severity' => $severity, 'data' => $data ] );
    }

    // ── hook handlers ─────────────────────────────────────────────────────

    /** Audit log row → action / security event. */
    public static function on_audit_logged( array $row, int $id ): void {
        if ( ! AICOM_Base_State::is_connected() ) {
            return;
        }
        $tool   = (string) ( $row['tool_name'] ?? '' );
        $module = (string) ( $row['module'] ?? '' );
        if ( $tool === '' || in_array( $module, [ 'hub', 'base' ], true ) ) {
            return;
        }
        $status = (string) ( $row['status'] ?? '' );

        // Security-relevant rows.
        if ( $status === 'auth_failed' || $status === 'rate_limited' ) {
            self::security( 'failed_auth', $status === 'rate_limited' ? 'medium' : 'low', [ 'tool' => $tool, 'status' => $status ], true );
            return;
        }
        if ( $status === 'denied_scope' ) {
            self::security( 'scope_rejected', 'low', [ 'tool' => $tool, 'source' => 'api_key' ], true );
        }

        // Action rows need a session (AICOMBase groups actions by session).
        $local_session = (int) ( $row['session_id'] ?? 0 );
        if ( ! $local_session ) {
            return;
        }
        if ( strpos( $status, 'blocked' ) === 0 || in_array( $status, [ 'denied_scope', 'auth_failed', 'rate_limited' ], true ) ) {
            $result = 'blocked';
        } elseif ( $status === 'success' || $status === 'idempotent_replay' ) {
            $result = 'success';
        } else {
            $result = 'error';
        }
        $meta    = AICOM_Tool_Registry::get( $tool );
        $summary = $tool;
        if ( ! empty( $row['target_type'] ) ) {
            $summary .= ' → ' . $row['target_type'] . ( ! empty( $row['target_id'] ) ? ' #' . $row['target_id'] : '' );
        }
        if ( ! empty( $row['is_dry_run'] ) ) {
            $summary .= ' (dry run)';
        }
        $event = [
            'session_id'    => self::session_ref( $local_session ),
            'tool_id'       => $tool,
            'capability_id' => $meta ? AICOM_Base_Capabilities::capability_id( $tool, (string) $meta['module'] ) : null,
            'summary'       => substr( $summary, 0, 240 ),
            'result'        => $result,
            'duration_ms'   => isset( $row['duration_ms'] ) ? (int) $row['duration_ms'] : null,
            'risk'          => $meta ? AICOM_Base_Capabilities::risk( $meta ) : 'low',
        ];
        if ( $result !== 'success' ) {
            $event['error_category'] = $row['error_code'] ?? $status;
        }
        self::enqueue( 'action', $event );
    }

    public static function on_session_opened( array $s ): void {
        $id = (int) $s['id'];
        if ( self::$pending_remote ) {
            self::$remote_sessions[ $id ] = self::$pending_remote;
            self::$pending_remote         = null;
        }
        $ref = self::$remote_sessions[ $id ] ?? null;
        $e   = [
            'session_id' => self::session_ref( $id ),
            'title'      => substr( (string) ( $s['name'] ?? 'AICOM session' ), 0, 300 ),
            'initiator'  => ( $ref && ( $s['source'] ?? 'local' ) !== 'aicombase' ) ? 'system' : 'ai',
            'started_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( ( $s['opened_at'] ?? 'now' ) . ' UTC' ) ),
        ];
        if ( $ref ) {
            $e['task_target_id'] = $ref['task_target_id'];
        }
        self::enqueue( 'session.started', $e );
    }

    public static function on_session_closed( array $s, string $end_status = 'completed' ): void {
        $id = (int) $s['id'];
        $e  = [
            'session_id' => self::session_ref( $id ),
            'status'     => in_array( $end_status, [ 'completed', 'failed', 'cancelled' ], true ) ? $end_status : 'completed',
            'ended_at'   => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( ( $s['closed_at'] ?? 'now' ) . ' UTC' ) ),
        ];
        self::enqueue( 'session.ended', $e );
    }

    /**
     * The 2 h rule closed sessions (AICOM_Sessions::close_stale): tell the owner about the AICOMBase ones, which
     * AICOMBase would otherwise only see end as cancelled.
     *
     * @param array[] $rows The closed sessions.
     */
    public static function on_sessions_closed_stale( array $rows, int $hours ): void {
        $base = array_values( array_filter( $rows, static fn( $s ) => ( $s['source'] ?? '' ) === 'aicombase' ) );
        if ( ! $base ) {
            return;
        }
        self::site_notice(
            'info',
            'session.closed_stale',
            /* translators: %d: hours */
            sprintf( _n( 'AICOM closed this AICOMBase work because its session was open for more than %d hour. Nothing more runs in it; what it changed stays and can still be undone.', 'AICOM closed this AICOMBase work because its session was open for more than %d hours. Nothing more runs in it; what it changed stays and can still be undone.', $hours, 'aicom' ), $hours ),
            [
                'title'      => __( 'Session closed after its time limit', 'aicom' ),
                'items'      => array_values( array_unique( array_map( static fn( $s ) => (string) ( $s['name'] ?? '' ), $base ) ) ),
                'category'   => 'tasks',
                'session_id' => count( $base ) === 1 ? (string) ( $base[0]['base_session_id'] ?? '' ) : '',
            ]
        );
    }

    public static function on_lock_changed(): void {
        $now  = AICOM_Lock_Manager::get_effective_lock();
        $map  = [ 'unlocked' => 'none', 'soft_locked' => 'soft', 'hard_locked' => 'hard' ];
        $state = $map[ $now ] ?? 'none';
        if ( get_option( 'aicom_base_last_lock_reported', 'none' ) === $state ) {
            return;
        }
        update_option( 'aicom_base_last_lock_reported', $state, false );
        self::enqueue( 'lock', [ 'state' => $state, 'by' => 'local' ] );
        if ( $state !== 'none' ) {
            self::security( 'lock', $state === 'hard' ? 'high' : 'medium', [ 'state' => $state, 'by' => 'local' ] );
        }
    }

    public static function on_plugin_activated( $plugin ): void {
        self::security( 'plugin_theme_change', 'info', [ 'change' => 'plugin_activated', 'plugin' => dirname( (string) $plugin ) ?: (string) $plugin ] );
    }

    public static function on_plugin_deactivated( $plugin ): void {
        $dir = dirname( (string) $plugin ) ?: (string) $plugin;
        self::security( 'plugin_theme_change', 'info', [ 'change' => 'plugin_deactivated', 'plugin' => $dir ] );

        // A plugin AICOM's tools depend on: its tools are gone until it comes back.
        [ $dependency, $name ] = self::DEPENDENCY_PLUGINS[ $dir ] ?? [ '', '' ];
        $tools = $dependency === '' ? 0 : count( array_filter( AICOM_Tool_Registry::get_all(), static fn( $t ) => ( $t['dependency'] ?? null ) === $dependency ) );
        if ( $tools ) {
            self::site_notice(
                'warn',
                'dependency.deactivated.' . $dependency,
                /* translators: 1: plugin name, 2: number of tools */
                sprintf( _n( '%1$s was deactivated, so its %2$d AICOM tool is no longer available to AI agents. It comes back when %1$s is activated again.', '%1$s was deactivated, so its %2$d AICOM tools are no longer available to AI agents. They come back when %1$s is activated again.', $tools, 'aicom' ), $name, $tools ),
                /* translators: %s: plugin name */
                [ 'title' => sprintf( __( '%s deactivated', 'aicom' ), $name ), 'category' => 'monitoring' ]
            );
        }
    }

    public static function on_switch_theme( $new_name ): void {
        self::security( 'plugin_theme_change', 'low', [ 'change' => 'theme_switched', 'theme' => (string) $new_name ] );
    }

    public static function on_user_register( $user_id ): void {
        $u = get_userdata( (int) $user_id );
        if ( $u && in_array( 'administrator', (array) $u->roles, true ) ) {
            self::security( 'admin_created', 'high', [ 'user_id' => (int) $user_id ] );
        }
    }

    public static function on_set_user_role( $user_id, $role, $old_roles ): void {
        if ( $role === 'administrator' && ! in_array( 'administrator', (array) $old_roles, true ) ) {
            self::security( 'admin_created', 'high', [ 'user_id' => (int) $user_id, 'via' => 'role_change' ] );
        }
    }

    public static function on_site_url_change( $old, $new ): void {
        if ( $old !== $new ) {
            self::security( 'site_identity_change', 'high', [
                'from' => (string) wp_parse_url( (string) $old, PHP_URL_HOST ),
                'to'   => (string) wp_parse_url( (string) $new, PHP_URL_HOST ),
            ] );
        }
    }
}
