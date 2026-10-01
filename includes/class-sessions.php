<?php
/**
 * Session CRUD: open/close/query for named agent sessions.
 * One active session per API key. Sessions gate write-tool execution.
 */
class AICOM_Sessions {

    // ── Write ─────────────────────────────────────────────────────────────

    /**
     * Open a new named session for the given API key.
     *
     * @return array|string The new session row (ARRAY_A), or the string
     *                       'SESSION_ALREADY_OPEN'. No union return type
     *                       declared — union types are PHP 8.0+ and this
     *                       plugin's declared floor is 7.4.
     */
    public static function open( int $key_id, string $key_label, string $name, string $desc = '' ) {
        if ( self::get_active( $key_id ) ) {
            return 'SESSION_ALREADY_OPEN';
        }

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'aicom_sessions',
            [
                'api_key_id'    => $key_id,
                'api_key_label' => $key_label,
                'name'          => sanitize_text_field( $name ),
                'description'   => sanitize_textarea_field( $desc ),
                'status'        => 'open',
                'opened_at'     => current_time( 'mysql', true ),
            ]
        );

        $row = self::get( (int) $wpdb->insert_id );
        if ( $row ) {
            do_action( 'aicom_session_opened', $row );
        }
        return $row;
    }

    // ── Sessions opened by AICOMBase ──────────────────────────────────────
    // AICOMBase can't run anything here outside a session it opened first (signed `open_session` command).
    // They're ordinary AICOM sessions (same table, same hooks → listed in AICOM → Activity → Sessions, can be
    // closed / restored there, reported back to AICOMBase), tagged source 'aicombase' + the AICOMBase id.

    /** Open (or return the already-open) session for an AICOMBase session id. */
    public static function open_remote( string $base_id, string $name, string $desc = '', string $agent_label = '' ): ?array {
        $base_id = substr( sanitize_text_field( $base_id ), 0, 64 );
        if ( $base_id === '' ) {
            return null;
        }
        $open = self::find_open_remote( $base_id );
        if ( $open ) {
            return $open;
        }
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'aicom_sessions',
            [
                'api_key_id'      => 0,
                'api_key_label'   => substr( 'AICOMBase' . ( $agent_label !== '' ? ' · ' . sanitize_text_field( $agent_label ) : '' ), 0, 191 ),
                'name'            => sanitize_text_field( $name !== '' ? $name : 'AICOMBase session' ),
                'description'     => sanitize_textarea_field( $desc ),
                'status'          => 'open',
                'opened_at'       => current_time( 'mysql', true ),
                'base_session_id' => $base_id,
                'source'          => 'aicombase',
            ]
        );
        $row = self::get( (int) $wpdb->insert_id );
        if ( $row ) {
            do_action( 'aicom_session_opened', $row );
        }
        return $row;
    }

    /** The open local session for an AICOMBase session id, if any. */
    public static function find_open_remote( string $base_id ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aicom_sessions WHERE base_session_id = %s AND status = 'open' ORDER BY opened_at DESC LIMIT 1",
                $base_id
            ),
            ARRAY_A
        ) ?: null;
    }

    /** Every local session (any status) that belonged to an AICOMBase session id, newest first. @return int[] */
    public static function remote_ids( string $base_id ): array {
        global $wpdb;
        return array_map( 'intval', (array) $wpdb->get_col(
            $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aicom_sessions WHERE base_session_id = %s ORDER BY opened_at DESC, id DESC", $base_id )
        ) );
    }

    /** Close the open local session(s) of an AICOMBase session id. Returns how many were closed. */
    public static function close_remote( string $base_id, string $end_status = 'completed' ): int {
        global $wpdb;
        $ids = array_map( 'intval', (array) $wpdb->get_col(
            $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aicom_sessions WHERE base_session_id = %s AND status = 'open'", $base_id )
        ) );
        foreach ( $ids as $id ) {
            $wpdb->update( $wpdb->prefix . 'aicom_sessions', [ 'status' => 'closed', 'closed_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
            $row = self::get( $id );
            if ( $row ) {
                do_action( 'aicom_session_closed', $row, $end_status );
            }
        }
        return count( $ids );
    }

    /** Close one open session by id (admin "Close" button) — fires the usual hook, so AICOMBase hears about it. */
    public static function close_by_id( int $id, string $end_status = 'cancelled' ): bool {
        global $wpdb;
        $n = $wpdb->update( $wpdb->prefix . 'aicom_sessions', [ 'status' => 'closed', 'closed_at' => current_time( 'mysql', true ) ], [ 'id' => $id, 'status' => 'open' ] );
        if ( $n ) {
            $row = self::get( $id );
            if ( $row ) {
                do_action( 'aicom_session_closed', $row, $end_status );
            }
        }
        return (bool) $n;
    }

    /** Hand an open remote session to the ephemeral key of one execution, so the Tool Router sees it as that key's session. */
    public static function attach_key( int $session_id, int $key_id ): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'aicom_sessions', [ 'api_key_id' => $key_id ], [ 'id' => $session_id, 'status' => 'open' ] );
    }

    /** The AICOMBase session id a local session belongs to ('' for local sessions). */
    public static function base_id_of( int $local_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare( "SELECT base_session_id FROM {$wpdb->prefix}aicom_sessions WHERE id = %d", $local_id ) );
    }

    /**
     * Close the active session for an API key.
     * Returns the closed session row or null if no active session.
     */
    public static function close( int $key_id, string $end_status = 'completed' ): ?array {
        $session = self::get_active( $key_id );
        if ( ! $session ) {
            return null;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'aicom_sessions',
            [
                'status'    => 'closed',
                'closed_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $session['id'] ]
        );

        $row = self::get( (int) $session['id'] );
        if ( $row ) {
            do_action( 'aicom_session_closed', $row, $end_status );
        }
        return $row;
    }

    /**
     * Auto-close sessions that have been open for more than $hours hours.
     * Called by the existing hourly cron (aicom_expire_keys).
     */
    public static function close_stale( int $hours = 2 ): void {
        global $wpdb;
        $now = current_time( 'mysql', true );
        // Collect the ids first so observers (AICOMBase events) can be told which sessions were auto-closed.
        $stale_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}aicom_sessions
                 WHERE status = 'open' AND opened_at < DATE_SUB(%s, INTERVAL %d HOUR)",
                $now,
                $hours
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}aicom_sessions
                 SET status = 'closed', closed_at = %s
                 WHERE status = 'open' AND opened_at < DATE_SUB(%s, INTERVAL %d HOUR)",
                $now,
                $now,
                $hours
            )
        );
        foreach ( (array) $stale_ids as $sid ) {
            $row = self::get( (int) $sid );
            if ( $row ) {
                do_action( 'aicom_session_closed', $row, 'cancelled' );
            }
        }
    }

    // ── Read ──────────────────────────────────────────────────────────────

    /**
     * Get the currently active session for an API key (or null).
     */
    public static function get_active( int $key_id ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aicom_sessions
                 WHERE api_key_id = %d AND status = 'open'
                 ORDER BY opened_at DESC LIMIT 1",
                $key_id
            ),
            ARRAY_A
        ) ?: null;
    }

    /**
     * Get a session by ID.
     */
    public static function get( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aicom_sessions WHERE id = %d",
                $id
            ),
            ARRAY_A
        ) ?: null;
    }

    /**
     * List sessions for the UI, newest first, with request/backup counts.
     * Optional filters: 'date' (open date), 'log_date' (any log on that date),
     *                   'date_from'/'date_to' (open date range).
     */
    public static function get_all( array $filters = [] ): array {
        global $wpdb;

        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $filters['log_date'] ) ) {
            // Sessions that had any log activity on this date (graph-click filter).
            $where[]  = 'EXISTS (SELECT 1 FROM ' . $wpdb->prefix . 'aicom_logs lf WHERE lf.session_id = s.id AND DATE(lf.created_at) = %s)';
            $params[] = $filters['log_date'];
        } elseif ( ! empty( $filters['date'] ) ) {
            $where[]  = 'DATE(s.opened_at) = %s';
            $params[] = $filters['date'];
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'DATE(s.opened_at) >= %s';
            $params[] = $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'DATE(s.opened_at) <= %s';
            $params[] = $filters['date_to'];
        }

        $where_sql = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sql = "SELECT s.*,
                    COUNT(DISTINCT l.id) AS request_count,
                    COUNT(DISTINCT b.id) AS backup_count
                FROM {$wpdb->prefix}aicom_sessions s
                LEFT JOIN {$wpdb->prefix}aicom_logs    l ON l.session_id = s.id
                LEFT JOIN {$wpdb->prefix}aicom_backups b ON b.session_id = s.id
                WHERE $where_sql
                GROUP BY s.id
                ORDER BY s.opened_at DESC
                LIMIT 200";

        return empty( $params )
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ? ( $wpdb->get_results( $sql, ARRAY_A ) ?: [] )
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            : ( $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [] );
    }

    /**
     * Get logs for a single session (for the expand panel).
     */
    public static function get_logs( int $session_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tool_name, tool_class, status, duration_ms, target_type, target_id, created_at
                 FROM {$wpdb->prefix}aicom_logs
                 WHERE session_id = %d
                 ORDER BY created_at ASC",
                $session_id
            ),
            ARRAY_A
        ) ?: [];
    }
}
