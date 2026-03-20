<?php
defined( 'ABSPATH' ) || exit;
global $wpdb;

// ── Filters ────────────────────────────────────────────────────────────
$filter_tool    = sanitize_text_field( $_GET['tool_name'] ?? '' );
$filter_status  = sanitize_key( $_GET['status'] ?? '' );
$filter_key_id  = (int) ( $_GET['api_key_id'] ?? 0 );
$filter_ip      = sanitize_text_field( $_GET['remote_ip'] ?? '' );
$filter_period  = sanitize_key( $_GET['period'] ?? 'today' );
$filter_from    = sanitize_text_field( $_GET['date_from'] ?? '' );
$filter_to      = sanitize_text_field( $_GET['date_to'] ?? '' );
$page_num       = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page       = 50;

// Resolve date range from period preset
if ( $filter_period !== 'custom' ) {
    $now = current_time( 'mysql', true );
    [ $filter_from, $filter_to ] = match ( $filter_period ) {
        'today'   => [ current_time( 'Y-m-d' ) . ' 00:00:00', $now ],
        '7days'   => [ gmdate( 'Y-m-d', strtotime( '-7 days' ) ) . ' 00:00:00', $now ],
        '30days'  => [ gmdate( 'Y-m-d', strtotime( '-30 days' ) ) . ' 00:00:00', $now ],
        'year'    => [ gmdate( 'Y' ) . '-01-01 00:00:00', $now ],
        default   => [ '', '' ],
    };
}

$filters = array_filter( [
    'tool_name'  => $filter_tool,
    'status'     => $filter_status,
    'api_key_id' => $filter_key_id ?: null,
    'remote_ip'  => $filter_ip,
    'date_from'  => $filter_from,
    'date_to'    => $filter_to,
] );

$result    = ACL_Audit_Logger::query( $filters, $per_page, $page_num );
$logs      = $result['items'];
$total     = $result['total'];
$num_pages = (int) ceil( $total / $per_page );

// For key dropdown
$api_keys = $wpdb->get_results( "SELECT id, label, key_prefix FROM {$wpdb->prefix}acl_api_keys ORDER BY label", ARRAY_A );

$status_options = [ '', 'success', 'error', 'blocked_soft_lock', 'blocked_hard_lock', 'denied_scope', 'denied_allowlist', 'validation_failed', 'auth_failed', 'dependency_missing' ];
$period_options = [ 'today' => 'Today', '7days' => 'Last 7 Days', '30days' => 'Last 30 Days', 'year' => 'This Year', 'custom' => 'Custom Range', 'all' => 'All Time' ];

$base_url = admin_url( 'admin.php?page=acl-audit-logs' );
?>
<div class="wrap acl-wrap">
    <h1>Audit Logs <span class="acl-count">(<?php echo number_format( $total ); ?> total)</span></h1>

    <!-- Filters -->
    <form method="get" action="" class="acl-filters">
        <input type="hidden" name="page" value="acl-audit-logs" />

        <div class="acl-filter-row">
            <input type="text" name="tool_name" value="<?php echo esc_attr( $filter_tool ); ?>" placeholder="Tool name..." class="regular-text" />

            <select name="status">
                <?php foreach ( $status_options as $s ) : ?>
                <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filter_status, $s ); ?>>
                    <?php echo $s ? esc_html( $s ) : 'All Statuses'; ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="api_key_id">
                <option value="">All Keys</option>
                <?php foreach ( $api_keys as $k ) : ?>
                <option value="<?php echo (int) $k['id']; ?>" <?php selected( $filter_key_id, $k['id'] ); ?>>
                    <?php echo esc_html( $k['label'] . ' (' . $k['key_prefix'] . '...)' ); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <input type="text" name="remote_ip" value="<?php echo esc_attr( $filter_ip ); ?>" placeholder="IP address..." class="regular-text" />

            <select name="period" id="acl-period-select">
                <?php foreach ( $period_options as $pk => $pl ) : ?>
                <option value="<?php echo esc_attr( $pk ); ?>" <?php selected( $filter_period, $pk ); ?>><?php echo esc_html( $pl ); ?></option>
                <?php endforeach; ?>
            </select>

            <span id="acl-custom-range" <?php echo $filter_period !== 'custom' ? 'style="display:none"' : ''; ?>>
                <input type="datetime-local" name="date_from" value="<?php echo esc_attr( str_replace( ' ', 'T', $filter_from ) ); ?>" />
                <span>to</span>
                <input type="datetime-local" name="date_to" value="<?php echo esc_attr( str_replace( ' ', 'T', $filter_to ) ); ?>" />
            </span>

            <button type="submit" class="button">Filter</button>
            <a href="<?php echo esc_url( $base_url ); ?>" class="button">Reset</a>
        </div>
    </form>

    <!-- Logs Table -->
    <?php if ( empty( $logs ) ) : ?>
    <p>No log entries found.</p>
    <?php else : ?>
    <table class="wp-list-table widefat fixed striped acl-logs-table">
        <thead>
            <tr>
                <th style="width:140px">Time</th>
                <th>Tool</th>
                <th style="width:120px">Status</th>
                <th style="width:80px">Duration</th>
                <th>Key</th>
                <th>IP</th>
                <th>Target</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $logs as $log ) :
            $status_cls = match ( true ) {
                $log['status'] === 'success'              => 'acl-status-active',
                str_starts_with( $log['status'], 'blocked' ) => 'acl-status-warning',
                default                                    => 'acl-status-revoked',
            };
        ?>
            <tr>
                <td><?php echo esc_html( $log['created_at'] ); ?></td>
                <td><code><?php echo esc_html( $log['tool_name'] ); ?></code></td>
                <td><span class="acl-status <?php echo esc_attr( $status_cls ); ?>"><?php echo esc_html( $log['status'] ); ?></span></td>
                <td><?php echo $log['duration_ms'] !== null ? esc_html( $log['duration_ms'] ) . 'ms' : '—'; ?></td>
                <td><?php echo esc_html( $log['api_key_label'] ?: '—' ); ?></td>
                <td><code><?php echo esc_html( $log['remote_ip'] ); ?></code></td>
                <td><?php echo $log['target_type'] ? esc_html( $log['target_type'] . ':' . $log['target_id'] ) : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ( $num_pages > 1 ) : ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            $pagination_args = array_merge( $_GET, [] );
            unset( $pagination_args['paged'] );
            $base = $base_url . '&' . http_build_query( $pagination_args );

            for ( $p = 1; $p <= $num_pages; $p++ ) :
                $url = $base . '&paged=' . $p;
                $cls = $p === $page_num ? 'button button-primary' : 'button';
            ?>
            <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $cls ); ?>"><?php echo (int) $p; ?></a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
