<?php
defined( 'ABSPATH' ) || exit;
global $wpdb;

$table = $wpdb->prefix . 'aicom_api_keys';

// Check for one-time display of a newly created/rotated key
$new_key_id  = (int) ( $_GET['created'] ?? $_GET['rotated'] ?? 0 );
$plain_key   = $new_key_id ? get_transient( 'aicom_new_key_' . $new_key_id ) : null;
if ( $plain_key ) {
    delete_transient( 'aicom_new_key_' . $new_key_id );
}

// All available scopes from spec
$all_scopes = [
    'read.wp'                         => 'Read WP (posts, terms, meta)',
    'write.wp.posts'                   => 'Write WP Posts',
    'delete.wp.posts'                  => 'Delete WP Posts',
    'manage.taxonomies'                => 'Manage Taxonomies',
    'manage.meta'                      => 'Manage Post Meta',
    'manage.elementor'                 => 'Manage Elementor',
    'manage.polylang'                  => 'Manage Polylang',
    'manage.woocommerce.products'      => 'WooCommerce Products',
    'manage.woocommerce.settings'      => 'WooCommerce Settings',
    'manage.media'                     => 'Media Library',
    'manage.files'                     => 'File System (restricted)',
    'read.users'                       => 'Read Users',
    'manage.users'                     => 'Manage Users',
    'delete.users'                     => 'Delete Users',
    'manage.roles'                     => 'Manage Roles',
    'manage.menus'                     => 'Nav Menus',
    'manage.backups'                   => 'Backup & Restore',
    'manage.wordpress.settings'        => 'WP Options/Settings',
];

$keys = $wpdb->get_results( "SELECT id, label, key_prefix, scopes_json, status, last_used_at, created_at FROM $table ORDER BY created_at DESC", ARRAY_A );
?>
<div class="wrap aicom-wrap">
    <h1>API Keys</h1>

    <?php if ( $plain_key ) : ?>
    <div class="notice notice-success aicom-notice-key">
        <h3>✓ Key <?php echo esc_html( $new_key_id ? 'rotated' : 'created' ); ?> — copy now, will not be shown again</h3>
        <div class="aicom-copy-row">
            <input type="text" readonly value="<?php echo esc_attr( $plain_key ); ?>" class="large-text aicom-copy-input" id="aicom-plain-key" />
            <button class="button aicom-copy-btn" data-target="<?php echo esc_attr( $plain_key ); ?>">Copy Key</button>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['revoked'] ) ) : ?>
    <div class="notice notice-warning is-dismissible"><p>Key revoked.</p></div>
    <?php endif; ?>

    <!-- Create New Key -->
    <div class="aicom-card">
        <h2>Generate New API Key</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="aicom_save" />
            <input type="hidden" name="aicom_action" value="create_key" />
            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="aicom-key-label">Label</label></th>
                    <td>
                        <input type="text" name="label" id="aicom-key-label" class="regular-text" required placeholder="e.g. OpenClaw Production" />
                    </td>
                </tr>
                <tr>
                    <th>Scopes</th>
                    <td>
                        <label class="aicom-check-all-label">
                            <input type="checkbox" id="aicom-scope-check-all" />
                            <strong>Check all</strong>
                        </label>
                        <div class="aicom-scope-grid">
                        <?php foreach ( $all_scopes as $scope_key => $scope_label ) : ?>
                            <label class="aicom-scope-label">
                                <input type="checkbox" name="scopes[]" value="<?php echo esc_attr( $scope_key ); ?>" class="aicom-scope-cb" />
                                <span><?php echo esc_html( $scope_label ); ?></span>
                                <code class="aicom-scope-code"><?php echo esc_html( $scope_key ); ?></code>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>IP Allowlist</th>
                    <td>
                        <textarea name="ip_allowlist" class="large-text" rows="3" placeholder="One IP or CIDR per line. Leave empty to allow all."></textarea>
                        <p class="description">e.g. 203.0.113.5 or 192.168.1.0/24</p>
                    </td>
                </tr>
                <tr>
                    <th>Dry-Run Only</th>
                    <td>
                        <label>
                            <input type="checkbox" name="dry_run_only" value="1" />
                            Force all write operations to dry-run mode for this key
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Generate Key', 'primary', 'submit', false ); ?>
        </form>
    </div>

    <!-- Existing Keys Table -->
    <div class="aicom-card">
        <h2>Existing Keys</h2>
        <?php if ( empty( $keys ) ) : ?>
        <p>No API keys yet.</p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Label</th>
                    <th>Prefix</th>
                    <th>Status</th>
                    <th>Scopes</th>
                    <th>Last Used</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $keys as $key ) :
                $scopes = json_decode( $key['scopes_json'], true ) ?: [];
                $status_class = $key['status'] === 'active' ? 'aicom-status-active' : 'aicom-status-revoked';
            ?>
                <tr>
                    <td><strong><?php echo esc_html( $key['label'] ); ?></strong></td>
                    <td><code><?php echo esc_html( $key['key_prefix'] ); ?>...</code></td>
                    <td><span class="aicom-status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $key['status'] ); ?></span></td>
                    <td>
                        <?php if ( empty( $scopes ) ) : ?>
                        <em>none</em>
                        <?php else : ?>
                        <details>
                            <summary><?php echo count( $scopes ); ?> scope(s)</summary>
                            <?php foreach ( $scopes as $s ) : ?><code><?php echo esc_html( $s ); ?></code><br/><?php endforeach; ?>
                        </details>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $key['last_used_at'] ? esc_html( $key['last_used_at'] ) : '—'; ?></td>
                    <td><?php echo esc_html( $key['created_at'] ); ?></td>
                    <td>
                        <?php if ( $key['status'] === 'active' ) : ?>
                        <!-- Rotate -->
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                            <input type="hidden" name="action" value="aicom_save" />
                            <input type="hidden" name="aicom_action" value="rotate_key" />
                            <input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>" />
                            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                            <button type="submit" class="button button-small" onclick="return confirm('Rotate this key? The old key will stop working immediately.')">Rotate</button>
                        </form>
                        <!-- Revoke -->
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                            <input type="hidden" name="action" value="aicom_save" />
                            <input type="hidden" name="aicom_action" value="revoke_key" />
                            <input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>" />
                            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                            <button type="submit" class="button button-small aicom-btn-danger" onclick="return confirm('Revoke this key? This cannot be undone.')">Revoke</button>
                        </form>
                        <?php else : ?>
                        <em>—</em>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
