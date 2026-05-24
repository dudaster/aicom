<?php
/**
 * Structured audit logging to wp_aicom_logs.
 * All log entries record the full context needed for the Audit Logs UI filters.
 */
class AICOM_Audit_Logger {

    private static ?string $table = null;

    private static function table(): string {
        if ( self::$table === null ) {
            global $wpdb;
            self::$table = $wpdb->prefix . 'aicom_logs';
        }
        return self::$table;
    }

    // ── Write ─────────────────────────────────────────────────────────────

    /**
     * Insert a log entry. $data keys match column names; missing keys use defaults.
     * Returns inserted row ID.
     */
    public static function log( array $data ): int {
        global $wpdb;

        $defaults = [
            'created_at'          => current_time( 'mysql', true ),
            'request_id'          => wp_generate_uuid4(),
            'session_id'          => null,
            'remote_ip'           => '0.0.0.0',
            'api_key_id'          => null,
            'api_key_label'       => null,
            'tool_name'           => 'unknown',
            'module'              => 'unknown',
            'tool_class'          => null,
            'status'              => 'error',
            'http_status'         => null,
            'duration_ms'         => null,
            'target_type'         => null,
            'target_id'           => null,
            'is_dry_run'          => 0,
            'error_code'          => null,
            'error_message'       => null,
            'params_json'         => null,
            'result_summary_json' => null,
        ];

        $row = array_merge( $defaults, array_intersect_key( $data, $defaults ) );
        $wpdb->insert( self::table(), $row );
        $insert_id = (int) $wpdb->insert_id;

        // Critical-event side channel: anything that looks like a thwarted
        // intrusion attempt is pushed to paired Hubs immediately, so the
        // central audit dashboard surfaces it before the hourly batch.
        // The same event also lands in the next batch — Hub dedupes.
        if ( self::is_urgent_event( $row ) && class_exists( 'AICOM_Hub_Channel' ) ) {
            AICOM_Hub_Channel::push_event_now( $row );
        }

        return $insert_id;
    }

    /**
     * Decide whether a logged event warrants a real-time push to paired Hubs.
     * Kept tight: only security-relevant events that an admin would want to see
     * within seconds, not within the hour.
     */
    private static function is_urgent_event( array $row ): bool {
        static $urgent_status = [
            'auth_failed',
            'rate_limited',
            'blocked_hard_lock',
            'blocked_soft_lock',
            'blocked_working_hours',
        ];
        return in_array( $row['status'] ?? '', $urgent_status, true );
    }

    // ── Read ──────────────────────────────────────────────────────────────

    /**
     * Query logs with filters. Returns ['items' => [...], 'total' => int].
     *
     * Supported filters: tool_name (LIKE), status, api_key_id, remote_ip,
     *                    date_from (YYYY-MM-DD HH:MM:SS), date_to.
     */
    public static function query( array $filters = [], int $per_page = 50, int $page = 1 ): array {
        global $wpdb;

        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $filters['tool_name'] ) ) {
            $where[]  = 'tool_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filters['tool_name'] ) . '%';
        }
        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }
        if ( ! empty( $filters['api_key_id'] ) ) {
            $where[]  = 'api_key_id = %d';
            $params[] = (int) $filters['api_key_id'];
        }
        if ( ! empty( $filters['remote_ip'] ) ) {
            $where[]  = 'remote_ip = %s';
            $params[] = $filters['remote_ip'];
        }
        if ( ! empty( $filters['session_id'] ) ) {
            $where[]  = 'session_id = %d';
            $params[] = (int) $filters['session_id'];
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'created_at >= %s';
            $params[] = $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'created_at <= %s';
            $params[] = $filters['date_to'];
        }

        $where_sql = implode( ' AND ', $where );
        $offset    = ( max( 1, $page ) - 1 ) * $per_page;
        $table     = self::table();

        // Count query (no ORDER BY or LIMIT).
        // $table = $wpdb->prefix.'aicom_logs' (trusted); user filters use %s/%d placeholders.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = empty( $params )
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            ? (int) $wpdb->get_var( $count_sql )
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            : (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );

        // Data query — always uses prepare (has %d LIMIT/%d OFFSET at minimum).
        $data_params = array_merge( $params, [ $per_page, $offset ] );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $data_sql = "SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $items = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_params ), ARRAY_A );

        return [
            'items' => $items ?: [],
            'total' => $total,
        ];
    }

    /**
     * Log requests per day broken down by tool_class, including zero-count days.
     * Returns [['date' => 'Y-m-d', 'total' => int, 'classes' => [class => count]], ...] ASC.
     */
    public static function get_daily_sessions( string $date_from, string $date_to ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(l.created_at) AS date, l.tool_class, COUNT(*) AS cnt
                 FROM {$wpdb->prefix}aicom_logs l
                 INNER JOIN {$wpdb->prefix}aicom_sessions s ON s.id = l.session_id
                 WHERE l.tool_class IS NOT NULL
                   AND DATE(l.created_at) BETWEEN %s AND %s
                 GROUP BY DATE(l.created_at), l.tool_class
                 ORDER BY date ASC",
                $date_from,
                $date_to
            ),
            ARRAY_A
        ) ?: [];

        $by_date = [];
        foreach ( $rows as $row ) {
            $by_date[ $row['date'] ][ $row['tool_class'] ] = (int) $row['cnt'];
        }

        $result  = [];
        $current = strtotime( $date_from );
        $end     = strtotime( $date_to );
        while ( $current <= $end ) {
            $d       = gmdate( 'Y-m-d', $current );
            $classes = $by_date[ $d ] ?? [];
            $result[] = [
                'date'    => $d,
                'total'   => array_sum( $classes ),
                'classes' => $classes,
            ];
            $current = strtotime( '+1 day', $current );
        }

        return $result;
    }

    /**
     * Whether any classified log entries exist before a given date (for graph back-navigation).
     */
    public static function has_logs_before( string $date ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}aicom_logs l
                 INNER JOIN {$wpdb->prefix}aicom_sessions s ON s.id = l.session_id
                 WHERE l.tool_class IS NOT NULL AND DATE(l.created_at) < %s LIMIT 1",
                $date
            )
        );
    }

    /**
     * Today's stats for the dashboard.
     */
    public static function get_stats_today(): array {
        global $wpdb;
        $today = current_time( 'Y-m-d' );

        return [
            'total_today'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_logs WHERE DATE(created_at) = %s", $today ) ),
            'success_today' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_logs WHERE DATE(created_at) = %s AND status = 'success'", $today ) ),
            'errors_today'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_logs WHERE DATE(created_at) = %s AND status = 'error'", $today ) ),
            'blocked_today' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_logs WHERE DATE(created_at) = %s AND (status = 'blocked_soft_lock' OR status = 'blocked_hard_lock')", $today ) ),
        ];
    }
}
