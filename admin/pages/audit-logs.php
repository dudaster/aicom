<?php
defined( 'ABSPATH' ) || exit;

( function () {
global $wpdb;

$active_tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'sessions' ) );

// ── Sessions tab data ──────────────────────────────────────────────────
$graph_offset = absint( wp_unslash( $_GET['graph_offset'] ?? 0 ) );
$graph_to     = gmdate( 'Y-m-d', strtotime( "-{$graph_offset} days" ) );
$graph_from   = gmdate( 'Y-m-d', strtotime( '-' . ( $graph_offset + 29 ) . ' days' ) );
$filter_date        = sanitize_text_field( wp_unslash( $_GET['session_date'] ?? '' ) );
$highlight_session  = absint( wp_unslash( $_GET['highlight_session'] ?? 0 ) );

$daily     = AICOM_Audit_Logger::get_daily_sessions( $graph_from, $graph_to );
$max_count = max( 1, max( array_column( $daily, 'total' ) ?: [ 1 ] ) );
$has_older = AICOM_Audit_Logger::has_logs_before( $graph_from );

$class_order  = [ 'admin_sensitive', 'destructive', 'write', 'read', 'discovery', 'public' ];
$class_labels = [
    'public'          => __( 'Public', 'aicom' ),
    'discovery'       => __( 'Discovery', 'aicom' ),
    'read'            => __( 'Read', 'aicom' ),
    'write'           => __( 'Write', 'aicom' ),
    'destructive'     => __( 'Destructive', 'aicom' ),
    'admin_sensitive' => __( 'Admin Sensitive', 'aicom' ),
];

// Collect which classes actually appear in this window for the legend.
$classes_present = [];
foreach ( $daily as $day ) {
    foreach ( array_keys( $day['classes'] ) as $cls ) {
        $classes_present[ $cls ] = true;
    }
}

if ( $filter_date ) {
    $sessions = AICOM_Sessions::get_all( [ 'log_date' => $filter_date ] );
} else {
    $sessions = AICOM_Sessions::get_all( [ 'date_from' => $graph_from, 'date_to' => $graph_to ] );
}

$session_base_url = add_query_arg( [ 'page' => 'aicom-audit-logs', 'tab' => 'sessions' ], admin_url( 'admin.php' ) );

// ── Logs tab data ──────────────────────────────────────────────────────
$filter_tool       = sanitize_text_field( wp_unslash( $_GET['tool_name'] ?? '' ) );
$filter_status     = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
$filter_key_id     = absint( wp_unslash( $_GET['api_key_id'] ?? 0 ) );
$filter_ip         = sanitize_text_field( wp_unslash( $_GET['remote_ip'] ?? '' ) );
$filter_session_id = absint( wp_unslash( $_GET['session_id'] ?? 0 ) );
$filter_period  = sanitize_key( wp_unslash( $_GET['period'] ?? 'today' ) );
$filter_from    = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
$filter_to      = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) );
$page_num       = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
$per_page       = 50;

if ( $filter_period !== 'custom' ) {
    $now = current_time( 'mysql', true );
    switch ( $filter_period ) {
        case 'today':
            $filter_from = current_time( 'Y-m-d' ) . ' 00:00:00';
            $filter_to   = $now;
            break;
        case '7days':
            $filter_from = gmdate( 'Y-m-d', strtotime( '-7 days' ) ) . ' 00:00:00';
            $filter_to   = $now;
            break;
        case '30days':
            $filter_from = gmdate( 'Y-m-d', strtotime( '-30 days' ) ) . ' 00:00:00';
            $filter_to   = $now;
            break;
        case 'year':
            $filter_from = gmdate( 'Y' ) . '-01-01 00:00:00';
            $filter_to   = $now;
            break;
        default:
            $filter_from = '';
            $filter_to   = '';
    }
}

$filters = array_filter( [
    'tool_name'  => $filter_tool,
    'status'     => $filter_status,
    'api_key_id' => $filter_key_id ?: null,
    'remote_ip'  => $filter_ip,
    'session_id' => $filter_session_id ?: null,
    'date_from'  => $filter_from,
    'date_to'    => $filter_to,
] );

$result    = AICOM_Audit_Logger::query( $filters, $per_page, $page_num );
$logs      = $result['items'];
$total     = $result['total'];
$num_pages = (int) ceil( $total / $per_page );

$api_keys = $wpdb->get_results( "SELECT id, label, key_prefix FROM {$wpdb->prefix}aicom_api_keys ORDER BY label", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$status_options = [ '', 'success', 'error', 'blocked_soft_lock', 'blocked_hard_lock', 'denied_scope', 'denied_allowlist', 'validation_failed', 'auth_failed', 'dependency_missing' ];
$period_options = [
    'today'   => __( 'Today', 'aicom' ),
    '7days'   => __( 'Last 7 Days', 'aicom' ),
    '30days'  => __( 'Last 30 Days', 'aicom' ),
    'year'    => __( 'This Year', 'aicom' ),
    'custom'  => __( 'Custom Range', 'aicom' ),
    'all'     => __( 'All Time', 'aicom' ),
];

$base_url = admin_url( 'admin.php?page=aicom-audit-logs' );
$active_tab_norm = ( $active_tab === 'filters' ) ? 'logs' : $active_tab;
?>
<?php include AICOM_DIR . 'admin/partials/layout-top.php'; ?>

    <div class="aicom-page-header">
        <h1>
            <?php esc_html_e( 'Activity', 'aicom' ); ?>
            <?php if ( $active_tab_norm === 'logs' ) : ?>
            <span class="aicom-count">(<?php echo number_format( $total ); ?> <?php esc_html_e( 'total', 'aicom' ); ?>)</span>
            <?php endif; ?>
        </h1>
        <p class="aicom-page-desc"><?php esc_html_e( 'Every MCP request logged with key, tool, result, and duration.', 'aicom' ); ?></p>
    </div>

    <?php if ( isset( $_GET['restored'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p>
        <?php
        $restored_count = absint( $_GET['restored'] );
        /* translators: %d: number of backups restored */
        printf( esc_html( _n( '%d backup restored.', '%d backups restored.', $restored_count, 'aicom' ) ), $restored_count );
        ?>
    </p></div>
    <?php endif; ?>

    <?php if ( isset( $_GET['restore_error'] ) ) : ?>
    <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not restore session — invalid session ID.', 'aicom' ); ?></p></div>
    <?php endif; ?>

    <!-- Tab bar -->
    <div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid var(--aicom-border);padding-bottom:0">
        <?php
        $tabs = [
            'sessions' => __( 'Sessions', 'aicom' ),
            'logs'     => __( 'Logs', 'aicom' ),
        ];
        foreach ( $tabs as $slug => $label ) :
            $is_active = $active_tab_norm === $slug;
            $tab_url   = add_query_arg( [ 'page' => 'aicom-audit-logs', 'tab' => $slug ], admin_url( 'admin.php' ) );
        ?>
        <a href="<?php echo esc_url( $tab_url ); ?>"
           style="display:inline-block;padding:8px 18px;font-size:0.88em;font-weight:600;text-decoration:none;border-bottom:2px solid <?php echo $is_active ? 'var(--aicom-primary)' : 'transparent'; ?>;color:<?php echo $is_active ? 'var(--aicom-primary)' : 'var(--aicom-text-sm)'; ?>;margin-bottom:-2px;">
            <?php echo esc_html( $label ); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ( $active_tab === 'sessions' ) : ?>

    <!-- Sessions: activity graph -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Activity', 'aicom' ); ?></h2>
            <?php if ( $filter_date ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'graph_offset' => $graph_offset, 'session_date' => '' ], $session_base_url ) ); ?>"
               style="font-size:0.78em;color:var(--aicom-text-sm)"><?php esc_html_e( 'Show all', 'aicom' ); ?></a>
            <?php endif; ?>
        </div>
        <div class="aicom-card-body">
            <!-- Navigation row -->
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
                <?php if ( $has_older ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'graph_offset' => $graph_offset + 30, 'session_date' => '' ], $session_base_url ) ); ?>"
                   class="button button-small" style="flex-shrink:0">&larr; <?php esc_html_e( 'Older', 'aicom' ); ?></a>
                <?php else : ?>
                <span class="button button-small" style="flex-shrink:0;opacity:.35;cursor:default">&larr; <?php esc_html_e( 'Older', 'aicom' ); ?></span>
                <?php endif; ?>

                <span style="flex:1;text-align:center;font-size:0.8em;color:var(--aicom-text-sm)">
                    <?php
                    echo esc_html(
                        gmdate( 'M j', strtotime( $graph_from ) ) . ' – ' .
                        gmdate( 'M j, Y', strtotime( $graph_to ) )
                    );
                    ?>
                </span>

                <?php if ( $graph_offset > 0 ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'graph_offset' => max( 0, $graph_offset - 30 ), 'session_date' => '' ], $session_base_url ) ); ?>"
                   class="button button-small" style="flex-shrink:0"><?php esc_html_e( 'Newer', 'aicom' ); ?> &rarr;</a>
                <?php else : ?>
                <span class="button button-small" style="flex-shrink:0;opacity:.35;cursor:default"><?php esc_html_e( 'Newest', 'aicom' ); ?></span>
                <?php endif; ?>
            </div>

            <!-- Graph bars -->
            <div class="aicom-graph">
                <?php foreach ( $daily as $day ) :
                    $is_empty      = $day['total'] === 0;
                    $pct           = $is_empty ? 0 : max( 8, (int) round( $day['total'] / $max_count * 100 ) );
                    $is_active_bar = $filter_date === $day['date'];
                    $bar_cls       = $is_active_bar ? 'is-active' : ( $is_empty ? 'is-empty' : '' );
                    $tip           = $day['date'] . ': ' . $day['total'] . ' ' . __( 'requests', 'aicom' );
                    $bar_url       = esc_url( add_query_arg( [ 'graph_offset' => $graph_offset, 'session_date' => $day['date'] ], $session_base_url ) );
                    $clear_url     = esc_url( add_query_arg( [ 'graph_offset' => $graph_offset, 'session_date' => '' ], $session_base_url ) );
                ?>
                <div class="aicom-graph-bar <?php echo esc_attr( $bar_cls ); ?>"
                     style="height:<?php echo (int) $pct; ?>%"
                     data-url="<?php echo esc_attr( $is_active_bar ? $clear_url : $bar_url ); ?>"
                     title="<?php echo esc_attr( $tip ); ?>">
                    <?php if ( ! $is_empty ) :
                        foreach ( $class_order as $cls ) :
                            if ( empty( $day['classes'][ $cls ] ) ) { continue; }
                    ?>
                    <div class="aicom-graph-seg seg-<?php echo esc_attr( $cls ); ?>"
                         style="flex:<?php echo (int) $day['classes'][ $cls ]; ?>"></div>
                    <?php
                        endforeach;
                    endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="aicom-graph-dates">
                <span><?php echo esc_html( gmdate( 'M j', strtotime( $graph_from ) ) ); ?></span>
                <span><?php echo esc_html( gmdate( 'M j', strtotime( $graph_to ) ) ); ?></span>
            </div>
            <?php if ( ! empty( $classes_present ) ) : ?>
            <div class="aicom-graph-legend">
                <?php foreach ( $class_order as $cls ) :
                    if ( empty( $classes_present[ $cls ] ) ) { continue; }
                ?>
                <span class="aicom-graph-legend-item">
                    <span class="aicom-graph-legend-dot seg-<?php echo esc_attr( $cls ); ?>"></span>
                    <?php echo esc_html( $class_labels[ $cls ] ?? $cls ); ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sessions list -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title">
                <?php esc_html_e( 'Sessions', 'aicom' ); ?>
                <span style="font-size:0.75em;font-weight:400;color:var(--aicom-text-sm);margin-left:6px"><?php echo count( $sessions ); ?> <?php esc_html_e( 'total', 'aicom' ); ?></span>
            </h2>
        </div>
        <div class="aicom-card-body" style="padding:0 16px 16px">
            <?php if ( empty( $sessions ) ) : ?>
            <p style="padding:16px 0;color:var(--aicom-text-sm);margin:0"><?php esc_html_e( 'No sessions found.', 'aicom' ); ?></p>
            <?php else : ?>
            <?php foreach ( $sessions as $s ) :
                $is_open     = $s['status'] === 'open';
                $session_date = substr( (string) $s['opened_at'], 0, 10 );
                $session_logs = AICOM_Sessions::get_logs( (int) $s['id'] );
            ?>
            <div class="aicom-session <?php echo ( $highlight_session === (int) $s['id'] ) ? 'is-highlighted' : ''; ?>"
                 id="session-<?php echo (int) $s['id']; ?>"
                 data-date="<?php echo esc_attr( $session_date ); ?>">
                <div class="aicom-session-head">
                    <span class="aicom-session-name"><?php echo esc_html( $s['name'] ); ?></span>
                    <?php if ( $is_open ) : ?>
                    <span class="aicom-badge-active"><?php esc_html_e( 'Active', 'aicom' ); ?></span>
                    <?php endif; ?>
                    <span class="aicom-session-meta"><?php echo esc_html( $s['api_key_label'] ); ?> &middot; <?php echo esc_html( substr( (string) $s['opened_at'], 0, 16 ) ); ?></span>
                    <span class="aicom-session-meta"><?php echo (int) $s['request_count']; ?> <?php esc_html_e( 'requests', 'aicom' ); ?> &middot; <?php echo (int) $s['backup_count']; ?> <?php esc_html_e( 'backups', 'aicom' ); ?></span>
                    <?php if ( (int) $s['backup_count'] > 0 ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
                        <input type="hidden" name="action" value="aicom_save" />
                        <input type="hidden" name="aicom_action" value="restore_session" />
                        <input type="hidden" name="session_id" value="<?php echo (int) $s['id']; ?>" />
                        <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                        <button type="submit" class="button button-small"
                                onclick="return confirm('<?php
                                    /* translators: %d: backup count */
                                    echo esc_js( sprintf( __( 'Restore %d backup(s) from this session? This will overwrite current content.', 'aicom' ), (int) $s['backup_count'] ) );
                                ?>')"><?php esc_html_e( 'Restore', 'aicom' ); ?></button>
                    </form>
                    <?php endif; ?>
                    <span class="aicom-session-toggle" style="font-size:0.75em;color:var(--aicom-text-xs);flex-shrink:0">&#9660;</span>
                </div>
                <?php if ( ! empty( $s['description'] ) || ! empty( $session_logs ) ) : ?>
                <div class="aicom-session-body">
                    <?php if ( ! empty( $s['description'] ) ) : ?>
                    <p class="aicom-session-desc"><?php echo esc_html( $s['description'] ); ?></p>
                    <?php endif; ?>
                    <?php if ( ! empty( $session_logs ) ) : ?>
                    <table class="aicom-keys-table" style="margin:0">
                        <thead>
                            <tr>
                                <th style="width:140px"><?php esc_html_e( 'Time', 'aicom' ); ?></th>
                                <th><?php esc_html_e( 'Tool', 'aicom' ); ?></th>
                                <th style="width:110px"><?php esc_html_e( 'Class', 'aicom' ); ?></th>
                                <th style="width:100px"><?php esc_html_e( 'Status', 'aicom' ); ?></th>
                                <th style="width:70px"><?php esc_html_e( 'Duration', 'aicom' ); ?></th>
                                <th><?php esc_html_e( 'Target', 'aicom' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $session_logs as $log ) :
                            if ( $log['status'] === 'success' ) {
                                $status_cls = 'aicom-status-active';
                            } elseif ( strpos( $log['status'], 'blocked' ) === 0 ) {
                                $status_cls = 'aicom-status-warning';
                            } else {
                                $status_cls = 'aicom-status-revoked';
                            }
                            $tool_cls = $log['tool_class'] ?? '';
                        ?>
                            <tr>
                                <td class="aicom-key-date"><?php echo esc_html( substr( (string) $log['created_at'], 0, 16 ) ); ?></td>
                                <td><code style="font-size:0.78em"><?php echo esc_html( $log['tool_name'] ); ?></code></td>
                                <td><?php if ( $tool_cls ) : ?><span class="aicom-class-<?php echo esc_attr( $tool_cls ); ?>" style="font-size:0.72em;padding:2px 7px;border-radius:999px;white-space:nowrap"><?php echo esc_html( $class_labels[ $tool_cls ] ?? $tool_cls ); ?></span><?php else : ?>—<?php endif; ?></td>
                                <td><span class="aicom-status <?php echo esc_attr( $status_cls ); ?>"><?php echo esc_html( $log['status'] ); ?></span></td>
                                <td class="aicom-key-date"><?php echo $log['duration_ms'] !== null ? esc_html( $log['duration_ms'] ) . 'ms' : '—'; ?></td>
                                <td class="aicom-key-date"><?php echo $log['target_type'] ? esc_html( $log['target_type'] . ':' . $log['target_id'] ) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ( $active_tab === 'logs' || $active_tab === 'filters' ) : ?>

    <!-- Logs (with filters) -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Filter', 'aicom' ); ?></h2>
            <?php if ( $filter_tool || $filter_status || $filter_key_id || $filter_ip || $filter_session_id || $filter_period !== 'today' ) : ?>
            <a href="<?php echo esc_url( $base_url ); ?>" style="font-size:0.78em;color:var(--aicom-text-sm)"><?php esc_html_e( 'Reset filters', 'aicom' ); ?></a>
            <?php endif; ?>
        </div>
        <div class="aicom-card-body">
            <form method="get" action="">
                <input type="hidden" name="page" value="aicom-audit-logs" />
                <input type="hidden" name="tab" value="logs" />
                <div class="aicom-filter-row">
                    <input type="text" name="tool_name" value="<?php echo esc_attr( $filter_tool ); ?>" placeholder="<?php esc_attr_e( 'Tool name…', 'aicom' ); ?>" />

                    <select name="status">
                        <?php foreach ( $status_options as $s ) : ?>
                        <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filter_status, $s ); ?>>
                            <?php echo $s ? esc_html( $s ) : esc_html__( 'All Statuses', 'aicom' ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="api_key_id">
                        <option value=""><?php esc_html_e( 'All Keys', 'aicom' ); ?></option>
                        <?php foreach ( $api_keys as $k ) : ?>
                        <option value="<?php echo (int) $k['id']; ?>" <?php selected( $filter_key_id, $k['id'] ); ?>>
                            <?php echo esc_html( $k['label'] . ' (' . $k['key_prefix'] . '…)' ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="text" name="remote_ip" value="<?php echo esc_attr( $filter_ip ); ?>" placeholder="<?php esc_attr_e( 'IP address…', 'aicom' ); ?>" />

                    <select name="session_id">
                        <option value=""><?php esc_html_e( 'All Sessions', 'aicom' ); ?></option>
                        <?php foreach ( AICOM_Sessions::get_all() as $sess ) : ?>
                        <option value="<?php echo (int) $sess['id']; ?>" <?php selected( $filter_session_id, $sess['id'] ); ?>>
                            <?php echo esc_html( '#' . $sess['id'] . ' — ' . $sess['name'] . ' (' . substr( $sess['opened_at'], 0, 10 ) . ')' ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="period" id="aicom-period-select">
                        <?php foreach ( $period_options as $pk => $pl ) : ?>
                        <option value="<?php echo esc_attr( $pk ); ?>" <?php selected( $filter_period, $pk ); ?>><?php echo esc_html( $pl ); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <span id="aicom-custom-range" <?php echo $filter_period !== 'custom' ? 'style="display:none"' : ''; ?>>
                        <input type="datetime-local" name="date_from" value="<?php echo esc_attr( str_replace( ' ', 'T', $filter_from ) ); ?>" />
                        <span><?php esc_html_e( 'to', 'aicom' ); ?></span>
                        <input type="datetime-local" name="date_to" value="<?php echo esc_attr( str_replace( ' ', 'T', $filter_to ) ); ?>" />
                    </span>

                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'aicom' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Log Entries', 'aicom' ); ?></h2>
            <span style="font-size:0.8em;color:var(--aicom-text-sm)">
                <?php
                /* translators: %s: formatted number */
                printf( esc_html( _n( '%s result', '%s results', $total, 'aicom' ) ), number_format( $total ) );
                ?>
            </span>
        </div>
        <div class="aicom-card-body" style="padding:0">
        <?php if ( empty( $logs ) ) : ?>
            <p style="padding:24px;color:var(--aicom-text-sm);margin:0"><?php esc_html_e( 'No log entries found for the selected filters.', 'aicom' ); ?></p>
        <?php else : ?>
            <table class="aicom-keys-table aicom-logs-table">
                <thead>
                    <tr>
                        <th style="width:140px"><?php esc_html_e( 'Time', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'Tool', 'aicom' ); ?></th>
                        <th style="width:130px"><?php esc_html_e( 'Status', 'aicom' ); ?></th>
                        <th style="width:80px"><?php esc_html_e( 'Duration', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'Key', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'IP', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'Target', 'aicom' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $logs as $log ) :
                    if ( $log['status'] === 'success' ) {
                        $status_cls = 'aicom-status-active';
                    } elseif ( strpos( $log['status'], 'blocked' ) === 0 ) {
                        $status_cls = 'aicom-status-warning';
                    } else {
                        $status_cls = 'aicom-status-revoked';
                    }
                ?>
                    <tr>
                        <td class="aicom-key-date"><?php echo esc_html( $log['created_at'] ); ?></td>
                        <td><code style="font-size:0.78em"><?php echo esc_html( $log['tool_name'] ); ?></code></td>
                        <td><span class="aicom-status <?php echo esc_attr( $status_cls ); ?>"><?php echo esc_html( $log['status'] ); ?></span></td>
                        <td class="aicom-key-date"><?php echo $log['duration_ms'] !== null ? esc_html( $log['duration_ms'] ) . 'ms' : '—'; ?></td>
                        <td style="font-size:0.84em"><?php echo esc_html( $log['api_key_label'] ?: '—' ); ?></td>
                        <td><code style="font-size:0.75em"><?php echo esc_html( $log['remote_ip'] ); ?></code></td>
                        <td class="aicom-key-date"><?php echo $log['target_type'] ? esc_html( $log['target_type'] . ':' . $log['target_id'] ) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $num_pages > 1 ) :
                $pagination_args = array_filter( [
                    'page'       => 'aicom-audit-logs',
                    'tab'        => 'logs',
                    'tool_name'  => $filter_tool,
                    'status'     => $filter_status,
                    'api_key_id' => $filter_key_id ?: null,
                    'remote_ip'  => $filter_ip,
                    'period'     => $filter_period,
                    'date_from'  => $filter_from,
                    'date_to'    => $filter_to,
                ] );
                $base = admin_url( 'admin.php' ) . '?' . http_build_query( $pagination_args );
                $page_url = fn( $p ) => $base . '&paged=' . (int) $p;

                // Build the page list with ellipsis. Always show first + last,
                // current and its immediate neighbours; collapse gaps with "…".
                $pages_to_show = [];
                if ( $num_pages <= 7 ) {
                    for ( $i = 1; $i <= $num_pages; $i++ ) { $pages_to_show[] = $i; }
                } else {
                    $pages_to_show[] = 1;
                    if ( $page_num - 1 > 2 ) { $pages_to_show[] = '…'; }
                    for ( $i = max( 2, $page_num - 1 ); $i <= min( $num_pages - 1, $page_num + 1 ); $i++ ) {
                        $pages_to_show[] = $i;
                    }
                    if ( $page_num + 1 < $num_pages - 1 ) { $pages_to_show[] = '…'; }
                    $pages_to_show[] = $num_pages;
                }
            ?>
            <nav class="aicom-pagination" role="navigation" aria-label="<?php esc_attr_e( 'Pagination', 'aicom' ); ?>">
                <?php if ( $page_num > 1 ) : ?>
                    <a class="aicom-pagination-step" href="<?php echo esc_url( $page_url( $page_num - 1 ) ); ?>" rel="prev" aria-label="<?php esc_attr_e( 'Previous page', 'aicom' ); ?>">
                        <span aria-hidden="true">‹</span> <?php esc_html_e( 'Back', 'aicom' ); ?>
                    </a>
                <?php else : ?>
                    <span class="aicom-pagination-step is-disabled" aria-disabled="true">
                        <span aria-hidden="true">‹</span> <?php esc_html_e( 'Back', 'aicom' ); ?>
                    </span>
                <?php endif; ?>

                <span class="aicom-pagination-pages">
                    <?php foreach ( $pages_to_show as $entry ) : ?>
                        <?php if ( $entry === '…' ) : ?>
                            <span class="aicom-pagination-gap" aria-hidden="true">…</span>
                        <?php elseif ( (int) $entry === (int) $page_num ) : ?>
                            <span class="aicom-pagination-num is-current" aria-current="page"><?php echo (int) $entry; ?></span>
                        <?php else : ?>
                            <a class="aicom-pagination-num" href="<?php echo esc_url( $page_url( $entry ) ); ?>"><?php echo (int) $entry; ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </span>

                <?php if ( $page_num < $num_pages ) : ?>
                    <a class="aicom-pagination-step" href="<?php echo esc_url( $page_url( $page_num + 1 ) ); ?>" rel="next" aria-label="<?php esc_attr_e( 'Next page', 'aicom' ); ?>">
                        <?php esc_html_e( 'Next', 'aicom' ); ?> <span aria-hidden="true">›</span>
                    </a>
                <?php else : ?>
                    <span class="aicom-pagination-step is-disabled" aria-disabled="true">
                        <?php esc_html_e( 'Next', 'aicom' ); ?> <span aria-hidden="true">›</span>
                    </span>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

<?php include AICOM_DIR . 'admin/partials/layout-bottom.php'; ?>
<?php
} )();
