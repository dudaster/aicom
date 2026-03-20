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
            'remote_ip'           => '0.0.0.0',
            'api_key_id'          => null,
            'api_key_label'       => null,
            'tool_name'           => 'unknown',
            'module'              => 'unknown',
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

        return (int) $wpdb->insert_id;
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

        // Count query (no ORDER BY or LIMIT)
        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
        $total     = empty( $params )
            ? (int) $wpdb->get_var( $count_sql )
            : (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );

        // Data query
        $data_params   = array_merge( $params, [ $per_page, $offset ] );
        $data_sql      = "SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $items         = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_params ), ARRAY_A );

        return [
            'items' => $items ?: [],
            'total' => $total,
        ];
    }

    /**
     * Today's stats for the dashboard.
     */
    public static function get_stats_today(): array {
        global $wpdb;
        $today = current_time( 'Y-m-d' );
        $t     = self::table();

        return [
            'total_today'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE DATE(created_at) = %s", $today ) ),
            'success_today' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE DATE(created_at) = %s AND status = 'success'", $today ) ),
            'errors_today'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE DATE(created_at) = %s AND status = 'error'", $today ) ),
            'blocked_today' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE DATE(created_at) = %s AND (status = 'blocked_soft_lock' OR status = 'blocked_hard_lock')", $today ) ),
        ];
    }
}
