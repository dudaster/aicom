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

        return self::get( (int) $wpdb->insert_id );
    }

    /**
     * Close the active session for an API key.
     * Returns the closed session row or null if no active session.
     */
    public static function close( int $key_id ): ?array {
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

        return self::get( (int) $session['id'] );
    }

    /**
     * Auto-close sessions that have been open for more than $hours hours.
     * Called by the existing hourly cron (aicom_expire_keys).
     */
    public static function close_stale( int $hours = 2 ): void {
        global $wpdb;
        $now = current_time( 'mysql', true );
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
