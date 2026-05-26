<?php
defined( 'ABSPATH' ) || exit;

( function () {
global $wpdb;

$active_tab      = sanitize_key( wp_unslash( $_GET['tab'] ?? 'skills' ) );
$base_url        = admin_url( 'admin.php?page=aicom-skills' );
$tab_url         = fn( string $t ) => add_query_arg( 'tab', $t, $base_url );
$suggestions_on  = get_option( 'aicom_skill_suggestions', '1' ) === '1';

$count_suggested = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_skills WHERE status = 'suggested'" );
$count_proposals = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_skills WHERE status = 'update_proposal'" );

include AICOM_DIR . 'admin/partials/layout-top.php';
?>

<div class="aicom-page-header">
    <h1 class="aicom-page-title"><?php esc_html_e( 'Saved Workflows', 'aicom' ); ?></h1>
    <p class="aicom-page-subtitle"><?php esc_html_e( 'Reusable AI procedures — instructions, rules, and workflows your AI agents can discover and execute.', 'aicom' ); ?></p>
</div>

<div class="aicom-tab-bar">
    <a href="<?php echo esc_url( $tab_url( 'skills' ) ); ?>"
       class="aicom-tab-btn <?php echo $active_tab === 'skills' ? 'is-active' : ''; ?>">
        <?php esc_html_e( 'Skills', 'aicom' ); ?>
    </a>
    <a href="<?php echo esc_url( $tab_url( 'suggested' ) ); ?>"
       class="aicom-tab-btn <?php echo $active_tab === 'suggested' ? 'is-active' : ''; ?>">
        <?php esc_html_e( 'Suggested', 'aicom' ); ?>
        <?php if ( $count_suggested > 0 ) : ?><span class="aicom-tab-badge"><?php echo (int) $count_suggested; ?></span><?php endif; ?>
    </a>
    <a href="<?php echo esc_url( $tab_url( 'proposals' ) ); ?>"
       class="aicom-tab-btn <?php echo $active_tab === 'proposals' ? 'is-active' : ''; ?>">
        <?php esc_html_e( 'Proposals', 'aicom' ); ?>
        <?php if ( $count_proposals > 0 ) : ?><span class="aicom-tab-badge"><?php echo (int) $count_proposals; ?></span><?php endif; ?>
    </a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicom-audit-logs&tab=logs&tool_name=skills.run&period=all' ) ); ?>"
       class="aicom-tab-btn">
        <?php esc_html_e( 'History ↗', 'aicom' ); ?>
    </a>
</div>

<?php if ( isset( $_GET['settings_saved'] ) ) : ?>
<div class="notice notice-success is-dismissible" style="margin:0 0 16px;">
    <p><?php esc_html_e( 'Setting saved.', 'aicom' ); ?></p>
</div>
<?php endif; ?>

<?php
// ── TAB: Skills ───────────────────────────────────────────────────────────────
if ( $active_tab === 'skills' ) :

    // ── Detail view ──
    $view_id = absint( $_GET['view'] ?? 0 );
    if ( $view_id ) :
        $skill = AICOM_Skills::get( $view_id );
        if ( ! $skill ) :
?>
<div class="notice notice-error"><p><?php esc_html_e( 'Skill not found.', 'aicom' ); ?></p></div>
<?php
        else :
            $type_labels  = [ 'simple' => 'Simple', 'guided' => 'Guided', 'advanced' => 'Advanced' ];
            $status_class = [
                'active'   => 'aicom-status-active',
                'draft'    => 'aicom-status-suspended',
                'archived' => 'aicom-status-archived',
            ];
            $back_url = esc_url( $tab_url( 'skills' ) );
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
    <a href="<?php echo $back_url; ?>" style="display:inline-flex;align-items:center;gap:5px;font-size:0.88em;color:var(--aicom-text-sm);text-decoration:none">
        <span class="dashicons dashicons-arrow-left-alt2" style="font-size:14px;width:14px;height:14px;margin-top:1px"></span>
        <?php esc_html_e( 'Back to Skills', 'aicom' ); ?>
    </a>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
        <input type="hidden" name="action"       value="aicom_save">
        <input type="hidden" name="aicom_action" value="skills_export">
        <input type="hidden" name="skill_id"     value="<?php echo (int) $skill['id']; ?>">
        <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
        <button type="submit" class="button button-small" style="display:inline-flex;align-items:center;gap:5px">
            <span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;margin-top:2px"></span>
            <?php esc_html_e( 'Export JSON', 'aicom' ); ?>
        </button>
    </form>
</div>

<div class="aicom-card">
    <div class="aicom-card-head" style="flex-direction:column;align-items:flex-start;gap:8px">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <h2 style="margin:0;font-size:1.1em"><?php echo esc_html( $skill['name'] ); ?></h2>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <code style="font-size:0.78em"><?php echo esc_html( $skill['slug'] ); ?></code>
            <span class="aicom-class-badge aicom-class-read" style="font-size:0.72em"><?php echo esc_html( $type_labels[ $skill['type'] ] ?? $skill['type'] ); ?></span>
            <span class="aicom-status <?php echo esc_attr( $status_class[ $skill['status'] ] ?? 'aicom-status-archived' ); ?>"><?php echo esc_html( ucfirst( $skill['status'] ) ); ?></span>
            <span class="aicom-key-date">v<?php echo (int) $skill['version']; ?></span>
            <span class="aicom-key-date"><?php echo esc_html( substr( $skill['updated_at'], 0, 10 ) ); ?></span>
        </div>
    </div>
    <div class="aicom-card-body">

        <?php if ( $skill['description'] ) : ?>
        <div style="margin-bottom:20px">
            <p style="font-size:0.78em;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--aicom-text-sm);margin:0 0 6px"><?php esc_html_e( 'Description', 'aicom' ); ?></p>
            <p style="margin:0"><?php echo esc_html( $skill['description'] ); ?></p>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $skill['input_schema'] ) ) : ?>
        <div style="margin-bottom:20px">
            <p style="font-size:0.78em;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--aicom-text-sm);margin:0 0 8px"><?php esc_html_e( 'Input Schema', 'aicom' ); ?></p>
            <table class="aicom-keys-table" style="width:100%">
                <thead><tr>
                    <th><?php esc_html_e( 'Name', 'aicom' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'aicom' ); ?></th>
                    <th><?php esc_html_e( 'Required', 'aicom' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'aicom' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( (array) $skill['input_schema'] as $param_name => $param ) : ?>
                <tr>
                    <td><code style="font-size:0.82em"><?php echo esc_html( $param_name ); ?></code></td>
                    <td class="aicom-key-date"><?php echo esc_html( $param['type'] ?? '—' ); ?></td>
                    <td class="aicom-key-date"><?php echo ! empty( $param['required'] ) ? '✓' : '—'; ?></td>
                    <td class="aicom-key-date"><?php echo esc_html( $param['description'] ?? '—' ); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $skill['steps'] ) ) : ?>
        <div style="margin-bottom:20px">
            <p style="font-size:0.78em;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--aicom-text-sm);margin:0 0 8px"><?php esc_html_e( 'Steps', 'aicom' ); ?></p>
            <ol style="margin:0;padding-left:20px">
            <?php foreach ( (array) $skill['steps'] as $step ) : ?>
                <li style="margin-bottom:4px">
                    <code style="font-size:0.85em"><?php echo esc_html( $step['action'] ?? $step['tool'] ?? '?' ); ?></code>
                    <?php if ( ! empty( $step['params'] ) ) : ?>
                    <span class="aicom-key-date" style="margin-left:6px"><?php echo esc_html( wp_json_encode( $step['params'] ) ); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ol>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $skill['rules'] ) ) : ?>
        <div style="margin-bottom:20px">
            <p style="font-size:0.78em;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--aicom-text-sm);margin:0 0 8px"><?php esc_html_e( 'Rules', 'aicom' ); ?></p>
            <pre style="background:#f8fafc;border:1px solid var(--aicom-border-light);border-radius:6px;padding:12px;font-size:0.82em;margin:0;overflow-x:auto;white-space:pre-wrap"><?php echo esc_html( wp_json_encode( $skill['rules'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $skill['tags'] ) ) : ?>
        <div style="margin-bottom:20px">
            <p style="font-size:0.78em;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--aicom-text-sm);margin:0 0 8px"><?php esc_html_e( 'Tags', 'aicom' ); ?></p>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php foreach ( (array) $skill['tags'] as $tag ) : ?>
                <span style="background:#f1f5f9;border:1px solid var(--aicom-border-light);border-radius:12px;padding:2px 10px;font-size:0.82em;color:var(--aicom-text-sm)"><?php echo esc_html( $tag ); ?></span>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $skill['permissions'] ) ) : ?>
        <div>
            <p style="font-size:0.78em;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--aicom-text-sm);margin:0 0 8px"><?php esc_html_e( 'Permissions', 'aicom' ); ?></p>
            <pre style="background:#f8fafc;border:1px solid var(--aicom-border-light);border-radius:6px;padding:12px;font-size:0.82em;margin:0;overflow-x:auto;white-space:pre-wrap"><?php echo esc_html( wp_json_encode( $skill['permissions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php
        endif; // skill found
    else : // list view

    $search        = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
    $filter_type   = sanitize_key( $_GET['filter_type'] ?? '' );
    $filter_status = sanitize_key( $_GET['filter_status'] ?? '' );
    $show_import   = ! empty( $_GET['import'] );
    $per_page      = 20;
    $current_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $offset        = ( $current_page - 1 ) * $per_page;

    $filters = array_filter( [
        'search' => $search,
        'type'   => $filter_type,
        'status' => $filter_status ?: null,
    ] );

    if ( ! $filter_status ) {
        $all_results = [];
        $total_count = 0;
        foreach ( [ 'active', 'draft', 'archived' ] as $st ) {
            $r           = AICOM_Skills::list( array_merge( $filters, [ 'status' => $st ] ), 200, 0 );
            $all_results = array_merge( $all_results, $r['items'] );
            $total_count += $r['total'];
        }
        usort( $all_results, fn( $a, $b ) => strcmp( $b['updated_at'], $a['updated_at'] ) );
        $items = array_slice( $all_results, $offset, $per_page );
    } else {
        $r           = AICOM_Skills::list( $filters, $per_page, $offset );
        $items       = $r['items'];
        $total_count = $r['total'];
    }

    $total_pages = (int) ceil( $total_count / $per_page );

    $type_labels = [
        'simple'   => __( 'Simple',   'aicom' ),
        'guided'   => __( 'Guided',   'aicom' ),
        'advanced' => __( 'Advanced', 'aicom' ),
    ];
    $status_class = [
        'active'   => 'aicom-status-active',
        'draft'    => 'aicom-status-suspended',
        'archived' => 'aicom-status-archived',
    ];

    // Notices
    if ( isset( $_GET['imported'] ) ) :
        $imp_skill = AICOM_Skills::get( absint( $_GET['imported'] ) );
?>
<div class="notice notice-success is-dismissible" style="margin:0 0 16px"><p>
    <?php printf( esc_html__( 'Skill "%s" imported as draft.', 'aicom' ), esc_html( $imp_skill['name'] ?? '?' ) ); ?>
</p></div>
<?php
    endif;
    if ( isset( $_GET['import_error'] ) ) :
?>
<div class="notice notice-error is-dismissible" style="margin:0 0 16px"><p>
    <?php printf( esc_html__( 'Import failed: %s', 'aicom' ), esc_html( sanitize_text_field( wp_unslash( $_GET['import_error'] ) ) ) ); ?>
</p></div>
<?php
    endif;
?>

<div class="aicom-card">
    <div class="aicom-card-head" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <form method="get" class="aicom-filter-row" style="flex:1">
            <input type="hidden" name="page" value="aicom-skills">
            <input type="hidden" name="tab"  value="skills">
            <input type="text" name="search" value="<?php echo esc_attr( $search ); ?>"
                   placeholder="<?php esc_attr_e( 'Search skills…', 'aicom' ); ?>">
            <select name="filter_type">
                <option value=""><?php esc_html_e( 'All types', 'aicom' ); ?></option>
                <?php foreach ( $type_labels as $val => $label ) : ?>
                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_type, $val ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_status">
                <option value=""><?php esc_html_e( 'All statuses', 'aicom' ); ?></option>
                <option value="active"   <?php selected( $filter_status, 'active' ); ?>><?php esc_html_e( 'Active only',   'aicom' ); ?></option>
                <option value="draft"    <?php selected( $filter_status, 'draft' ); ?>><?php esc_html_e( 'Draft only',    'aicom' ); ?></option>
                <option value="archived" <?php selected( $filter_status, 'archived' ); ?>><?php esc_html_e( 'Archived',      'aicom' ); ?></option>
            </select>
            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'aicom' ); ?></button>
            <?php if ( $search || $filter_type || $filter_status ) : ?>
            <a href="<?php echo esc_url( $tab_url( 'skills' ) ); ?>" style="font-size:0.82em;color:var(--aicom-text-sm)"><?php esc_html_e( 'Reset', 'aicom' ); ?></a>
            <?php endif; ?>
        </form>
        <div style="display:flex;gap:6px;flex-shrink:0;align-items:center">
            <a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'skills', 'import' => '1' ], $base_url ) ); ?>"
               class="button button-small" style="display:inline-flex;align-items:center;gap:5px">
                <span class="dashicons dashicons-upload" style="font-size:14px;width:14px;height:14px;margin-top:2px"></span>
                <?php esc_html_e( 'Import JSON', 'aicom' ); ?>
            </a>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                <input type="hidden" name="action"       value="aicom_save">
                <input type="hidden" name="aicom_action" value="skills_toggle_suggestions">
                <input type="hidden" name="suggestions_enabled" value="<?php echo $suggestions_on ? '' : '1'; ?>">
                <button type="submit" class="button button-small"
                        title="<?php echo $suggestions_on
                            ? esc_attr__( 'Suggestions active — click to disable.', 'aicom' )
                            : esc_attr__( 'Suggestions disabled — click to enable.', 'aicom' ); ?>"
                        style="display:flex;align-items:center;gap:5px;<?php echo $suggestions_on ? '' : 'color:#6b7280;border-color:#d1d5db'; ?>">
                    <span class="dashicons <?php echo $suggestions_on ? 'dashicons-visibility' : 'dashicons-hidden'; ?>"
                          style="font-size:14px;width:14px;height:14px;margin-top:2px;<?php echo $suggestions_on ? 'color:#16a34a' : 'color:#9ca3af'; ?>"></span>
                    <?php echo $suggestions_on
                        ? esc_html__( 'Suggestions on', 'aicom' )
                        : esc_html__( 'Suggestions off', 'aicom' ); ?>
                </button>
            </form>
        </div>
    </div>

    <?php if ( $show_import ) : ?>
    <div style="padding:16px;border-bottom:1px solid var(--aicom-border-light);background:#f8fafc">
        <p style="font-size:0.88em;font-weight:600;margin:0 0 8px"><?php esc_html_e( 'Import Skill from JSON', 'aicom' ); ?></p>
        <p style="font-size:0.82em;color:var(--aicom-text-sm);margin:0 0 10px"><?php esc_html_e( 'Paste a skill definition exported from this plugin or crafted manually. The skill will be saved as Draft for review.', 'aicom' ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action"       value="aicom_save">
            <input type="hidden" name="aicom_action" value="skills_import_json">
            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
            <textarea name="skill_json" rows="8" style="width:100%;font-family:monospace;font-size:0.82em;border:1px solid var(--aicom-border-light);border-radius:6px;padding:10px;resize:vertical"
                      placeholder='{"name":"My Skill","slug":"my_skill","type":"simple","steps":[{"action":"wp.posts.create"}]}'></textarea>
            <div style="display:flex;gap:8px;margin-top:8px;align-items:center">
                <button type="submit" class="button button-primary button-small"><?php esc_html_e( 'Import', 'aicom' ); ?></button>
                <a href="<?php echo esc_url( $tab_url( 'skills' ) ); ?>" class="button button-small"><?php esc_html_e( 'Cancel', 'aicom' ); ?></a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="aicom-card-body" style="padding:0">
        <?php if ( empty( $items ) ) : ?>
        <p style="padding:24px;color:var(--aicom-text-sm);text-align:center;margin:0">
            <?php esc_html_e( 'No skills found. AI agents create skills via skills.create, or import a local definition via skills.import or the Import JSON button above.', 'aicom' ); ?>
        </p>
        <?php else : ?>
        <table class="aicom-keys-table" style="width:100%">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'aicom' ); ?></th>
                    <th><?php esc_html_e( 'Slug', 'aicom' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'aicom' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'aicom' ); ?></th>
                    <th><?php esc_html_e( 'Ver.', 'aicom' ); ?></th>
                    <th><?php esc_html_e( 'Updated', 'aicom' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'aicom' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $items as $skill ) : ?>
            <tr>
                <td>
                    <strong><?php echo esc_html( $skill['name'] ); ?></strong>
                    <?php if ( $skill['description'] ) : ?>
                    <div class="aicom-key-date" style="margin-top:2px"><?php echo esc_html( wp_trim_words( $skill['description'], 12 ) ); ?></div>
                    <?php endif; ?>
                </td>
                <td><code style="font-size:0.78em"><?php echo esc_html( $skill['slug'] ); ?></code></td>
                <td><span class="aicom-class-badge aicom-class-read" style="font-size:0.72em"><?php echo esc_html( $type_labels[ $skill['type'] ] ?? $skill['type'] ); ?></span></td>
                <td><span class="aicom-status <?php echo esc_attr( $status_class[ $skill['status'] ] ?? 'aicom-status-archived' ); ?>"><?php echo esc_html( ucfirst( $skill['status'] ) ); ?></span></td>
                <td class="aicom-key-date" style="text-align:center">v<?php echo (int) $skill['version']; ?></td>
                <td class="aicom-key-date"><?php echo esc_html( substr( $skill['updated_at'], 0, 10 ) ); ?></td>
                <td>
                    <div class="aicom-km">
                        <button type="button" class="aicom-km-trigger" title="<?php esc_attr_e( 'Actions', 'aicom' ); ?>">&#8942;</button>
                        <div class="aicom-km-dropdown">
                            <a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'skills', 'view' => $skill['id'] ], $base_url ) ); ?>"
                               class="aicom-km-item">
                                <span class="aicom-km-icon">&#9432;</span> <?php esc_html_e( 'Details', 'aicom' ); ?>
                            </a>
                            <div class="aicom-km-sep"></div>
                            <?php if ( $skill['status'] === 'draft' ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action"       value="aicom_save">
                                <input type="hidden" name="aicom_action" value="skills_activate">
                                <input type="hidden" name="skill_id"     value="<?php echo (int) $skill['id']; ?>">
                                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                <button type="submit" class="aicom-km-item aicom-km-ok">
                                    <span class="aicom-km-icon">&#10003;</span> <?php esc_html_e( 'Activate', 'aicom' ); ?>
                                </button>
                            </form>
                            <?php elseif ( $skill['status'] === 'active' ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action"       value="aicom_save">
                                <input type="hidden" name="aicom_action" value="skills_save_draft">
                                <input type="hidden" name="skill_id"     value="<?php echo (int) $skill['id']; ?>">
                                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                <button type="submit" class="aicom-km-item aicom-km-warn">
                                    <span class="aicom-km-icon">&#8856;</span> <?php esc_html_e( 'Deactivate', 'aicom' ); ?>
                                </button>
                            </form>
                            <?php elseif ( $skill['status'] === 'archived' ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action"       value="aicom_save">
                                <input type="hidden" name="aicom_action" value="skills_save_draft">
                                <input type="hidden" name="skill_id"     value="<?php echo (int) $skill['id']; ?>">
                                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                <button type="submit" class="aicom-km-item aicom-km-ok">
                                    <span class="aicom-km-icon">&#8629;</span> <?php esc_html_e( 'Restore', 'aicom' ); ?>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if ( $skill['status'] !== 'archived' ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action"       value="aicom_save">
                                <input type="hidden" name="aicom_action" value="skills_archive">
                                <input type="hidden" name="skill_id"     value="<?php echo (int) $skill['id']; ?>">
                                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                <button type="submit" class="aicom-km-item">
                                    <span class="aicom-km-icon">&#8675;</span> <?php esc_html_e( 'Archive', 'aicom' ); ?>
                                </button>
                            </form>
                            <?php else : ?>
                            <div class="aicom-km-sep"></div>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                  onsubmit="return confirm('<?php esc_attr_e( 'Permanently delete this skill?', 'aicom' ); ?>')">
                                <input type="hidden" name="action"       value="aicom_save">
                                <input type="hidden" name="aicom_action" value="skills_delete">
                                <input type="hidden" name="skill_id"     value="<?php echo (int) $skill['id']; ?>">
                                <input type="hidden" name="confirm"      value="1">
                                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                                <button type="submit" class="aicom-km-item aicom-km-danger">
                                    <span class="aicom-km-icon">&#10005;</span> <?php esc_html_e( 'Delete', 'aicom' ); ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
        <div style="padding:12px 16px;border-top:1px solid var(--aicom-border-light);font-size:0.82em;color:var(--aicom-text-sm);display:flex;gap:12px;align-items:center">
            <span><?php printf( esc_html__( 'Page %1$d of %2$d — %3$d total', 'aicom' ), $current_page, $total_pages, $total_count ); ?></span>
            <?php if ( $current_page > 1 ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $tab_url( 'skills' ) ) ); ?>">&laquo; <?php esc_html_e( 'Prev', 'aicom' ); ?></a>
            <?php endif; ?>
            <?php if ( $current_page < $total_pages ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $tab_url( 'skills' ) ) ); ?>"><?php esc_html_e( 'Next', 'aicom' ); ?> &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
    endif; // list view

// ── TAB: Suggested ────────────────────────────────────────────────────────────
elseif ( $active_tab === 'suggested' ) :
    $r     = AICOM_Skills::list( [ 'status' => 'suggested' ], 50, 0 );
    $items = $r['items'];
?>

<div class="aicom-card">
    <div class="aicom-card-head">
        <h2 class="aicom-card-title"><?php esc_html_e( 'Suggested Skills', 'aicom' ); ?></h2>
        <span style="font-size:0.82em;color:var(--aicom-text-sm)"><?php esc_html_e( 'Skills proposed by AI agents, waiting for your review.', 'aicom' ); ?></span>
    </div>
    <div class="aicom-card-body">
    <?php if ( empty( $items ) ) : ?>
        <p style="color:var(--aicom-text-sm);margin:0"><?php esc_html_e( 'No suggested skills. AI agents can push skill definitions here via skills.import.', 'aicom' ); ?></p>
    <?php else : ?>
        <?php foreach ( $items as $skill ) : ?>
        <div style="border:1px solid var(--aicom-border-light);border-radius:8px;margin-bottom:12px;overflow:hidden">
            <div style="padding:12px 16px;background:#f8fafc;border-bottom:1px solid var(--aicom-border-light);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <strong><?php echo esc_html( $skill['name'] ); ?></strong>
                <code style="font-size:0.78em"><?php echo esc_html( $skill['slug'] ); ?></code>
                <span class="aicom-class-badge aicom-class-read" style="font-size:0.72em"><?php echo esc_html( ucfirst( $skill['type'] ) ); ?></span>
                <?php if ( $skill['description'] ) : ?>
                <span style="font-size:0.82em;color:var(--aicom-text-sm)"><?php echo esc_html( $skill['description'] ); ?></span>
                <?php endif; ?>
            </div>
            <div style="padding:12px 16px">
                <?php if ( ! empty( $skill['steps'] ) ) : ?>
                <p style="font-size:0.78em;font-weight:600;margin:0 0 6px;text-transform:uppercase;letter-spacing:.4px;color:var(--aicom-text-sm)"><?php esc_html_e( 'Steps', 'aicom' ); ?></p>
                <ol style="margin:0 0 12px;padding-left:18px;font-size:0.82em">
                    <?php foreach ( (array) $skill['steps'] as $step ) : ?>
                    <li><code style="font-size:0.9em"><?php echo esc_html( $step['action'] ?? $step['tool'] ?? wp_json_encode( $step ) ); ?></code></li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action"       value="aicom_save">
                        <input type="hidden" name="aicom_action" value="skills_activate">
                        <input type="hidden" name="skill_id"     value="<?php echo (int) $skill['id']; ?>">
                        <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Activate', 'aicom' ); ?></button>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action"       value="aicom_save">
                        <input type="hidden" name="aicom_action" value="skills_save_draft">
                        <input type="hidden" name="skill_id"     value="<?php echo (int) $skill['id']; ?>">
                        <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                        <button type="submit" class="button"><?php esc_html_e( 'Save as Draft', 'aicom' ); ?></button>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          onsubmit="return confirm('<?php esc_attr_e( 'Reject and delete this suggested skill?', 'aicom' ); ?>')">
                        <input type="hidden" name="action"       value="aicom_save">
                        <input type="hidden" name="aicom_action" value="skills_delete">
                        <input type="hidden" name="skill_id"     value="<?php echo (int) $skill['id']; ?>">
                        <input type="hidden" name="confirm"      value="1">
                        <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                        <button type="submit" class="button" style="color:#dc2626;border-color:#fca5a5"><?php esc_html_e( 'Reject', 'aicom' ); ?></button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>

<?php

// ── TAB: Proposals ────────────────────────────────────────────────────────────
elseif ( $active_tab === 'proposals' ) :
    $r     = AICOM_Skills::list( [ 'status' => 'update_proposal' ], 50, 0 );
    $items = $r['items'];
?>

<div class="aicom-card">
    <div class="aicom-card-head">
        <h2 class="aicom-card-title"><?php esc_html_e( 'Update Proposals', 'aicom' ); ?></h2>
        <span style="font-size:0.82em;color:var(--aicom-text-sm)"><?php esc_html_e( 'AI-proposed changes to existing skills. Review the diff before accepting.', 'aicom' ); ?></span>
    </div>
    <div class="aicom-card-body">
    <?php if ( empty( $items ) ) : ?>
        <p style="color:var(--aicom-text-sm);margin:0"><?php esc_html_e( 'No update proposals pending.', 'aicom' ); ?></p>
    <?php else : ?>
        <?php foreach ( $items as $proposal ) :
            $original = $proposal['parent_skill_id'] ? AICOM_Skills::get( (int) $proposal['parent_skill_id'] ) : null;
        ?>
        <div style="border:1px solid #fde68a;border-radius:8px;margin-bottom:12px;overflow:hidden">
            <div style="padding:12px 16px;background:#fffbeb;border-bottom:1px solid #fde68a;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <strong><?php echo esc_html( $original ? $original['name'] : "skill #{$proposal['parent_skill_id']}" ); ?></strong>
                <code style="font-size:0.78em"><?php echo esc_html( $proposal['slug'] ); ?></code>
                <span class="aicom-status aicom-status-warning" style="font-size:0.72em"><?php esc_html_e( 'Update proposed', 'aicom' ); ?></span>
            </div>
            <div style="padding:12px 16px">
                <?php if ( $original && ! empty( $proposal['steps'] ) ) : ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
                    <div>
                        <p style="font-size:0.78em;font-weight:600;margin:0 0 6px;text-transform:uppercase;letter-spacing:.4px;color:var(--aicom-text-sm)"><?php esc_html_e( 'Current', 'aicom' ); ?></p>
                        <ol style="margin:0;padding-left:18px;font-size:0.82em">
                            <?php foreach ( (array) $original['steps'] as $step ) : ?>
                            <li><code style="font-size:0.9em"><?php echo esc_html( $step['action'] ?? $step['tool'] ?? wp_json_encode( $step ) ); ?></code></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                    <div>
                        <p style="font-size:0.78em;font-weight:600;margin:0 0 6px;text-transform:uppercase;letter-spacing:.4px;color:#d97706"><?php esc_html_e( 'Proposed', 'aicom' ); ?></p>
                        <ol style="margin:0;padding-left:18px;font-size:0.82em">
                            <?php foreach ( (array) $proposal['steps'] as $step ) : ?>
                            <li><code style="font-size:0.9em"><?php echo esc_html( $step['action'] ?? $step['tool'] ?? wp_json_encode( $step ) ); ?></code></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                </div>
                <?php endif; ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action"       value="aicom_save">
                        <input type="hidden" name="aicom_action" value="skills_accept_proposal">
                        <input type="hidden" name="proposal_id"  value="<?php echo (int) $proposal['id']; ?>">
                        <input type="hidden" name="original_id"  value="<?php echo (int) $proposal['parent_skill_id']; ?>">
                        <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Accept Update', 'aicom' ); ?></button>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          onsubmit="return confirm('<?php esc_attr_e( 'Reject and delete this proposal?', 'aicom' ); ?>')">
                        <input type="hidden" name="action"       value="aicom_save">
                        <input type="hidden" name="aicom_action" value="skills_delete">
                        <input type="hidden" name="skill_id"     value="<?php echo (int) $proposal['id']; ?>">
                        <input type="hidden" name="confirm"      value="1">
                        <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                        <button type="submit" class="button" style="color:#dc2626;border-color:#fca5a5"><?php esc_html_e( 'Reject', 'aicom' ); ?></button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php include AICOM_DIR . 'admin/partials/layout-bottom.php'; ?>
<?php } )(); ?>
