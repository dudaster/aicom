<?php
defined( 'ABSPATH' ) || exit;

( function () {
global $wpdb;

$created    = isset( $_GET['created'] )    ? absint( wp_unslash( $_GET['created'] ) )    : 0;
$rotated    = isset( $_GET['rotated'] )    ? absint( wp_unslash( $_GET['rotated'] ) )    : 0;
$edited     = isset( $_GET['edited'] )     ? absint( wp_unslash( $_GET['edited'] ) )     : 0;
$archived   = isset( $_GET['archived'] )   ? absint( wp_unslash( $_GET['archived'] ) )   : 0;
$unarchived = isset( $_GET['unarchived'] ) ? absint( wp_unslash( $_GET['unarchived'] ) ) : 0;
$edit_diff = null;
if ( $edited ) {
    $edit_diff_json = get_transient( 'aicom_edit_result_' . $edited );
    delete_transient( 'aicom_edit_result_' . $edited );
    $edit_diff = $edit_diff_json ? json_decode( $edit_diff_json, true ) : [];
}

// ── Scope tree (single source of truth: AICOM_Auth::scope_tree) ────────────
$scope_tree      = AICOM_Auth::scope_tree();
$scope_tree_flat = AICOM_Auth::scope_flat();

// Implications map for UI hints — when a manage.X checkbox is ticked, the
// matching read.X row gets a small "included automatically" note rendered
// underneath so users see the relationship.
$scope_implications = AICOM_Auth::scope_implications();
$scope_implied_by   = [];
foreach ( $scope_implications as $write_scope => $implied_read ) {
    $scope_implied_by[ $implied_read ][] = $write_scope;
}
$all_scope_keys  = AICOM_Auth::scope_slugs();

// ── System presets ─────────────────────────────────────────────────────────
$presets = AICOM_Admin::system_presets();

// ── Working Hours helper ───────────────────────────────────────────────────
// Renders the same field group in both Generate and Edit forms. Site uses the
// short day labels from Safety; we mirror them here so the UI feels familiar.
$day_labels = [
    0 => __( 'Sun', 'aicom' ),
    1 => __( 'Mon', 'aicom' ),
    2 => __( 'Tue', 'aicom' ),
    3 => __( 'Wed', 'aicom' ),
    4 => __( 'Thu', 'aicom' ),
    5 => __( 'Fri', 'aicom' ),
    6 => __( 'Sat', 'aicom' ),
];
$render_working_hours = function ( bool $enabled, array $days, string $start, string $end ) use ( $day_labels ) {
    ?>
    <div class="aicom-field-row aicom-wh-row<?php echo $enabled ? ' is-enabled' : ''; ?>">
        <label class="aicom-field-label"><?php esc_html_e( 'Working Hours', 'aicom' ); ?><br><small><?php esc_html_e( 'Optional', 'aicom' ); ?></small></label>
        <div class="aicom-field-control">
            <label class="aicom-toggle-label">
                <input type="checkbox" name="working_hours_enabled" value="1" class="aicom-wh-toggle" <?php checked( $enabled ); ?> />
                <?php esc_html_e( 'Restrict this key to a daily time window', 'aicom' ); ?>
            </label>
            <div class="aicom-wh-fields" hidden style="margin-top:10px;padding:12px 14px;background:var(--aicom-bg);border:1px dashed var(--aicom-border);border-radius:6px">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px">
                    <span class="aicom-field-desc" style="margin:0;font-weight:600;color:var(--aicom-text-md)"><?php esc_html_e( 'Days', 'aicom' ); ?></span>
                    <?php foreach ( $day_labels as $d_num => $d_label ) : ?>
                        <label style="display:inline-flex;align-items:center;gap:4px;font-size:0.85em;cursor:pointer;padding:2px 6px;border-radius:4px;background:var(--aicom-surface);border:1px solid var(--aicom-border)">
                            <input type="checkbox" name="working_hours_days[]" value="<?php echo (int) $d_num; ?>"
                                <?php checked( in_array( $d_num, $days, true ) ); ?> />
                            <span><?php echo esc_html( $d_label ); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex;align-items:center;gap:10px">
                    <span class="aicom-field-desc" style="margin:0;font-weight:600;color:var(--aicom-text-md)"><?php esc_html_e( 'From', 'aicom' ); ?></span>
                    <input type="time" name="working_hours_start" value="<?php echo esc_attr( $start ); ?>"
                           style="font-size:0.88em;padding:5px 8px;border:1px solid var(--aicom-border);border-radius:4px" />
                    <span class="aicom-field-desc" style="margin:0;color:var(--aicom-text-sm)"><?php esc_html_e( 'to', 'aicom' ); ?></span>
                    <input type="time" name="working_hours_end" value="<?php echo esc_attr( $end ); ?>"
                           style="font-size:0.88em;padding:5px 8px;border:1px solid var(--aicom-border);border-radius:4px" />
                </div>
            </div>
            <p class="aicom-field-desc">
                <?php
                printf(
                    /* translators: %s = site timezone */
                    esc_html__( 'Requests outside this window return 403. Site timezone: %s', 'aicom' ),
                    esc_html( wp_timezone_string() )
                );
                ?>
            </p>
        </div>
    </div>
    <?php
};

// ── Custom presets from DB ─────────────────────────────────────────────────
$custom_presets = $wpdb->get_results(
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    "SELECT id, name, scopes_json FROM {$wpdb->prefix}aicom_presets ORDER BY created_at ASC",
    ARRAY_A
) ?: [];

// ── Edit mode ─────────────────────────────────────────────────────────────
$edit_key_id = ( isset( $_GET['action'], $_GET['key_id'] ) && $_GET['action'] === 'edit_key' )
    ? absint( wp_unslash( $_GET['key_id'] ) ) : 0;
$edit_key = $edit_key_id
    ? $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d", $edit_key_id
    ), ARRAY_A )
    : null;

$show_archived = ! empty( $_GET['show_archived'] );
$archived_sql  = $show_archived ? '' : "AND status != 'archived'";

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$keys = $wpdb->get_results(
    "SELECT id, label, key_prefix, scopes_json, restrictions_json, status, last_used_at, created_at, expires_at FROM {$wpdb->prefix}aicom_api_keys WHERE 1=1 $archived_sql ORDER BY created_at DESC",
    ARRAY_A
);
?>
<?php include AICOM_DIR . 'admin/partials/layout-top.php'; ?>

    <!-- Page header -->
    <div class="aicom-page-header">
        <h1><?php esc_html_e( 'Manage API Keys', 'aicom' ); ?></h1>
        <p class="aicom-page-desc"><?php esc_html_e( 'Generate and manage API keys that grant AI agents access to your site via MCP.', 'aicom' ); ?></p>
    </div>

    <?php if ( $edit_key ) : ?>
    <?php
    // ══════════════════════════════════════════════════════════════════════
    // EDIT KEY VIEW
    // ══════════════════════════════════════════════════════════════════════
    $edit_scopes          = json_decode( $edit_key['scopes_json'] ?? '[]', true ) ?: [];
    $edit_restrictions    = json_decode( $edit_key['restrictions_json'] ?? '{}', true ) ?: [];
    $edit_expires         = $edit_key['expires_at'] ? gmdate( 'Y-m-d', strtotime( $edit_key['expires_at'] ) ) : '';
    $edit_ip_lock         = ! empty( $edit_restrictions['ip_lock'] );
    $edit_ip_lock_bound   = $edit_restrictions['ip_lock_bound_at'] ?? null;
    $edit_ip_lock_ip      = $edit_ip_lock && $edit_ip_lock_bound && ! empty( $edit_restrictions['ip_allowlist'] )
                                ? $edit_restrictions['ip_allowlist'][0]
                                : null;
    // Hide auto-bound IP from the manual textarea — merge logic in handle_edit_key() preserves it.
    $edit_ip              = ( $edit_ip_lock && $edit_ip_lock_bound )
                                ? ''
                                : implode( "\n", $edit_restrictions['ip_allowlist'] ?? [] );
    $edit_dry_run         = ! empty( $edit_restrictions['dry_run_only'] );
    $edit_wh              = $edit_restrictions['working_hours'] ?? [];
    $edit_wh_enabled      = ! empty( $edit_wh['enabled'] );
    $edit_wh_days         = array_map( 'intval', $edit_wh['days'] ?? [ 1, 2, 3, 4, 5 ] );
    $edit_wh_start        = $edit_wh['start'] ?? '09:00';
    $edit_wh_end          = $edit_wh['end']   ?? '18:00';
    $edit_post_types   = implode( "\n", $edit_restrictions['post_types'] ?? [] );
    $edit_taxonomies   = implode( "\n", $edit_restrictions['taxonomies'] ?? [] );
    $edit_meta_keys    = implode( "\n", $edit_restrictions['meta_keys']  ?? [] );
    $edit_res_options  = implode( "\n", $edit_restrictions['options']    ?? [] );
    $edit_file_paths   = implode( "\n", $edit_restrictions['file_paths'] ?? [] );
    $edit_languages    = implode( "\n", $edit_restrictions['languages']  ?? [] );
    ?>
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title">
                <?php esc_html_e( 'Edit Key:', 'aicom' ); ?> <em><?php echo esc_html( $edit_key['label'] ); ?></em>
            </h2>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicom-api-keys' ) ); ?>" class="aicom-card-link"><?php esc_html_e( '← Back to keys', 'aicom' ); ?></a>
        </div>
        <div class="aicom-card-body">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  id="aicom-edit-key-form"
                  data-original-scopes="<?php echo esc_attr( wp_json_encode( $edit_scopes ) ); ?>">
                <input type="hidden" name="action"      value="aicom_save" />
                <input type="hidden" name="aicom_action" value="edit_key" />
                <input type="hidden" name="key_id"       value="<?php echo (int) $edit_key['id']; ?>" />
                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>

                <div class="aicom-form">

                    <!-- Scopes -->
                    <div class="aicom-field-row">
                        <label class="aicom-field-label">
                            <span class="aicom-term" tabindex="0" data-define="<?php esc_attr_e( 'Permissions you give your agent — what it can read or change. Pick narrow for safety, broad for power.', 'aicom' ); ?>"><?php esc_html_e( 'Scopes', 'aicom' ); ?></span>
                            <small><?php esc_html_e( 'Permissions for this key', 'aicom' ); ?></small>
                        </label>
                        <div class="aicom-field-control">
                            <input type="text" id="aicom-scope-search-edit" class="regular-text aicom-scope-search"
                                   placeholder="<?php esc_attr_e( 'Search scopes…', 'aicom' ); ?>" autocomplete="off" />
                            <div id="aicom-scope-tree-edit">
                            <?php foreach ( $scope_tree as $group_name => $scopes ) : ?>
                                <div class="aicom-tree-group" data-group="<?php echo esc_attr( sanitize_title( $group_name ) ); ?>">
                                    <div class="aicom-tree-group-header">
                                        <span class="aicom-tree-chevron">&#9660;</span>
                                        <strong><?php echo esc_html( $group_name ); ?></strong>
                                        <span class="aicom-tree-group-count"></span>
                                    </div>
                                    <div class="aicom-tree-group-body">
                                    <?php foreach ( $scopes as $scope_key => $scope_data ) : [ $scope_label, $scope_risk ] = $scope_data; $scope_desc = $scope_data[2] ?? ''; ?>
                                        <label class="aicom-tree-scope-row"
                                               data-scope="<?php echo esc_attr( $scope_key ); ?>"
                                               <?php if ( $scope_desc ) : ?>title="<?php echo esc_attr( $scope_desc ); ?>"<?php endif; ?>
                                               <?php if ( isset( $scope_implications[ $scope_key ] ) ) : ?>data-implies="<?php echo esc_attr( $scope_implications[ $scope_key ] ); ?>"<?php endif; ?>>
                                            <input type="checkbox" name="scopes[]"
                                                   value="<?php echo esc_attr( $scope_key ); ?>"
                                                   class="aicom-scope-cb"
                                                   <?php checked( in_array( $scope_key, $edit_scopes, true ) ); ?> />
                                            <span class="aicom-tree-scope-label"><?php echo esc_html( $scope_label ); ?></span>
                                            <code class="aicom-tree-scope-code"><?php echo esc_html( $scope_key ); ?></code>
                                            <span class="aicom-risk-badge aicom-risk-<?php echo esc_attr( $scope_risk ); ?>"><?php echo esc_html( strtoupper( $scope_risk ) ); ?></span>
                                            <?php if ( isset( $scope_implied_by[ $scope_key ] ) ) : ?>
                                                <span class="aicom-scope-implied-hint" data-implied-by="<?php echo esc_attr( implode( ',', $scope_implied_by[ $scope_key ] ) ); ?>" hidden>
                                                    <?php esc_html_e( '(included automatically when the matching manage scope is ticked)', 'aicom' ); ?>
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                            <div id="aicom-scope-diff" style="display:none;margin-top:8px;font-size:0.82em;font-family:monospace"></div>
                        </div>
                    </div>

                    <!-- Expiry Date -->
                    <div class="aicom-field-row">
                        <label class="aicom-field-label" for="aicom-edit-expires">
                            <?php esc_html_e( 'Expiry Date', 'aicom' ); ?>
                            <small><?php esc_html_e( 'Optional', 'aicom' ); ?></small>
                        </label>
                        <div class="aicom-field-control">
                            <input type="date" name="expires_at" id="aicom-edit-expires"
                                   value="<?php echo esc_attr( $edit_expires ); ?>"
                                   min="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '+1 day' ) ) ); ?>" />
                            <p class="aicom-field-desc"><?php esc_html_e( 'Leave empty to remove expiry.', 'aicom' ); ?></p>
                        </div>
                    </div>

                    <!-- IP Allowlist -->
                    <div class="aicom-field-row">
                        <label class="aicom-field-label" for="aicom-edit-ip">
                            <?php esc_html_e( 'IP Allowlist', 'aicom' ); ?>
                            <small><?php esc_html_e( 'Optional', 'aicom' ); ?></small>
                        </label>
                        <div class="aicom-field-control">
                            <textarea name="ip_allowlist" id="aicom-edit-ip" rows="3"
                                      style="width:100%;max-width:420px;font-family:monospace;font-size:0.85em"
                                      placeholder="<?php esc_attr_e( 'One IP or CIDR per line. Leave empty to allow all.', 'aicom' ); ?>"><?php echo esc_textarea( $edit_ip ); ?></textarea>
                        </div>
                    </div>

                    <!-- IP Auto-Lock -->
                    <div class="aicom-field-row">
                        <label class="aicom-field-label">
                            <?php esc_html_e( 'IP Auto-Lock', 'aicom' ); ?>
                        </label>
                        <div class="aicom-field-control">
                            <label class="aicom-toggle-label">
                                <input type="checkbox" name="ip_lock" value="1" <?php checked( $edit_ip_lock ); ?> />
                                <?php esc_html_e( 'Bind key to the first IP that uses it', 'aicom' ); ?>
                            </label>
                            <?php if ( $edit_ip_lock && $edit_ip_lock_bound && $edit_ip_lock_ip ) : ?>
                            <p class="aicom-field-desc" style="color:#059669;margin-top:6px">
                                &#128274; <?php printf(
                                    /* translators: 1: IP address, 2: UTC datetime */
                                    esc_html__( 'Locked to %1$s since %2$s UTC', 'aicom' ),
                                    esc_html( $edit_ip_lock_ip ),
                                    esc_html( substr( $edit_ip_lock_bound, 0, 16 ) )
                                ); ?>
                            </p>
                            <?php elseif ( $edit_ip_lock ) : ?>
                            <p class="aicom-field-desc" style="color:#d97706;margin-top:6px">
                                &#128275; <?php esc_html_e( 'Waiting for first use — IP will be bound on next request.', 'aicom' ); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Resource Restrictions -->
                    <?php
                    $res_active = count( array_filter( [ $edit_post_types, $edit_taxonomies, $edit_meta_keys, $edit_res_options, $edit_file_paths, $edit_languages ] ) );
                    ?>
                    <div class="aicom-res-section <?php echo $res_active ? 'is-open' : ''; ?>">
                        <div class="aicom-res-header">
                            <span class="aicom-res-icon">&#128274;</span>
                            <span class="aicom-res-title"><?php esc_html_e( 'Resource Restrictions', 'aicom' ); ?></span>
                            <?php if ( $res_active ) : ?>
                                <span class="aicom-res-badge"><?php echo (int) $res_active; ?> active</span>
                            <?php endif; ?>
                            <span class="aicom-res-chevron">&#9654;</span>
                        </div>
                        <div class="aicom-res-body">
                            <p class="aicom-res-intro"><?php esc_html_e( 'Limit which WordPress resources this key can access. Restrictions are enforced per-call — leave a field empty to allow all values of that type.', 'aicom' ); ?></p>
                            <div class="aicom-res-grid">
                                <div class="aicom-res-field <?php echo $edit_post_types ? 'has-value' : ''; ?>">
                                    <label class="aicom-res-label">
                                        <?php esc_html_e( 'Post Types', 'aicom' ); ?>
                                        <span class="aicom-res-status <?php echo $edit_post_types ? 'is-set' : 'is-unset'; ?>"><?php echo $edit_post_types ? esc_html__( 'Active', 'aicom' ) : esc_html__( 'Inactive', 'aicom' ); ?></span>
                                    </label>
                                    <textarea name="res_post_types" rows="3" placeholder="post&#10;page&#10;product"><?php echo esc_textarea( $edit_post_types ); ?></textarea>
                                    <p class="aicom-res-hint"><?php esc_html_e( 'One per line · empty = allow all', 'aicom' ); ?></p>
                                </div>
                                <div class="aicom-res-field <?php echo $edit_taxonomies ? 'has-value' : ''; ?>">
                                    <label class="aicom-res-label">
                                        <?php esc_html_e( 'Taxonomies', 'aicom' ); ?>
                                        <span class="aicom-res-status <?php echo $edit_taxonomies ? 'is-set' : 'is-unset'; ?>"><?php echo $edit_taxonomies ? esc_html__( 'Active', 'aicom' ) : esc_html__( 'Inactive', 'aicom' ); ?></span>
                                    </label>
                                    <textarea name="res_taxonomies" rows="3" placeholder="category&#10;post_tag"><?php echo esc_textarea( $edit_taxonomies ); ?></textarea>
                                    <p class="aicom-res-hint"><?php esc_html_e( 'One per line · empty = allow all', 'aicom' ); ?></p>
                                </div>
                                <div class="aicom-res-field <?php echo $edit_meta_keys ? 'has-value' : ''; ?>">
                                    <label class="aicom-res-label">
                                        <?php esc_html_e( 'Meta Keys', 'aicom' ); ?>
                                        <span class="aicom-res-status <?php echo $edit_meta_keys ? 'is-set' : 'is-unset'; ?>"><?php echo $edit_meta_keys ? esc_html__( 'Active', 'aicom' ) : esc_html__( 'Inactive', 'aicom' ); ?></span>
                                    </label>
                                    <textarea name="res_meta_keys" rows="3" placeholder="_price&#10;custom_field"><?php echo esc_textarea( $edit_meta_keys ); ?></textarea>
                                    <p class="aicom-res-hint"><?php esc_html_e( 'One per line · empty = allow all', 'aicom' ); ?></p>
                                </div>
                                <div class="aicom-res-field <?php echo $edit_res_options ? 'has-value' : ''; ?>">
                                    <label class="aicom-res-label">
                                        <?php esc_html_e( 'WP Options', 'aicom' ); ?>
                                        <span class="aicom-res-status <?php echo $edit_res_options ? 'is-set' : 'is-unset'; ?>"><?php echo $edit_res_options ? esc_html__( 'Active', 'aicom' ) : esc_html__( 'Inactive', 'aicom' ); ?></span>
                                    </label>
                                    <textarea name="res_options" rows="3" placeholder="blogname&#10;siteurl"><?php echo esc_textarea( $edit_res_options ); ?></textarea>
                                    <p class="aicom-res-hint"><?php esc_html_e( 'One per line · empty = allow all', 'aicom' ); ?></p>
                                </div>
                                <div class="aicom-res-field is-full <?php echo $edit_file_paths ? 'has-value' : 'is-warn-empty'; ?>">
                                    <label class="aicom-res-label">
                                        <?php esc_html_e( 'File Paths', 'aicom' ); ?>
                                        <span class="aicom-res-status <?php echo $edit_file_paths ? 'is-set' : 'is-blocked'; ?>"><?php echo $edit_file_paths ? esc_html__( 'Paths set', 'aicom' ) : esc_html__( 'All blocked', 'aicom' ); ?></span>
                                    </label>
                                    <textarea name="res_file_paths" rows="2" placeholder="/var/www/html/wp-content/uploads/"><?php echo esc_textarea( $edit_file_paths ); ?></textarea>
                                    <p class="aicom-res-hint is-warn"><?php esc_html_e( 'Empty = BLOCK all file operations. Add allowed directory prefixes, one per line.', 'aicom' ); ?></p>
                                </div>
                                <?php if ( AICOM_Module_Detector::is_polylang_active() ) : ?>
                                <div class="aicom-res-field is-full <?php echo $edit_languages ? 'has-value' : ''; ?>">
                                    <label class="aicom-res-label">
                                        <?php esc_html_e( 'Languages', 'aicom' ); ?>
                                        <span class="aicom-res-status <?php echo $edit_languages ? 'is-set' : 'is-unset'; ?>"><?php echo $edit_languages ? esc_html__( 'Active', 'aicom' ) : esc_html__( 'Inactive', 'aicom' ); ?></span>
                                    </label>
                                    <textarea name="res_languages" rows="2" placeholder="en&#10;fr"><?php echo esc_textarea( $edit_languages ); ?></textarea>
                                    <p class="aicom-res-hint"><?php esc_html_e( 'One language code per line · empty = allow all', 'aicom' ); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php $render_working_hours( $edit_wh_enabled, $edit_wh_days, $edit_wh_start, $edit_wh_end ); ?>

                    <!-- Dry-run -->
                    <div class="aicom-field-row">
                        <label class="aicom-field-label"><?php esc_html_e( 'Dry-Run Only', 'aicom' ); ?></label>
                        <div class="aicom-field-control">
                            <label class="aicom-toggle-label">
                                <input type="checkbox" name="dry_run_only" value="1" <?php checked( $edit_dry_run ); ?> />
                                <?php esc_html_e( 'Force all write operations to dry-run mode for this key', 'aicom' ); ?>
                            </label>
                        </div>
                    </div>

                    <!-- Rotate secret -->
                    <div class="aicom-field-row">
                        <label class="aicom-field-label"><?php esc_html_e( 'Rotate Secret', 'aicom' ); ?></label>
                        <div class="aicom-field-control">
                            <label class="aicom-toggle-label">
                                <input type="checkbox" name="rotate_secret" value="1" />
                                <?php esc_html_e( 'Generate a new API key secret (old key stops working immediately)', 'aicom' ); ?>
                            </label>
                        </div>
                    </div>

                </div>

                <div class="aicom-form-footer">
                    <?php submit_button( __( 'Save Changes', 'aicom' ), 'primary', 'submit', false ); ?>
                </div>
            </form>
        </div>
    </div>
    <?php
    include AICOM_DIR . 'admin/partials/layout-bottom.php';
    return;
    endif;
    ?>

    <!-- ── Notices ───────────────────────────────────────────────────────── -->
    <?php if ( isset( $_GET['revoked'] ) ) : ?>
    <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Key revoked.', 'aicom' ); ?></p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['suspended'] ) ) : ?>
    <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Key suspended — access blocked until unsuspended.', 'aicom' ); ?></p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['unsuspended'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Key unsuspended — access restored.', 'aicom' ); ?></p></div>
    <?php endif; ?>
    <?php if ( $edited && is_array( $edit_diff ) ) : ?>
    <div class="notice notice-success is-dismissible">
        <p><strong><?php esc_html_e( 'Key updated.', 'aicom' ); ?></strong>
        <?php if ( ! empty( $edit_diff['added'] ) ) : ?>
            <?php /* translators: %s: list of scope names */ ?>
            <?php printf( ' ' . esc_html__( 'Added: %s.', 'aicom' ), esc_html( implode( ', ', $edit_diff['added'] ) ) ); ?>
        <?php endif; ?>
        <?php if ( ! empty( $edit_diff['removed'] ) ) : ?>
            <?php /* translators: %s: list of scope names */ ?>
            <?php printf( ' ' . esc_html__( 'Removed: %s.', 'aicom' ), esc_html( implode( ', ', $edit_diff['removed'] ) ) ); ?>
        <?php endif; ?>
        <?php if ( ! empty( $edit_diff['rotated'] ) ) : ?>
            <?php esc_html_e( ' Secret rotated — see new key above.', 'aicom' ); ?>
        <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>
    <?php if ( $archived ) : ?>
    <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Key archived.', 'aicom' ); ?></p></div>
    <?php endif; ?>
    <?php if ( $unarchived ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Key unarchived — now suspended.', 'aicom' ); ?></p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['ip_lock_reset'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'IP lock reset — the key will bind to the next connecting IP.', 'aicom' ); ?></p></div>
    <?php endif; ?>

    <!-- ── Tab bar ────────────────────────────────────────────────────────── -->
    <?php
    $default_tab = 'generate';
    if ( $created || $rotated || isset( $_GET['revoked'] ) || isset( $_GET['suspended'] ) || isset( $_GET['unsuspended'] ) || $edited || $archived || $unarchived || isset( $_GET['ip_lock_reset'] ) ) {
        $default_tab = 'keys';
    }
    $key_count = count( $keys ?: [] );
    ?>
    <div class="aicom-tab-bar" id="aicom-tab-bar" data-default="<?php echo esc_attr( $default_tab ); ?>">
        <button type="button" class="aicom-tab-btn" data-tab="generate"><?php esc_html_e( 'Generate New Key', 'aicom' ); ?></button>
        <button type="button" class="aicom-tab-btn" data-tab="connection"><?php esc_html_e( 'Connection Info', 'aicom' ); ?></button>
        <?php if ( $key_count > 0 ) : ?>
        <button type="button" class="aicom-tab-btn" data-tab="keys">
            <?php esc_html_e( 'Existing Keys', 'aicom' ); ?><span class="aicom-tab-badge"><?php echo (int) $key_count; ?></span>
        </button>
        <?php endif; ?>
    </div>

    <!-- ══ Tab: Generate New Key ══════════════════════════════════════════ -->
    <div class="aicom-tab-panel" id="aicom-tab-generate">
        <div class="aicom-card">
            <div class="aicom-card-body">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="aicom_save" />
                    <input type="hidden" name="aicom_action" value="create_key" />
                    <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>

                    <div class="aicom-form">

                        <!-- Label -->
                        <div class="aicom-field-row">
                            <label class="aicom-field-label" for="aicom-key-label">
                                <?php esc_html_e( 'Label', 'aicom' ); ?>
                                <small><?php esc_html_e( 'Display name for logs', 'aicom' ); ?></small>
                            </label>
                            <div class="aicom-field-control">
                                <input type="text" name="label" id="aicom-key-label" class="regular-text"
                                       required placeholder="<?php esc_attr_e( 'e.g. OpenClaw Production', 'aicom' ); ?>" style="max-width:320px" />
                            </div>
                        </div>

                        <!-- Preset -->
                        <div class="aicom-field-row">
                            <label class="aicom-field-label">
                                <?php esc_html_e( 'Preset', 'aicom' ); ?>
                                <small><?php esc_html_e( 'Start from a template', 'aicom' ); ?></small>
                            </label>
                            <div class="aicom-field-control">
                                <?php $default_preset = 'content-assistant'; $default_scopes = $presets[ $default_preset ]['scopes'] ?? []; ?>
                                <div class="aicom-preset-grid" id="aicom-preset-grid">
                                    <?php foreach ( $presets as $slug => $p ) : ?>
                                    <button type="button"
                                            class="aicom-preset-card aicom-preset-risk-<?php echo esc_attr( $p['risk'] ); ?><?php echo $slug === $default_preset ? ' is-active' : ''; ?>"
                                            data-preset="<?php echo esc_attr( $slug ); ?>"
                                            data-scopes="<?php echo esc_attr( wp_json_encode( $p['scopes'] ) ); ?>">
                                        <span class="aicom-preset-name"><?php echo esc_html( $p['name'] ); ?></span>
                                        <span class="aicom-preset-desc"><?php echo esc_html( $p['desc'] ); ?></span>
                                        <span class="aicom-preset-count"><?php printf( esc_html( _n( '%d scope', '%d scopes', count( $p['scopes'] ), 'aicom' ) ), count( $p['scopes'] ) ); ?></span>
                                        <span class="aicom-risk-badge aicom-risk-<?php echo esc_attr( $p['risk'] ); ?>"><?php echo esc_html( strtoupper( $p['risk'] ) ); ?></span>
                                    </button>
                                    <?php endforeach; ?>

                                    <?php foreach ( $custom_presets as $cp ) :
                                        $cp_scopes = json_decode( $cp['scopes_json'], true ) ?: [];
                                    ?>
                                    <button type="button"
                                            class="aicom-preset-card aicom-preset-user"
                                            data-preset="user-<?php echo (int) $cp['id']; ?>"
                                            data-scopes="<?php echo esc_attr( wp_json_encode( $cp_scopes ) ); ?>"
                                            data-preset-id="<?php echo (int) $cp['id']; ?>">
                                        <span class="aicom-preset-name"><?php echo esc_html( $cp['name'] ); ?></span>
                                        <span class="aicom-preset-count"><?php printf( esc_html( _n( '%d scope', '%d scopes', count( $cp_scopes ), 'aicom' ) ), count( $cp_scopes ) ); ?></span>
                                        <span class="aicom-preset-user-badge"><?php esc_html_e( 'Custom', 'aicom' ); ?></span>
                                        <span class="aicom-preset-actions">
                                            <span class="aicom-preset-rename"
                                                  data-preset-id="<?php echo (int) $cp['id']; ?>"
                                                  title="<?php esc_attr_e( 'Rename', 'aicom' ); ?>" role="button" tabindex="0">&#9999;</span>
                                            <span class="aicom-preset-duplicate"
                                                  data-preset-id="<?php echo (int) $cp['id']; ?>"
                                                  title="<?php esc_attr_e( 'Duplicate', 'aicom' ); ?>" role="button" tabindex="0">&#10064;</span>
                                            <span class="aicom-preset-delete"
                                                  data-preset-id="<?php echo (int) $cp['id']; ?>"
                                                  title="<?php esc_attr_e( 'Delete', 'aicom' ); ?>" role="button" tabindex="0">&#10005;</span>
                                        </span>
                                    </button>
                                    <?php endforeach; ?>

                                    <button type="button"
                                            class="aicom-preset-card aicom-preset-custom"
                                            data-preset="custom" data-scopes="[]">
                                        <span class="aicom-preset-name"><?php esc_html_e( 'Custom', 'aicom' ); ?></span>
                                        <span class="aicom-preset-desc"><?php esc_html_e( 'Pick scopes manually', 'aicom' ); ?></span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Scopes -->
                        <div class="aicom-field-row">
                            <label class="aicom-field-label">
                                <?php esc_html_e( 'Scopes', 'aicom' ); ?>
                                <small><?php esc_html_e( 'Permissions for this key', 'aicom' ); ?></small>
                            </label>
                            <div class="aicom-field-control">
                                <p class="aicom-scope-hint" id="aicom-scope-hint"><?php esc_html_e( 'Scopes are set by the preset above. Tick a checkbox to switch to Custom and edit manually.', 'aicom' ); ?></p>
                                <input type="text" id="aicom-scope-search" class="regular-text aicom-scope-search"
                                       placeholder="<?php esc_attr_e( 'Search scopes…', 'aicom' ); ?>" autocomplete="off" />
                                <div id="aicom-scope-tree">
                                <?php foreach ( $scope_tree as $group_name => $scopes ) : ?>
                                    <div class="aicom-tree-group" data-group="<?php echo esc_attr( sanitize_title( $group_name ) ); ?>">
                                        <div class="aicom-tree-group-header">
                                            <span class="aicom-tree-chevron">&#9660;</span>
                                            <strong><?php echo esc_html( $group_name ); ?></strong>
                                            <span class="aicom-tree-group-count"></span>
                                        </div>
                                        <div class="aicom-tree-group-body">
                                        <?php foreach ( $scopes as $scope_key => $scope_data ) : [ $scope_label, $scope_risk ] = $scope_data; $scope_desc = $scope_data[2] ?? ''; ?>
                                            <label class="aicom-tree-scope-row"
                                                   data-scope="<?php echo esc_attr( $scope_key ); ?>"
                                                   <?php if ( $scope_desc ) : ?>title="<?php echo esc_attr( $scope_desc ); ?>"<?php endif; ?>
                                                   <?php if ( isset( $scope_implications[ $scope_key ] ) ) : ?>data-implies="<?php echo esc_attr( $scope_implications[ $scope_key ] ); ?>"<?php endif; ?>>
                                                <input type="checkbox" name="scopes[]"
                                                       value="<?php echo esc_attr( $scope_key ); ?>"
                                                       class="aicom-scope-cb"
                                                       <?php checked( in_array( $scope_key, $default_scopes, true ) ); ?> />
                                                <span class="aicom-tree-scope-label"><?php echo esc_html( $scope_label ); ?></span>
                                                <code class="aicom-tree-scope-code"><?php echo esc_html( $scope_key ); ?></code>
                                                <span class="aicom-risk-badge aicom-risk-<?php echo esc_attr( $scope_risk ); ?>"><?php echo esc_html( strtoupper( $scope_risk ) ); ?></span>
                                                <?php if ( isset( $scope_implied_by[ $scope_key ] ) ) : ?>
                                                    <span class="aicom-scope-implied-hint" data-implied-by="<?php echo esc_attr( implode( ',', $scope_implied_by[ $scope_key ] ) ); ?>" hidden>
                                                        <?php esc_html_e( '(included automatically when the matching manage scope is ticked)', 'aicom' ); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                </div>

                                <div class="aicom-save-preset-wrap">
                                    <button type="button" id="aicom-save-preset-btn" class="button"><?php esc_html_e( '+ Save as preset', 'aicom' ); ?></button>
                                    <span id="aicom-save-preset-form" style="display:none">
                                        <input type="text" id="aicom-preset-name-input" placeholder="<?php esc_attr_e( 'Preset name…', 'aicom' ); ?>" class="regular-text" style="width:200px" maxlength="100" />
                                        <button type="button" id="aicom-preset-save-confirm" class="button button-primary"><?php esc_html_e( 'Save', 'aicom' ); ?></button>
                                        <button type="button" id="aicom-preset-save-cancel" class="button"><?php esc_html_e( 'Cancel', 'aicom' ); ?></button>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <details class="aicom-advanced">
                            <summary>
                                <span><?php esc_html_e( 'Advanced options', 'aicom' ); ?></span>
                                <span class="aicom-advanced-hint"><?php esc_html_e( 'IP locks, working hours, dry-run, expiry…', 'aicom' ); ?></span>
                            </summary>

                            <!-- IP Allowlist -->
                            <div class="aicom-field-row">
                                <label class="aicom-field-label" for="aicom-ip-allowlist">
                                    <?php esc_html_e( 'IP Allowlist', 'aicom' ); ?>
                                    <small><?php esc_html_e( 'Optional', 'aicom' ); ?></small>
                                </label>
                                <div class="aicom-field-control">
                                    <textarea name="ip_allowlist" id="aicom-ip-allowlist" rows="3"
                                              style="width:100%;max-width:420px;font-family:monospace;font-size:0.85em"
                                              placeholder="<?php esc_attr_e( 'One IP or CIDR per line. Leave empty to allow all.', 'aicom' ); ?>"></textarea>
                                    <p class="aicom-field-desc"><?php esc_html_e( 'e.g. 203.0.113.5 or 192.168.1.0/24', 'aicom' ); ?></p>
                                </div>
                            </div>

                        <!-- IP Auto-Lock -->
                        <div class="aicom-field-row">
                            <label class="aicom-field-label">
                                <?php esc_html_e( 'IP Auto-Lock', 'aicom' ); ?>
                                <small><?php esc_html_e( 'Optional', 'aicom' ); ?></small>
                            </label>
                            <div class="aicom-field-control">
                                <label class="aicom-toggle-label">
                                    <input type="checkbox" name="ip_lock" value="1" />
                                    <?php esc_html_e( 'Bind key to the first IP that uses it', 'aicom' ); ?>
                                </label>
                                <p class="aicom-field-desc"><?php esc_html_e( 'On first use the connecting IP is recorded and all subsequent requests must come from that IP. Use "Reset IP lock" in the key menu to rebind.', 'aicom' ); ?></p>
                            </div>
                        </div>

                        <!-- Resource Restrictions -->
                        <div class="aicom-res-section">
                            <div class="aicom-res-header">
                                <span class="aicom-res-icon">&#128274;</span>
                                <span class="aicom-res-title"><?php esc_html_e( 'Resource Restrictions', 'aicom' ); ?></span>
                                <span class="aicom-res-chevron">&#9654;</span>
                            </div>
                            <div class="aicom-res-body">
                                <p class="aicom-res-intro"><?php esc_html_e( 'Limit which WordPress resources this key can access. Restrictions are enforced per-call — leave a field empty to allow all values of that type.', 'aicom' ); ?></p>
                                <div class="aicom-res-grid">
                                    <div class="aicom-res-field">
                                        <label class="aicom-res-label">
                                            <?php esc_html_e( 'Post Types', 'aicom' ); ?>
                                            <span class="aicom-res-status is-unset"><?php esc_html_e( 'Inactive', 'aicom' ); ?></span>
                                        </label>
                                        <textarea name="res_post_types" rows="3" placeholder="post&#10;page&#10;product"></textarea>
                                        <p class="aicom-res-hint"><?php esc_html_e( 'One per line · empty = allow all', 'aicom' ); ?></p>
                                    </div>
                                    <div class="aicom-res-field">
                                        <label class="aicom-res-label">
                                            <?php esc_html_e( 'Taxonomies', 'aicom' ); ?>
                                            <span class="aicom-res-status is-unset"><?php esc_html_e( 'Inactive', 'aicom' ); ?></span>
                                        </label>
                                        <textarea name="res_taxonomies" rows="3" placeholder="category&#10;post_tag"></textarea>
                                        <p class="aicom-res-hint"><?php esc_html_e( 'One per line · empty = allow all', 'aicom' ); ?></p>
                                    </div>
                                    <div class="aicom-res-field">
                                        <label class="aicom-res-label">
                                            <?php esc_html_e( 'Meta Keys', 'aicom' ); ?>
                                            <span class="aicom-res-status is-unset"><?php esc_html_e( 'Inactive', 'aicom' ); ?></span>
                                        </label>
                                        <textarea name="res_meta_keys" rows="3" placeholder="_price&#10;custom_field"></textarea>
                                        <p class="aicom-res-hint"><?php esc_html_e( 'One per line · empty = allow all', 'aicom' ); ?></p>
                                    </div>
                                    <div class="aicom-res-field">
                                        <label class="aicom-res-label">
                                            <?php esc_html_e( 'WP Options', 'aicom' ); ?>
                                            <span class="aicom-res-status is-unset"><?php esc_html_e( 'Inactive', 'aicom' ); ?></span>
                                        </label>
                                        <textarea name="res_options" rows="3" placeholder="blogname&#10;siteurl"></textarea>
                                        <p class="aicom-res-hint"><?php esc_html_e( 'One per line · empty = allow all', 'aicom' ); ?></p>
                                    </div>
                                    <div class="aicom-res-field is-full is-warn-empty">
                                        <label class="aicom-res-label">
                                            <?php esc_html_e( 'File Paths', 'aicom' ); ?>
                                            <span class="aicom-res-status is-blocked"><?php esc_html_e( 'All blocked', 'aicom' ); ?></span>
                                        </label>
                                        <textarea name="res_file_paths" rows="2" placeholder="/var/www/html/wp-content/uploads/"></textarea>
                                        <p class="aicom-res-hint is-warn"><?php esc_html_e( 'Empty = BLOCK all file operations. Add allowed directory prefixes, one per line.', 'aicom' ); ?></p>
                                    </div>
                                    <?php if ( AICOM_Module_Detector::is_polylang_active() ) : ?>
                                    <div class="aicom-res-field is-full">
                                        <label class="aicom-res-label">
                                            <?php esc_html_e( 'Languages', 'aicom' ); ?>
                                            <span class="aicom-res-status is-unset"><?php esc_html_e( 'Inactive', 'aicom' ); ?></span>
                                        </label>
                                        <textarea name="res_languages" rows="2" placeholder="en&#10;fr"></textarea>
                                        <p class="aicom-res-hint"><?php esc_html_e( 'One language code per line · empty = allow all', 'aicom' ); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php $render_working_hours( false, [ 1, 2, 3, 4, 5 ], '09:00', '18:00' ); ?>

                        <!-- Dry-run -->
                        <div class="aicom-field-row">
                            <label class="aicom-field-label"><?php esc_html_e( 'Dry-Run Only', 'aicom' ); ?></label>
                            <div class="aicom-field-control">
                                <label class="aicom-toggle-label">
                                    <input type="checkbox" name="dry_run_only" value="1" />
                                    <?php esc_html_e( 'Force all write operations to dry-run mode for this key', 'aicom' ); ?>
                                </label>
                            </div>
                        </div>

                        <!-- Expiry Date -->
                        <div class="aicom-field-row">
                            <label class="aicom-field-label" for="aicom-expires-at">
                                <?php esc_html_e( 'Expiry Date', 'aicom' ); ?>
                                <small><?php esc_html_e( 'Optional', 'aicom' ); ?></small>
                            </label>
                            <div class="aicom-field-control">
                                <input type="date" name="expires_at" id="aicom-expires-at"
                                       min="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '+1 day' ) ) ); ?>" />
                                <p class="aicom-field-desc"><?php esc_html_e( 'Key stops working automatically on this date.', 'aicom' ); ?></p>
                            </div>
                        </div>

                        </details>

                    </div><!-- /.aicom-form -->

                    <div class="aicom-form-footer">
                        <?php submit_button( __( 'Generate Key', 'aicom' ), 'primary', 'submit', false ); ?>
                    </div>
                </form>
            </div>
        </div>
    </div><!-- #aicom-tab-generate -->

    <!-- ══ Tab: Connection Info ═══════════════════════════════════════════ -->
    <div class="aicom-tab-panel" id="aicom-tab-connection">
        <div class="aicom-card">
            <div class="aicom-card-head">
                <h2 class="aicom-card-title"><?php esc_html_e( 'Connection Info', 'aicom' ); ?></h2>
            </div>
            <div class="aicom-card-body">
                <?php
                $mcp_endpoint  = get_site_url() . '/wp-json/aicom/v1/mcp';
                $fallback_ep   = get_site_url() . '/?aicom=1';
                $htaccess_line = 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1';
                ?>
                <div class="aicom-endpoint-item">
                    <span class="aicom-endpoint-label"><?php esc_html_e( 'Primary Endpoint', 'aicom' ); ?></span>
                    <span class="aicom-endpoint-value"><?php echo esc_html( $mcp_endpoint ); ?></span>
                    <button class="button button-small aicom-copy-btn" data-target="<?php echo esc_attr( $mcp_endpoint ); ?>"><?php esc_html_e( 'Copy', 'aicom' ); ?></button>
                </div>
                <div class="aicom-endpoint-item">
                    <span class="aicom-endpoint-label"><?php esc_html_e( 'Fallback Endpoint', 'aicom' ); ?></span>
                    <span class="aicom-endpoint-value"><?php echo esc_html( $fallback_ep ); ?></span>
                    <button class="button button-small aicom-copy-btn" data-target="<?php echo esc_attr( $fallback_ep ); ?>"><?php esc_html_e( 'Copy', 'aicom' ); ?></button>
                </div>
                <div class="aicom-endpoint-item">
                    <span class="aicom-endpoint-label"><?php esc_html_e( 'Authentication', 'aicom' ); ?></span>
                    <code class="aicom-endpoint-value">Authorization: Bearer &lt;your-api-key&gt;</code>
                </div>
                <div class="aicom-endpoint-item">
                    <span class="aicom-endpoint-label"><?php esc_html_e( 'Apache Note', 'aicom' ); ?></span>
                    <span class="aicom-endpoint-value"><?php echo esc_html( $htaccess_line ); ?></span>
                    <button class="button button-small aicom-copy-btn" data-target="<?php echo esc_attr( $htaccess_line ); ?>"><?php esc_html_e( 'Copy', 'aicom' ); ?></button>
                </div>
            </div>
        </div>
    </div><!-- #aicom-tab-connection -->

    <!-- ══ Tab: Existing Keys ═════════════════════════════════════════════ -->
    <div class="aicom-tab-panel" id="aicom-tab-keys">
        <div class="aicom-card">
            <div class="aicom-card-head">
                <h2 class="aicom-card-title"><?php esc_html_e( 'Existing Keys', 'aicom' ); ?></h2>
                <?php if ( $key_count ) : ?>
                <span style="font-size:0.8em;color:#6b7280"><?php printf( esc_html( _n( '%d key', '%d keys', $key_count, 'aicom' ) ), $key_count ); ?></span>
                <?php endif; ?>
                <span style="margin-left:auto">
                    <?php if ( $show_archived ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicom-api-keys' ) ); ?>" class="aicom-card-link"><?php esc_html_e( 'Hide archived', 'aicom' ); ?></a>
                    <?php else : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'show_archived', '1', admin_url( 'admin.php?page=aicom-api-keys' ) ) ); ?>" class="aicom-card-link"><?php esc_html_e( 'Show archived', 'aicom' ); ?></a>
                    <?php endif; ?>
                </span>
            </div>
            <div class="aicom-card-body" style="padding:0">
            <?php if ( empty( $keys ) ) : ?>
                <p style="padding:24px;color:#6b7280;margin:0"><?php esc_html_e( 'No API keys yet. Generate your first key on the Generate tab.', 'aicom' ); ?></p>
            <?php else : ?>
                <table class="aicom-keys-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Label', 'aicom' ); ?></th>
                            <th><?php esc_html_e( 'Prefix', 'aicom' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'aicom' ); ?></th>
                            <th><?php esc_html_e( 'Scopes', 'aicom' ); ?></th>
                            <th><?php esc_html_e( 'Expires', 'aicom' ); ?></th>
                            <th><?php esc_html_e( 'Last Used', 'aicom' ); ?></th>
                            <th><?php esc_html_e( 'Created', 'aicom' ); ?></th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $keys as $key ) :
                        $scopes          = json_decode( $key['scopes_json'], true ) ?: [];
                        $row_restrictions = json_decode( $key['restrictions_json'] ?? '{}', true ) ?: [];
                        $row_ip_lock      = ! empty( $row_restrictions['ip_lock'] );
                        $row_ip_bound_at  = $row_restrictions['ip_lock_bound_at'] ?? null;
                        $row_ip_bound_ip  = $row_ip_lock && $row_ip_bound_at && ! empty( $row_restrictions['ip_allowlist'] )
                                                ? $row_restrictions['ip_allowlist'][0]
                                                : null;
                        $status_class = match( $key['status'] ) {
                            'active'    => 'aicom-status-active',
                            'suspended' => 'aicom-status-suspended',
                            'expired'   => 'aicom-status-expired',
                            'archived'  => 'aicom-status-archived',
                            default     => 'aicom-status-revoked',
                        };
                    ?>
                        <tr>
                            <td><span class="aicom-key-label"><?php echo esc_html( $key['label'] ); ?></span></td>
                            <td><code class="aicom-key-prefix"><?php echo esc_html( $key['key_prefix'] ); ?>…</code></td>
                            <td>
                                <span class="aicom-status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $key['status'] ); ?></span>
                                <?php if ( $row_ip_lock ) : ?>
                                    <?php if ( $row_ip_bound_ip ) : ?>
                                    <br><span class="aicom-ip-lock-badge is-bound"
                                              title="<?php echo esc_attr( sprintf(
                                                  /* translators: 1: IP, 2: UTC datetime */
                                                  __( 'IP-locked to %1$s since %2$s UTC', 'aicom' ),
                                                  $row_ip_bound_ip,
                                                  substr( $row_ip_bound_at, 0, 16 )
                                              ) ); ?>">&#128274; <?php esc_html_e( 'IP-locked', 'aicom' ); ?></span>
                                    <?php else : ?>
                                    <br><span class="aicom-ip-lock-badge is-waiting"
                                              title="<?php esc_attr_e( 'IP Auto-Lock active — waiting for first use', 'aicom' ); ?>">&#128275; <?php esc_html_e( 'IP-unlocked', 'aicom' ); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="aicom-key-scopes">
                                <?php if ( empty( $scopes ) ) : ?>
                                <em style="color:#9ca3af"><?php esc_html_e( 'none', 'aicom' ); ?></em>
                                <?php else : ?>
                                <details>
                                    <summary><?php printf( esc_html( _n( '%d scope', '%d scopes', count( $scopes ), 'aicom' ) ), count( $scopes ) ); ?></summary>
                                    <?php foreach ( $scopes as $s ) : ?><code><?php echo esc_html( $s ); ?></code><?php endforeach; ?>
                                </details>
                                <?php endif; ?>
                            </td>
                            <td class="aicom-key-date"><?php echo $key['expires_at'] ? esc_html( substr( $key['expires_at'], 0, 10 ) ) : '—'; ?></td>
                            <td class="aicom-key-date"><?php echo $key['last_used_at'] ? esc_html( substr( $key['last_used_at'], 0, 16 ) ) : '—'; ?></td>
                            <td class="aicom-key-date"><?php echo esc_html( substr( $key['created_at'], 0, 10 ) ); ?></td>
                            <td>
                            <?php if ( $key['status'] === 'revoked' ) : ?>
                                <em style="color:#9ca3af">—</em>
                            <?php else : ?>
                                <div class="aicom-km">
                                    <button type="button" class="aicom-km-trigger" title="<?php esc_attr_e( 'Actions', 'aicom' ); ?>">&#8942;</button>
                                    <div class="aicom-km-dropdown">
                                    <?php if ( $key['status'] === 'active' ) : ?>
                                        <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'edit_key', 'key_id' => $key['id'] ], admin_url( 'admin.php?page=aicom-api-keys' ) ) ); ?>"
                                           class="aicom-km-item">
                                            <span class="aicom-km-icon">✎</span> <?php esc_html_e( 'Edit scopes', 'aicom' ); ?>
                                        </a>
                                        <?php if ( $row_ip_lock && $row_ip_bound_ip ) : ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                            <input type="hidden" name="action" value="aicom_save" />
                                            <input type="hidden" name="aicom_action" value="reset_ip_lock" />
                                            <input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>" />
                                            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                            <button type="submit" class="aicom-km-item"
                                                    onclick="return confirm('<?php echo esc_js( __( 'Reset IP lock? The next request will re-bind to a new IP.', 'aicom' ) ); ?>')">
                                                <span class="aicom-km-icon">&#128275;</span> <?php esc_html_e( 'Reset IP lock', 'aicom' ); ?>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <div class="aicom-km-sep"></div>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                            <input type="hidden" name="action" value="aicom_save" />
                                            <input type="hidden" name="aicom_action" value="rotate_key" />
                                            <input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>" />
                                            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                            <button type="submit" class="aicom-km-item"
                                                    onclick="return confirm('<?php echo esc_js( __( 'Rotate this key? The old key stops working immediately.', 'aicom' ) ); ?>')">
                                                <span class="aicom-km-icon">↻</span> <?php esc_html_e( 'Rotate key', 'aicom' ); ?>
                                            </button>
                                        </form>
                                        <div class="aicom-km-sep"></div>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                            <input type="hidden" name="action" value="aicom_save" />
                                            <input type="hidden" name="aicom_action" value="suspend_key" />
                                            <input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>" />
                                            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                            <button type="submit" class="aicom-km-item aicom-km-warn">
                                                <span class="aicom-km-icon">⊘</span> <?php esc_html_e( 'Suspend', 'aicom' ); ?>
                                            </button>
                                        </form>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                            <input type="hidden" name="action" value="aicom_save" />
                                            <input type="hidden" name="aicom_action" value="revoke_key" />
                                            <input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>" />
                                            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                            <button type="submit" class="aicom-km-item aicom-km-danger"
                                                    onclick="return confirm('<?php echo esc_js( __( 'Revoke this key? This cannot be undone.', 'aicom' ) ); ?>')">
                                                <span class="aicom-km-icon">✕</span> <?php esc_html_e( 'Revoke', 'aicom' ); ?>
                                            </button>
                                        </form>
                                    <?php elseif ( $key['status'] === 'suspended' || $key['status'] === 'expired' ) : ?>
                                        <?php if ( $key['status'] === 'suspended' ) : ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                            <input type="hidden" name="action" value="aicom_save" />
                                            <input type="hidden" name="aicom_action" value="unsuspend_key" />
                                            <input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>" />
                                            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                            <button type="submit" class="aicom-km-item aicom-km-ok">
                                                <span class="aicom-km-icon">✓</span> <?php esc_html_e( 'Unsuspend', 'aicom' ); ?>
                                            </button>
                                        </form>
                                        <div class="aicom-km-sep"></div>
                                        <?php endif; ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                            <input type="hidden" name="action" value="aicom_save" />
                                            <input type="hidden" name="aicom_action" value="archive_key" />
                                            <input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>" />
                                            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                            <button type="submit" class="aicom-km-item">
                                                <span class="aicom-km-icon">⌁</span> <?php esc_html_e( 'Archive', 'aicom' ); ?>
                                            </button>
                                        </form>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                            <input type="hidden" name="action" value="aicom_save" />
                                            <input type="hidden" name="aicom_action" value="revoke_key" />
                                            <input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>" />
                                            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                            <button type="submit" class="aicom-km-item aicom-km-danger"
                                                    onclick="return confirm('<?php echo esc_js( __( 'Revoke this key? This cannot be undone.', 'aicom' ) ); ?>')">
                                                <span class="aicom-km-icon">✕</span> <?php esc_html_e( 'Revoke', 'aicom' ); ?>
                                            </button>
                                        </form>
                                    <?php elseif ( $key['status'] === 'archived' ) : ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                            <input type="hidden" name="action" value="aicom_save" />
                                            <input type="hidden" name="aicom_action" value="unarchive_key" />
                                            <input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>" />
                                            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                            <button type="submit" class="aicom-km-item aicom-km-ok">
                                                <span class="aicom-km-icon">↩</span> <?php esc_html_e( 'Unarchive', 'aicom' ); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            </div>
        </div>
    </div><!-- #aicom-tab-keys -->

    <script>
    // Working Hours: show days/hours panel only when the toggle is checked.
    (function () {
        document.querySelectorAll('.aicom-wh-toggle').forEach(function (cb) {
            var fields = cb.closest('.aicom-field-control').querySelector('.aicom-wh-fields');
            if (!fields) return;
            var sync = function () { fields.hidden = ! cb.checked; };
            sync();
            cb.addEventListener('change', sync);
        });
    })();
    </script>

<?php include AICOM_DIR . 'admin/partials/layout-bottom.php'; ?>
<?php
} )();
