<?php
defined( 'ABSPATH' ) || exit;
global $wpdb;

$filter_type = sanitize_key( $_GET['target_type'] ?? '' );
$per_page    = 50;
$page_num    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

// Build query
$where  = [ '1=1' ];
$params = [];
if ( $filter_type ) {
    $where[]  = 'target_type = %s';
    $params[] = $filter_type;
}

$where_sql = implode( ' AND ', $where );
$offset    = ( $page_num - 1 ) * $per_page;
$table     = $wpdb->prefix . 'aicom_backups';

$count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
$total     = empty( $params ) ? (int) $wpdb->get_var( $count_sql ) : (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );

$data_params = array_merge( $params, [ $per_page, $offset ] );
$data_sql    = "SELECT id, created_at, tool_name, target_type, target_id, manifest_json FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
$backups     = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_params ), ARRAY_A );

$num_pages = (int) ceil( $total / $per_page );
$type_options = [ '' => 'All Types', 'post' => 'Post', 'term' => 'Term', 'elementor_page' => 'Elementor Page' ];
?>
<div class="wrap aicom-wrap">
    <h1>Backups <span class="aicom-count">(<?php echo number_format( $total ); ?> total)</span></h1>

    <?php if ( isset( $_GET['deleted'] ) ) : ?>
    <div class="notice notice-warning is-dismissible"><p>Backup deleted.</p></div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="get" action="" class="aicom-filters">
        <input type="hidden" name="page" value="aicom-backups" />
        <div class="aicom-filter-row">
            <select name="target_type">
                <?php foreach ( $type_options as $val => $label ) : ?>
                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_type, $val ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button">Filter</button>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicom-backups' ) ); ?>" class="button">Reset</a>
        </div>
    </form>

    <?php if ( empty( $backups ) ) : ?>
    <p>No backups found.</p>
    <?php else : ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:50px">ID</th>
                <th style="width:150px">Date</th>
                <th>Type</th>
                <th>Target ID</th>
                <th>Tool</th>
                <th>Manifest</th>
                <th style="width:80px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $backups as $backup ) :
            $manifest = json_decode( $backup['manifest_json'] ?? '{}', true );
        ?>
            <tr>
                <td><?php echo (int) $backup['id']; ?></td>
                <td><?php echo esc_html( $backup['created_at'] ); ?></td>
                <td><code><?php echo esc_html( $backup['target_type'] ); ?></code></td>
                <td><?php echo esc_html( $backup['target_id'] ); ?></td>
                <td><code><?php echo esc_html( $backup['tool_name'] ); ?></code></td>
                <td>
                    <?php if ( $manifest ) : ?>
                    <details>
                        <summary>View</summary>
                        <pre style="font-size:11px;max-height:100px;overflow:auto"><?php echo esc_html( wp_json_encode( $manifest, JSON_PRETTY_PRINT ) ); ?></pre>
                    </details>
                    <?php else : ?>—<?php endif; ?>
                </td>
                <td>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="aicom_save" />
                        <input type="hidden" name="aicom_action" value="delete_backup" />
                        <input type="hidden" name="backup_id" value="<?php echo (int) $backup['id']; ?>" />
                        <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                        <button type="submit" class="button button-small aicom-btn-danger" onclick="return confirm('Delete this backup? This cannot be undone.')">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ( $num_pages > 1 ) : ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php for ( $p = 1; $p <= $num_pages; $p++ ) :
                $url = admin_url( 'admin.php?page=aicom-backups&target_type=' . urlencode( $filter_type ) . '&paged=' . $p );
                $cls = $p === $page_num ? 'button button-primary' : 'button';
            ?>
            <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $cls ); ?>"><?php echo (int) $p; ?></a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>
