<?php
defined( 'ABSPATH' ) || exit;

$module_status = AICOM_Module_Detector::get_module_status_map();
$active_modules = AICOM_Module_Detector::get_active_modules();

// Count registered tools per module
$all_tools = AICOM_Tool_Registry::get_for_modules( $active_modules );
$tools_per_module = [];
foreach ( $all_tools as $tool_meta ) {
    $mod = $tool_meta['module'];
    $tools_per_module[ $mod ] = ( $tools_per_module[ $mod ] ?? 0 ) + 1;
}

$module_info = [
    'wp_core'     => [
        'label'       => 'WordPress Core',
        'description' => 'Posts, pages, custom post types, taxonomies, meta, options.',
        'required'    => null,
        'icon'        => 'dashicons-wordpress',
    ],
    'media'       => [
        'label'       => 'Media & Files',
        'description' => 'WordPress media library, file upload/list/delete (with allowlist).',
        'required'    => null,
        'icon'        => 'dashicons-format-image',
    ],
    'users'       => [
        'label'       => 'Users & Roles',
        'description' => 'User management, role assignment, capabilities. Anti-lockout guards included.',
        'required'    => null,
        'icon'        => 'dashicons-admin-users',
    ],
    'backup'      => [
        'label'       => 'Backup & Restore',
        'description' => 'Post/term/Elementor backups stored in database. Per-request restore.',
        'required'    => null,
        'icon'        => 'dashicons-backup',
    ],
    'woocommerce' => [
        'label'       => 'WooCommerce',
        'description' => 'Products, categories, attributes, stock, price, settings.',
        'required'    => 'WooCommerce plugin',
        'icon'        => 'dashicons-cart',
    ],
    'elementor'   => [
        'label'       => 'Elementor',
        'description' => 'Programmatic widget editing via _elementor_data. Batch text update, backup/restore.',
        'required'    => 'Elementor plugin',
        'icon'        => 'dashicons-layout',
    ],
    'polylang'    => [
        'label'       => 'Polylang',
        'description' => 'Language assignment, translation linking for posts and terms.',
        'required'    => 'Polylang plugin',
        'icon'        => 'dashicons-translation',
    ],
];
?>
<div class="wrap aicom-wrap">
    <h1>Modules</h1>

    <div class="aicom-modules-grid">
    <?php foreach ( $module_info as $slug => $info ) :
        $status      = $module_status[ $slug ] ?? 'inactive';
        $is_active   = $status === 'active';
        $card_class  = $is_active ? 'aicom-module-card-active' : 'aicom-module-card-inactive';
        $tool_count  = $tools_per_module[ $slug ] ?? 0;
    ?>
        <div class="aicom-module-card <?php echo esc_attr( $card_class ); ?>">
            <div class="aicom-module-header">
                <span class="dashicons <?php echo esc_attr( $info['icon'] ); ?>"></span>
                <h3><?php echo esc_html( $info['label'] ); ?></h3>
                <span class="aicom-module-status-dot <?php echo esc_attr( $is_active ? 'aicom-dot-active' : 'aicom-dot-inactive' ); ?>"></span>
            </div>
            <p class="aicom-module-desc"><?php echo esc_html( $info['description'] ); ?></p>
            <div class="aicom-module-meta">
                <?php if ( $info['required'] ) : ?>
                <span class="aicom-module-requires"><?php echo esc_html( $is_active ? '✓ ' . $info['required'] : '⚠ Requires: ' . $info['required'] ); ?></span>
                <?php else : ?>
                <span class="aicom-module-requires">Always active</span>
                <?php endif; ?>
                <span class="aicom-module-tools"><?php echo (int) $tool_count; ?> tool<?php echo $tool_count !== 1 ? 's' : ''; ?></span>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <!-- Tool List per Module -->
    <div class="aicom-card">
        <h2>All Registered Tools</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Tool Name</th>
                    <th>Module</th>
                    <th>Class</th>
                    <th>Required Scopes</th>
                    <th>Flags</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $all = AICOM_Tool_Registry::get_for_modules( $active_modules );
            foreach ( $all as $meta ) :
                $flags = [];
                if ( $meta['destructive'] )      $flags[] = 'destructive';
                if ( $meta['admin_sensitive'] )  $flags[] = 'admin_sensitive';
                if ( $meta['supports_dry_run'] ) $flags[] = 'dry_run';
                if ( $meta['requires_confirm'] ) $flags[] = 'confirm_required';
            ?>
                <tr>
                    <td><code><?php echo esc_html( $meta['tool_name'] ); ?></code></td>
                    <td><?php echo esc_html( $meta['module'] ); ?></td>
                    <td><span class="aicom-class-badge aicom-class-<?php echo esc_attr( $meta['class'] ); ?>"><?php echo esc_html( $meta['class'] ); ?></span></td>
                    <td><?php echo ! empty( $meta['required_scopes'] ) ? esc_html( implode( ', ', $meta['required_scopes'] ) ) : '&#8212;'; ?></td>
                    <td><?php echo wp_kses( implode( ' ', array_map( fn( $f ) => '<code>' . esc_html( $f ) . '</code>', $flags ) ), [ 'code' => [] ] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
