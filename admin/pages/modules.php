<?php
defined( 'ABSPATH' ) || exit;

( function () {
$module_status  = AICOM_Module_Detector::get_module_status_map();
$active_modules = AICOM_Module_Detector::get_active_modules();

$all_tools = AICOM_Tool_Registry::get_for_modules( $active_modules );
$tools_per_module = [];
foreach ( $all_tools as $tool_meta ) {
    $mod = $tool_meta['module'];
    $tools_per_module[ $mod ] = ( $tools_per_module[ $mod ] ?? 0 ) + 1;
}

$module_info = [
    'wp_core'     => [ 'label' => __( 'WordPress Core',  'aicom' ), 'description' => __( 'Posts, pages, custom post types, taxonomies, meta, options.',                           'aicom' ), 'required' => null,                        'icon' => 'dashicons-wordpress' ],
    'media'       => [ 'label' => __( 'Media & Files',    'aicom' ), 'description' => __( 'WordPress media library, file upload/list/delete (with allowlist).',                    'aicom' ), 'required' => null,                        'icon' => 'dashicons-format-image' ],
    'users'       => [ 'label' => __( 'Users & Roles',    'aicom' ), 'description' => __( 'User management, role assignment, capabilities. Anti-lockout guards included.',          'aicom' ), 'required' => null,                        'icon' => 'dashicons-admin-users' ],
    'backup'      => [ 'label' => __( 'Backup & Restore', 'aicom' ), 'description' => __( 'Post/term/Elementor backups stored in database. Per-request restore.',                   'aicom' ), 'required' => null,                        'icon' => 'dashicons-backup' ],
    'woocommerce' => [ 'label' => __( 'WooCommerce',      'aicom' ), 'description' => __( 'Products, categories, attributes, stock, price, settings.',                             'aicom' ), 'required' => __( 'WooCommerce plugin', 'aicom' ),        'icon' => 'dashicons-cart' ],
    'elementor'   => [ 'label' => __( 'Elementor',        'aicom' ), 'description' => __( 'Programmatic widget editing via _elementor_data. Batch text update, backup/restore.',   'aicom' ), 'required' => __( 'Elementor plugin', 'aicom' ),          'icon' => 'dashicons-layout' ],
    'polylang'    => [ 'label' => __( 'Polylang',         'aicom' ), 'description' => __( 'Language assignment, translation linking for posts and terms.',                          'aicom' ), 'required' => __( 'Polylang plugin', 'aicom' ),           'icon' => 'dashicons-translation' ],
    'ecs'         => [ 'label' => __( 'ECS',              'aicom' ), 'description' => __( 'Ele Custom Skin — loop skins, color schemes, container layouts for Elementor.',          'aicom' ), 'required' => __( 'ECS plugin', 'aicom' ),                'icon' => 'dashicons-admin-customizer' ],
    'yoast'       => [ 'label' => __( 'Yoast SEO',        'aicom' ), 'description' => __( 'SEO meta, Open Graph, Twitter Card, sitemap, breadcrumbs per post and term.',           'aicom' ), 'required' => __( 'Yoast SEO plugin', 'aicom' ),          'icon' => 'dashicons-chart-bar' ],
    'clautron'    => [ 'label' => __( 'Clautron',         'aicom' ), 'description' => __( 'Blueprint and capability management — catalog, primitives, compile, smoke test.',        'aicom' ), 'required' => __( 'Clautron plugin', 'aicom' ),           'icon' => 'dashicons-superhero' ],
];
?>
<?php include AICOM_DIR . 'admin/partials/layout-top.php'; ?>

    <div class="aicom-page-header">
        <h1><?php esc_html_e( 'Modules', 'aicom' ); ?></h1>
        <p class="aicom-page-desc"><?php esc_html_e( 'Active modules and all registered MCP tools with their scopes and classes.', 'aicom' ); ?></p>
    </div>

    <!-- Module Cards -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Installed Modules', 'aicom' ); ?></h2>
            <span style="font-size:0.8em;color:var(--aicom-text-sm)">
                <?php
                /* translators: %d: number of active modules */
                printf( esc_html( _n( '%d active', '%d active', count( $active_modules ), 'aicom' ) ), count( $active_modules ) );
                ?>
            </span>
        </div>
        <div class="aicom-card-body">
            <div class="aicom-modules-grid">
            <?php foreach ( $module_info as $slug => $info ) :
                $status     = $module_status[ $slug ] ?? 'inactive';
                $is_active  = str_starts_with( $status, 'active' );
                $card_class = $is_active ? 'aicom-module-card-active' : 'aicom-module-card-inactive';
                $tool_count = $tools_per_module[ $slug ] ?? 0;

                $tier_label = match ( $status ) {
                    'active'                 => $info['required'] ?? null,
                    'active_free'            => ( $info['required'] ?? null ) . ' ' . __( '(Free)', 'aicom' ),
                    'active_pro'             => ( $info['required'] ?? null ) . ' ' . __( '(Pro)', 'aicom' ),
                    'active_premium'         => ( $info['required'] ?? null ) . ' ' . __( '(Premium)', 'aicom' ),
                    'installed_no_languages' => __( 'Installed — no languages', 'aicom' ),
                    default                  => $info['required'] ?? null,
                };
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
                        <span class="aicom-module-requires">
                            <?php
                            if ( $is_active ) {
                                echo esc_html( '✓ ' . $tier_label );
                            } else {
                                /* translators: %s: plugin name */
                                printf( esc_html__( '⚠ Requires: %s', 'aicom' ), esc_html( $info['required'] ) );
                            }
                            ?>
                        </span>
                        <?php else : ?>
                        <span class="aicom-module-requires"><?php esc_html_e( 'Always active', 'aicom' ); ?></span>
                        <?php endif; ?>
                        <span class="aicom-module-tools">
                            <?php
                            /* translators: %d: number of tools */
                            printf( esc_html( _n( '%d tool', '%d tools', $tool_count, 'aicom' ) ), (int) $tool_count );
                            ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Tool Registry -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Registered Tools', 'aicom' ); ?></h2>
            <span style="font-size:0.8em;color:var(--aicom-text-sm)">
                <?php
                /* translators: %d: number of tools */
                printf( esc_html( _n( '%d tool', '%d tools', count( $all_tools ), 'aicom' ) ), count( $all_tools ) );
                ?>
            </span>
        </div>
        <div class="aicom-card-body" style="padding:0">
            <table class="aicom-keys-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Tool Name', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'Module', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'Class', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'Required Scopes', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'Flags', 'aicom' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                foreach ( $all_tools as $meta ) :
                    $flags = [];
                    if ( $meta['destructive'] )      $flags[] = 'destructive';
                    if ( $meta['admin_sensitive'] )  $flags[] = 'admin_sensitive';
                    if ( $meta['supports_dry_run'] ) $flags[] = 'dry_run';
                    if ( $meta['requires_confirm'] ) $flags[] = 'confirm';
                ?>
                    <tr>
                        <td><code style="font-size:0.78em"><?php echo esc_html( $meta['tool_name'] ); ?></code></td>
                        <td style="font-size:0.84em;color:var(--aicom-text-sm)"><?php echo esc_html( $meta['module'] ); ?></td>
                        <td><span class="aicom-class-badge aicom-class-<?php echo esc_attr( $meta['class'] ); ?>"><?php echo esc_html( $meta['class'] ); ?></span></td>
                        <td style="font-size:0.8em;color:var(--aicom-text-sm)"><?php echo ! empty( $meta['required_scopes'] ) ? esc_html( implode( ', ', $meta['required_scopes'] ) ) : '&#8212;'; ?></td>
                        <td><?php echo wp_kses( implode( ' ', array_map( fn( $f ) => '<code style="font-size:0.72em">' . esc_html( $f ) . '</code>', $flags ) ), [ 'code' => [ 'style' => [] ] ] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php include AICOM_DIR . 'admin/partials/layout-bottom.php'; ?>
<?php
} )();
