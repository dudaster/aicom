<?php
defined( 'ABSPATH' ) || exit;

( function () {
global $wpdb;

$active_tab  = sanitize_key( wp_unslash( $_GET['tab'] ?? 'dashboard' ) );
$base_url    = admin_url( 'admin.php?page=aicom-backups' );
$tab_url     = fn( string $t ) => add_query_arg( 'tab', $t, $base_url );

$table = $wpdb->prefix . 'aicom_backups';

// ── Dashboard data ─────────────────────────────────────────────────────────

$total_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore
$total_bytes = (int) $wpdb->get_var( "SELECT SUM(LENGTH(payload_json)) FROM $table" ); // phpcs:ignore

$periods = [
    '24h'   => [ 'label' => __( 'Last 24 hours', 'aicom' ), 'interval' => 'INTERVAL 1 DAY' ],
    '7d'    => [ 'label' => __( 'Last 7 days',   'aicom' ), 'interval' => 'INTERVAL 7 DAY' ],
    '30d'   => [ 'label' => __( 'Last 30 days',  'aicom' ), 'interval' => 'INTERVAL 30 DAY' ],
    '1y'    => [ 'label' => __( 'Last year',      'aicom' ), 'interval' => 'INTERVAL 1 YEAR' ],
];

$period_stats = [];
foreach ( $periods as $key => $p ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $row = $wpdb->get_row( "SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(payload_json)),0) AS bytes FROM $table WHERE created_at >= DATE_SUB(NOW(), {$p['interval']})", ARRAY_A );
    $period_stats[ $key ] = [
        'label' => $p['label'],
        'count' => (int) ( $row['cnt']   ?? 0 ),
        'bytes' => (int) ( $row['bytes'] ?? 0 ),
    ];
}

$max_age = (int) get_option( 'aicom_backup_max_age_days', 0 );
$max_mb  = (int) get_option( 'aicom_backup_max_size_mb',  0 );

function aicom_fmt_bytes( int $bytes ): string {
    if ( $bytes <= 0 )          { return '0 B'; }
    if ( $bytes < 1024 )        { return $bytes . ' B'; }
    if ( $bytes < 1048576 )     { return round( $bytes / 1024, 1 ) . ' KB'; }
    return round( $bytes / 1048576, 1 ) . ' MB';
}

// ── Snapshots tab data ─────────────────────────────────────────────────────

$filter_type = sanitize_key( wp_unslash( $_GET['target_type'] ?? '' ) );
$per_page    = 50;
$page_num    = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );

$where  = [ '1=1' ];
$params = [];
if ( $filter_type ) {
    $where[]  = 'target_type = %s';
    $params[] = $filter_type;
}
$where_sql   = implode( ' AND ', $where );
$offset      = ( $page_num - 1 ) * $per_page;

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
$snap_total = empty( $params )
    ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where_sql" )
    : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where_sql", ...$params ) );

$class_labels = [
    'public'          => __( 'Public',         'aicom' ),
    'discovery'       => __( 'Discovery',      'aicom' ),
    'read'            => __( 'Read',           'aicom' ),
    'write'           => __( 'Write',          'aicom' ),
    'destructive'     => __( 'Destructive',    'aicom' ),
    'admin_sensitive' => __( 'Admin Sensitive','aicom' ),
];

$data_params = array_merge( $params, [ $per_page, $offset ] );
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
$backups = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, created_at, tool_name, target_type, target_id,
                session_id, LENGTH(payload_json) AS payload_bytes
         FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d",
        ...$data_params
    ),
    ARRAY_A
);

$num_pages    = (int) ceil( $snap_total / $per_page );
$type_options = [
    ''               => __( 'All Types', 'aicom' ),
    'post'           => __( 'Post', 'aicom' ),
    'term'           => __( 'Term', 'aicom' ),
    'elementor_page' => __( 'Elementor Page', 'aicom' ),
];

// ── Sessions with snapshots (undo-a-whole-session) ─────────────────────────
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$session_summaries = $wpdb->get_results(
    "SELECT b.session_id, COUNT(*) AS backup_count,
            MIN(b.created_at) AS first_at, MAX(b.created_at) AS last_at,
            s.name AS session_name, s.status AS session_status, s.api_key_label
     FROM $table b
     LEFT JOIN {$wpdb->prefix}aicom_sessions s ON s.id = b.session_id
     WHERE b.session_id IS NOT NULL AND b.session_id > 0
     GROUP BY b.session_id
     ORDER BY last_at DESC
     LIMIT 50",
    ARRAY_A
);
?>
<?php include AICOM_DIR . 'admin/partials/layout-top.php'; ?>

    <div class="aicom-page-header">
        <h1><?php esc_html_e( 'Snapshots', 'aicom' ); ?></h1>
        <p class="aicom-page-desc"><?php esc_html_e( 'Snapshots of posts and terms created before AI agent edits.', 'aicom' ); ?></p>
    </div>

    <?php if ( isset( $_GET['deleted'] ) ) : ?>
    <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Backup deleted.', 'aicom' ); ?></p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['settings_saved'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cleanup settings saved.', 'aicom' ); ?></p></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="aicom-tab-bar">
        <a href="<?php echo esc_url( $tab_url( 'dashboard' ) ); ?>"
           class="aicom-tab-btn <?php echo $active_tab === 'dashboard' ? 'is-active' : ''; ?>">
            <?php esc_html_e( 'Dashboard', 'aicom' ); ?>
        </a>
        <a href="<?php echo esc_url( $tab_url( 'cleanup' ) ); ?>"
           class="aicom-tab-btn <?php echo $active_tab === 'cleanup' ? 'is-active' : ''; ?>">
            <?php esc_html_e( 'Cleanup Settings', 'aicom' ); ?>
        </a>
        <a href="<?php echo esc_url( $tab_url( 'snapshots' ) ); ?>"
           class="aicom-tab-btn <?php echo $active_tab === 'snapshots' ? 'is-active' : ''; ?>">
            <?php esc_html_e( 'Backup Snapshots', 'aicom' ); ?>
        </a>
    </div>

    <?php if ( $active_tab === 'dashboard' ) : ?>

    <!-- ── Overview ──────────────────────────────────────────────────────── -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Overview', 'aicom' ); ?></h2>
        </div>
        <div class="aicom-card-body">
            <div class="aicom-stat-grid">

                <div class="aicom-stat-box">
                    <div class="aicom-stat-value"><?php echo number_format( $total_count ); ?></div>
                    <div class="aicom-stat-label"><?php esc_html_e( 'Total backups', 'aicom' ); ?></div>
                </div>

                <div class="aicom-stat-box">
                    <div class="aicom-stat-value"><?php echo esc_html( aicom_fmt_bytes( $total_bytes ) ); ?></div>
                    <div class="aicom-stat-label"><?php esc_html_e( 'Total storage used', 'aicom' ); ?></div>
                </div>

                <div class="aicom-stat-box <?php echo ( $max_age > 0 || $max_mb > 0 ) ? 'is-ok' : 'is-warn'; ?>">
                    <div class="aicom-stat-value" style="font-size:1em;line-height:1.5">
                        <?php if ( $max_age > 0 && $max_mb > 0 ) : ?>
                            <?php
                            /* translators: %d: days */
                            printf( esc_html__( 'Delete after %d days', 'aicom' ), $max_age );
                            echo '<br>';
                            /* translators: %d: MB */
                            printf( esc_html__( 'Cap at %d MB', 'aicom' ), $max_mb );
                            ?>
                        <?php elseif ( $max_age > 0 ) : ?>
                            <?php printf( esc_html__( 'Delete after %d days', 'aicom' ), $max_age ); ?>
                        <?php elseif ( $max_mb > 0 ) : ?>
                            <?php printf( esc_html__( 'Cap at %d MB', 'aicom' ), $max_mb ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'No auto-cleanup', 'aicom' ); ?>
                        <?php endif; ?>
                    </div>
                    <div class="aicom-stat-label">
                        <?php esc_html_e( 'Auto-cleanup', 'aicom' ); ?>
                        <?php if ( $max_age === 0 && $max_mb === 0 ) : ?>
                        — <a href="<?php echo esc_url( $tab_url( 'cleanup' ) ); ?>" style="font-size:0.9em"><?php esc_html_e( 'Configure', 'aicom' ); ?></a>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- ── Activity by period ─────────────────────────────────────────────── -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Backup Activity', 'aicom' ); ?></h2>
        </div>
        <div class="aicom-card-body" style="padding:0">
            <table class="aicom-keys-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Period', 'aicom' ); ?></th>
                        <th style="width:120px"><?php esc_html_e( 'Backups created', 'aicom' ); ?></th>
                        <th style="width:120px"><?php esc_html_e( 'Storage added', 'aicom' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $period_stats as $stat ) : ?>
                    <tr>
                        <td style="font-size:0.88em"><?php echo esc_html( $stat['label'] ); ?></td>
                        <td class="aicom-key-date"><?php echo number_format( $stat['count'] ); ?></td>
                        <td class="aicom-key-date"><?php echo esc_html( aicom_fmt_bytes( $stat['bytes'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ( $active_tab === 'cleanup' ) : ?>

    <!-- ── Cleanup Settings ───────────────────────────────────────────────── -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Cleanup Settings', 'aicom' ); ?></h2>
            <span style="font-size:0.78em;color:var(--aicom-text-sm)"><?php esc_html_e( 'Runs daily via cron. Set 0 to disable.', 'aicom' ); ?></span>
        </div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="aicom_save" />
            <input type="hidden" name="aicom_action" value="save_backup_settings" />
            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>

            <div style="border-top:1px solid var(--aicom-border-light)">

                <div style="display:grid;grid-template-columns:200px 1fr;gap:16px;align-items:start;padding:20px 24px;border-bottom:1px solid var(--aicom-border-light)">
                    <label style="display:flex;align-items:center;gap:8px;font-size:0.92em;font-weight:600;padding-top:2px">
                        <input type="number" name="max_age_days" min="0" style="width:70px" value="<?php echo $max_age; ?>" />
                        <?php esc_html_e( 'days', 'aicom' ); ?>
                    </label>
                    <div>
                        <div style="font-size:0.88em;font-weight:600;margin-bottom:4px"><?php esc_html_e( 'Max age', 'aicom' ); ?></div>
                        <p style="margin:0;font-size:0.8em;color:var(--aicom-text-sm);line-height:1.5">
                            <?php esc_html_e( 'Backups older than this number of days are permanently deleted by the daily cron. Set 0 to keep backups indefinitely.', 'aicom' ); ?>
                        </p>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:200px 1fr;gap:16px;align-items:start;padding:20px 24px;border-bottom:1px solid var(--aicom-border-light)">
                    <label style="display:flex;align-items:center;gap:8px;font-size:0.92em;font-weight:600;padding-top:2px">
                        <input type="number" name="max_size_mb" min="0" style="width:70px" value="<?php echo $max_mb; ?>" />
                        MB
                    </label>
                    <div>
                        <div style="font-size:0.88em;font-weight:600;margin-bottom:4px"><?php esc_html_e( 'Max size', 'aicom' ); ?></div>
                        <p style="margin:0;font-size:0.8em;color:var(--aicom-text-sm);line-height:1.5">
                            <?php esc_html_e( 'When the total size of all backup payloads exceeds this limit, the oldest backups are deleted first until the limit is met. Set 0 to disable size capping.', 'aicom' ); ?>
                        </p>
                    </div>
                </div>

            </div>

            <div style="padding:16px 24px">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'aicom' ); ?></button>
            </div>

        </form>
    </div>

    <?php elseif ( $active_tab === 'snapshots' ) : ?>

    <?php if ( isset( $_GET['restored'] ) ) : ?>
    <?php $restored_count = absint( $_GET['restored'] ); ?>
    <div class="notice notice-success is-dismissible">
        <p>
            <?php
            /* translators: %d: number of backups restored */
            printf( esc_html( _n( '%d backup restored.', '%d backups restored.', $restored_count, 'aicom' ) ), $restored_count );
            ?>
        </p>
    </div>
    <?php endif; ?>
    <?php if ( isset( $_GET['restore_error'] ) ) : ?>
    <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not restore session — invalid session ID.', 'aicom' ); ?></p></div>
    <?php endif; ?>

    <!-- ── Undo a whole session ──────────────────────────────────────────── -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Sessions with Snapshots', 'aicom' ); ?></h2>
            <span style="font-size:0.8em;color:var(--aicom-text-sm)"><?php esc_html_e( 'Restore every post, term, and Elementor page touched during a session back to its pre-session state.', 'aicom' ); ?></span>
        </div>
        <div class="aicom-card-body" style="padding:0">
        <?php if ( empty( $session_summaries ) ) : ?>
            <p style="padding:24px;color:var(--aicom-text-sm);margin:0"><?php esc_html_e( 'No sessions have snapshots yet.', 'aicom' ); ?></p>
        <?php else : ?>
            <table class="aicom-keys-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Session', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'API Key', 'aicom' ); ?></th>
                        <th style="width:110px"><?php esc_html_e( 'Snapshots', 'aicom' ); ?></th>
                        <th style="width:160px"><?php esc_html_e( 'Last activity', 'aicom' ); ?></th>
                        <th style="width:120px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $session_summaries as $sess ) :
                    $sess_id   = (int) $sess['session_id'];
                    $sess_date = substr( (string) $sess['first_at'], 0, 10 );
                    $audit_url = add_query_arg( [ 'page' => 'aicom-audit-logs', 'tab' => 'sessions', 'session_date' => $sess_date, 'highlight_session' => $sess_id ], admin_url( 'admin.php' ) );
                ?>
                    <tr>
                        <td style="font-size:0.86em">
                            <a href="<?php echo esc_url( $audit_url ); ?>">#<?php echo $sess_id; ?> <?php echo esc_html( $sess['session_name'] ?: __( '(untitled)', 'aicom' ) ); ?></a>
                            <?php if ( $sess['session_status'] === 'open' ) : ?><span class="aicom-badge-active" style="margin-left:6px"><?php esc_html_e( 'Active', 'aicom' ); ?></span><?php endif; ?>
                        </td>
                        <td style="font-size:0.84em"><?php echo esc_html( $sess['api_key_label'] ?: '—' ); ?></td>
                        <td class="aicom-key-date"><?php echo (int) $sess['backup_count']; ?></td>
                        <td class="aicom-key-date"><?php echo esc_html( $sess['last_at'] ); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="aicom_save" />
                                <input type="hidden" name="aicom_action" value="restore_session" />
                                <input type="hidden" name="session_id" value="<?php echo $sess_id; ?>" />
                                <input type="hidden" name="_redirect_to" value="backups" />
                                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                <button type="submit" class="button button-small aicom-btn-danger"
                                        onclick="return confirm('<?php
                                            /* translators: %d: backup count */
                                            echo esc_js( sprintf( __( 'Restore %d snapshot(s) from this session? This overwrites current content for every post/term touched in the session.', 'aicom' ), (int) $sess['backup_count'] ) );
                                        ?>')"><?php esc_html_e( 'Restore session', 'aicom' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
    </div>

    <!-- ── Filters ────────────────────────────────────────────────────────── -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Filters', 'aicom' ); ?></h2>
            <?php if ( $filter_type ) : ?>
            <a href="<?php echo esc_url( $tab_url( 'snapshots' ) ); ?>" style="font-size:0.78em;color:var(--aicom-text-sm)"><?php esc_html_e( 'Reset', 'aicom' ); ?></a>
            <?php endif; ?>
        </div>
        <div class="aicom-card-body">
            <form method="get" action="">
                <input type="hidden" name="page" value="aicom-backups" />
                <input type="hidden" name="tab" value="snapshots" />
                <div class="aicom-filter-row">
                    <select name="target_type">
                        <?php foreach ( $type_options as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_type, $val ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'aicom' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Backup Snapshots table ─────────────────────────────────────────── -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Backup Snapshots', 'aicom' ); ?></h2>
            <span style="font-size:0.8em;color:var(--aicom-text-sm)"><?php echo number_format( $snap_total ); ?> <?php esc_html_e( 'total', 'aicom' ); ?></span>
        </div>
        <div class="aicom-card-body" style="padding:0">
        <?php if ( empty( $backups ) ) : ?>
            <p style="padding:24px;color:var(--aicom-text-sm);margin:0">
                <?php $filter_type ? esc_html_e( 'No backups found for the selected type.', 'aicom' ) : esc_html_e( 'No backups found.', 'aicom' ); ?>
            </p>
        <?php else : ?>
            <table class="aicom-keys-table">
                <thead>
                    <tr>
                        <th style="width:50px"><?php esc_html_e( 'ID', 'aicom' ); ?></th>
                        <th style="width:150px"><?php esc_html_e( 'Date', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'Target', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'Tool', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'Class', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'Session', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'Size', 'aicom' ); ?></th>
                        <th style="width:80px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $backups as $backup ) :
                    $kb = $backup['payload_bytes'] ? round( $backup['payload_bytes'] / 1024, 1 ) : 0;
                ?>
                    <tr>
                        <?php
                            $tool_meta = AICOM_Tool_Registry::get( $backup['tool_name'] );
                            $tool_cls  = $tool_meta['class'] ?? '';
                            $sess_id   = (int) ( $backup['session_id'] ?? 0 );
                            $sess_date = $sess_id ? substr( $backup['created_at'], 0, 10 ) : '';
                            $audit_url = $sess_id
                                ? add_query_arg( [ 'page' => 'aicom-audit-logs', 'tab' => 'sessions', 'session_date' => $sess_date, 'highlight_session' => $sess_id ], admin_url( 'admin.php' ) )
                                : '';
                        ?>
                        <td class="aicom-key-date"><?php echo (int) $backup['id']; ?></td>
                        <td class="aicom-key-date"><?php echo esc_html( $backup['created_at'] ); ?></td>
                        <td><code style="font-size:0.78em"><?php echo esc_html( $backup['target_type'] ); ?></code></td>
                        <td style="font-size:0.84em"><?php echo esc_html( $backup['target_id'] ); ?></td>
                        <td><code style="font-size:0.78em"><?php echo esc_html( $backup['tool_name'] ); ?></code></td>
                        <td><?php if ( $tool_cls ) : ?><span class="aicom-class-<?php echo esc_attr( $tool_cls ); ?>" style="font-size:0.72em;padding:2px 7px;border-radius:999px;white-space:nowrap"><?php echo esc_html( $class_labels[ $tool_cls ] ?? $tool_cls ); ?></span><?php else : ?>—<?php endif; ?></td>
                        <td class="aicom-key-date"><?php if ( $audit_url ) : ?><a href="<?php echo esc_url( $audit_url ); ?>" style="font-size:0.82em">#<?php echo $sess_id; ?></a><?php else : ?>—<?php endif; ?></td>
                        <td class="aicom-key-date"><?php echo $kb > 0 ? esc_html( $kb ) . ' KB' : '—'; ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="aicom_save" />
                                <input type="hidden" name="aicom_action" value="delete_backup" />
                                <input type="hidden" name="backup_id" value="<?php echo (int) $backup['id']; ?>" />
                                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                <button type="submit" class="button button-small aicom-btn-danger"
                                        onclick="return confirm('<?php echo esc_js( __( 'Delete this backup? This cannot be undone.', 'aicom' ) ); ?>')"><?php esc_html_e( 'Delete', 'aicom' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $num_pages > 1 ) : ?>
            <div style="padding:12px 16px;border-top:1px solid var(--aicom-border-light);display:flex;gap:4px">
                <?php for ( $p = 1; $p <= $num_pages; $p++ ) :
                    $url = add_query_arg( [ 'tab' => 'snapshots', 'target_type' => $filter_type, 'paged' => $p ], $base_url );
                    $cls = $p === $page_num ? 'button button-primary button-small' : 'button button-small';
                ?>
                <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $cls ); ?>"><?php echo (int) $p; ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

<?php include AICOM_DIR . 'admin/partials/layout-bottom.php'; ?>
<?php
} )();
