<?php
defined( 'ABSPATH' ) || exit;

( function () {
global $wpdb;

$active_tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'skills' ) );
$base_url   = admin_url( 'admin.php?page=aicom-skills' );
$tab_url    = fn( string $t ) => add_query_arg( 'tab', $t, $base_url );

// Counts for badges
$count_suggested = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_skills WHERE status = 'suggested'" );
$count_proposals = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_skills WHERE status = 'update_proposal'" );

include AICOM_DIR . 'admin/partials/layout-top.php';
?>

<div class="aicom-page-header">
    <h1 class="aicom-page-title"><?php esc_html_e( 'Skills', 'aicom' ); ?></h1>
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
        <?php if ( $count_suggested > 0 ) : ?><span class="aicom-badge aicom-badge-blue"><?php echo (int) $count_suggested; ?></span><?php endif; ?>
    </a>
    <a href="<?php echo esc_url( $tab_url( 'proposals' ) ); ?>"
       class="aicom-tab-btn <?php echo $active_tab === 'proposals' ? 'is-active' : ''; ?>">
        <?php esc_html_e( 'Proposals', 'aicom' ); ?>
        <?php if ( $count_proposals > 0 ) : ?><span class="aicom-badge aicom-badge-amber"><?php echo (int) $count_proposals; ?></span><?php endif; ?>
    </a>
    <a href="<?php echo esc_url( $tab_url( 'history' ) ); ?>"
       class="aicom-tab-btn <?php echo $active_tab === 'history' ? 'is-active' : ''; ?>">
        <?php esc_html_e( 'History', 'aicom' ); ?>
    </a>
</div>

<?php

// ── TAB: Skills ───────────────────────────────────────────────────────────────
if ( $active_tab === 'skills' ) :

    $search      = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
    $filter_type = sanitize_key( $_GET['filter_type'] ?? '' );
    $filter_status = sanitize_key( $_GET['filter_status'] ?? '' );
    $per_page    = 20;
    $current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $offset      = ( $current_page - 1 ) * $per_page;

    $filters = array_filter( [
        'search' => $search,
        'type'   => $filter_type,
        'status' => $filter_status ?: null,
    ] );

    if ( ! $filter_status ) {
        // Default: show active + draft, not archived/suggested/proposals
        $all_results  = [];
        $total_count  = 0;
        foreach ( [ 'active', 'draft' ] as $st ) {
            $r = AICOM_Skills::list( array_merge( $filters, [ 'status' => $st ] ), 200, 0 );
            $all_results = array_merge( $all_results, $r['items'] );
            $total_count += $r['total'];
        }
        usort( $all_results, fn( $a, $b ) => strcmp( $b['updated_at'], $a['updated_at'] ) );
        $items = array_slice( $all_results, $offset, $per_page );
    } else {
        $r     = AICOM_Skills::list( $filters, $per_page, $offset );
        $items = $r['items'];
        $total_count = $r['total'];
    }

    $total_pages = (int) ceil( $total_count / $per_page );

    $type_labels = [
        'simple'   => __( 'Simple',   'aicom' ),
        'guided'   => __( 'Guided',   'aicom' ),
        'advanced' => __( 'Advanced', 'aicom' ),
    ];
    $status_colors = [
        'active'   => '#16a34a',
        'draft'    => '#6b7280',
        'archived' => '#d97706',
    ];
?>

<div class="aicom-card">
    <div class="aicom-card-head" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <h2 class="aicom-card-title" style="margin:0"><?php esc_html_e( 'All Skills', 'aicom' ); ?></h2>
        <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;margin-left:auto">
            <input type="hidden" name="page" value="aicom-skills">
            <input type="hidden" name="tab"  value="skills">
            <input type="text" name="search" value="<?php echo esc_attr( $search ); ?>"
                   placeholder="<?php esc_attr_e( 'Search skills…', 'aicom' ); ?>"
                   style="padding:5px 10px;border-radius:4px;border:1px solid #ccc;min-width:160px">
            <select name="filter_type" style="padding:5px;border-radius:4px;border:1px solid #ccc">
                <option value=""><?php esc_html_e( 'All types', 'aicom' ); ?></option>
                <?php foreach ( $type_labels as $val => $label ) : ?>
                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_type, $val ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_status" style="padding:5px;border-radius:4px;border:1px solid #ccc">
                <option value=""><?php esc_html_e( 'Active + Draft', 'aicom' ); ?></option>
                <option value="active"   <?php selected( $filter_status, 'active' ); ?>><?php esc_html_e( 'Active only', 'aicom' ); ?></option>
                <option value="draft"    <?php selected( $filter_status, 'draft' ); ?>><?php esc_html_e( 'Draft only', 'aicom' ); ?></option>
                <option value="archived" <?php selected( $filter_status, 'archived' ); ?>><?php esc_html_e( 'Archived', 'aicom' ); ?></option>
            </select>
            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'aicom' ); ?></button>
        </form>
    </div>
    <div class="aicom-card-body" style="padding:0">
        <?php if ( empty( $items ) ) : ?>
        <p style="padding:24px;color:var(--aicom-text-sm);text-align:center">
            <?php esc_html_e( 'No skills yet. AI agents can create skills via skills.create, or import a local definition via skills.import.', 'aicom' ); ?>
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
            <?php foreach ( $items as $skill ) :
                $color = $status_colors[ $skill['status'] ] ?? '#6b7280';
            ?>
            <tr>
                <td>
                    <strong><?php echo esc_html( $skill['name'] ); ?></strong>
                    <?php if ( $skill['description'] ) : ?>
                    <div style="font-size:0.78em;color:var(--aicom-text-sm);margin-top:2px"><?php echo esc_html( wp_trim_words( $skill['description'], 12 ) ); ?></div>
                    <?php endif; ?>
                </td>
                <td><code style="font-size:0.78em"><?php echo esc_html( $skill['slug'] ); ?></code></td>
                <td><span style="font-size:0.78em;background:#f3f4f6;padding:2px 8px;border-radius:999px"><?php echo esc_html( $type_labels[ $skill['type'] ] ?? $skill['type'] ); ?></span></td>
                <td><span style="font-size:0.78em;color:<?php echo esc_attr( $color ); ?>;font-weight:600"><?php echo esc_html( ucfirst( $skill['status'] ) ); ?></span></td>
                <td style="text-align:center">v<?php echo (int) $skill['version']; ?></td>
                <td style="font-size:0.82em;color:var(--aicom-text-sm)"><?php echo esc_html( substr( $skill['updated_at'], 0, 10 ) ); ?></td>
                <td>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <?php if ( $skill['status'] === 'draft' ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action"       value="aicom_save">
                            <input type="hidden" name="aicom_action" value="skills_activate">
                            <input type="hidden" name="skill_id"     value="<?php echo (int) $skill['id']; ?>">
                            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                            <button type="submit" class="button button-primary" style="font-size:0.8em;padding:2px 8px"><?php esc_html_e( 'Activate', 'aicom' ); ?></button>
                        </form>
                        <?php endif; ?>
                        <?php if ( $skill['status'] === 'active' ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action"       value="aicom_save">
                            <input type="hidden" name="aicom_action" value="skills_archive">
                            <input type="hidden" name="skill_id"     value="<?php echo (int) $skill['id']; ?>">
                            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                            <button type="submit" class="button" style="font-size:0.8em;padding:2px 8px"><?php esc_html_e( 'Archive', 'aicom' ); ?></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
        <div style="padding:12px 16px;border-top:1px solid #eee;font-size:0.82em;color:var(--aicom-text-sm)">
            <?php printf( esc_html__( 'Page %1$d of %2$d — %3$d total', 'aicom' ), $current_page, $total_pages, $total_count ); ?>
            <?php if ( $current_page > 1 ) : ?>
            &nbsp;<a href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $tab_url( 'skills' ) ) ); ?>">&laquo; <?php esc_html_e( 'Prev', 'aicom' ); ?></a>
            <?php endif; ?>
            <?php if ( $current_page < $total_pages ) : ?>
            &nbsp;<a href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $tab_url( 'skills' ) ) ); ?>"><?php esc_html_e( 'Next', 'aicom' ); ?> &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php

// ── TAB: Suggested ────────────────────────────────────────────────────────────
elseif ( $active_tab === 'suggested' ) :
    $r     = AICOM_Skills::list( [ 'status' => 'suggested' ], 50, 0 );
    $items = $r['items'];
?>

<div class="aicom-card">
    <div class="aicom-card-head">
        <h2 class="aicom-card-title"><?php esc_html_e( 'Suggested Skills', 'aicom' ); ?></h2>
        <span style="font-size:0.78em;color:var(--aicom-text-sm)"><?php esc_html_e( 'Skills imported or proposed by AI agents, waiting for your review.', 'aicom' ); ?></span>
    </div>
    <div class="aicom-card-body">
    <?php if ( empty( $items ) ) : ?>
        <p style="color:var(--aicom-text-sm)"><?php esc_html_e( 'No suggested skills. AI agents can push local skill definitions here via skills.import.', 'aicom' ); ?></p>
    <?php else : ?>
        <?php foreach ( $items as $skill ) : ?>
        <div class="aicom-card" style="margin-bottom:16px;border:1px solid #d1fae5">
            <div class="aicom-card-head" style="background:#f0fdf4">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <strong><?php echo esc_html( $skill['name'] ); ?></strong>
                    <code style="font-size:0.78em"><?php echo esc_html( $skill['slug'] ); ?></code>
                    <span style="font-size:0.78em;background:#dcfce7;color:#166534;padding:2px 8px;border-radius:999px"><?php echo esc_html( ucfirst( $skill['type'] ) ); ?></span>
                </div>
                <?php if ( $skill['description'] ) : ?>
                <p style="margin:6px 0 0;font-size:0.85em;color:var(--aicom-text-sm)"><?php echo esc_html( $skill['description'] ); ?></p>
                <?php endif; ?>
            </div>
            <div class="aicom-card-body">
                <?php if ( ! empty( $skill['steps'] ) ) : ?>
                <p style="font-size:0.78em;font-weight:600;margin-bottom:4px"><?php esc_html_e( 'Steps:', 'aicom' ); ?></p>
                <ol style="margin:0 0 12px;padding-left:18px;font-size:0.82em">
                    <?php foreach ( (array) $skill['steps'] as $i => $step ) : ?>
                    <li><?php echo esc_html( $step['action'] ?? $step['tool'] ?? wp_json_encode( $step ) ); ?></li>
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
        <span style="font-size:0.78em;color:var(--aicom-text-sm)"><?php esc_html_e( 'AI-proposed updates to existing skills. Review changes before accepting.', 'aicom' ); ?></span>
    </div>
    <div class="aicom-card-body">
    <?php if ( empty( $items ) ) : ?>
        <p style="color:var(--aicom-text-sm)"><?php esc_html_e( 'No update proposals pending.', 'aicom' ); ?></p>
    <?php else : ?>
        <?php foreach ( $items as $proposal ) :
            $original = $proposal['parent_skill_id'] ? AICOM_Skills::get( (int) $proposal['parent_skill_id'] ) : null;
        ?>
        <div class="aicom-card" style="margin-bottom:16px;border:1px solid #fde68a">
            <div class="aicom-card-head" style="background:#fffbeb">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <strong><?php esc_html_e( 'Proposal for:', 'aicom' ); ?> <?php echo esc_html( $original ? $original['name'] : "skill #{$proposal['parent_skill_id']}" ); ?></strong>
                    <code style="font-size:0.78em"><?php echo esc_html( $proposal['slug'] ); ?></code>
                </div>
            </div>
            <div class="aicom-card-body">
                <?php if ( $original && ! empty( $proposal['steps'] ) ) : ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
                    <div>
                        <p style="font-size:0.78em;font-weight:600;margin-bottom:4px;color:#6b7280"><?php esc_html_e( 'Current steps:', 'aicom' ); ?></p>
                        <ol style="margin:0;padding-left:18px;font-size:0.82em">
                            <?php foreach ( (array) $original['steps'] as $step ) : ?>
                            <li><?php echo esc_html( $step['action'] ?? $step['tool'] ?? wp_json_encode( $step ) ); ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                    <div>
                        <p style="font-size:0.78em;font-weight:600;margin-bottom:4px;color:#d97706"><?php esc_html_e( 'Proposed steps:', 'aicom' ); ?></p>
                        <ol style="margin:0;padding-left:18px;font-size:0.82em">
                            <?php foreach ( (array) $proposal['steps'] as $step ) : ?>
                            <li><?php echo esc_html( $step['action'] ?? $step['tool'] ?? wp_json_encode( $step ) ); ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                </div>
                <?php endif; ?>

                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action"         value="aicom_save">
                        <input type="hidden" name="aicom_action"   value="skills_accept_proposal">
                        <input type="hidden" name="proposal_id"    value="<?php echo (int) $proposal['id']; ?>">
                        <input type="hidden" name="original_id"    value="<?php echo (int) $proposal['parent_skill_id']; ?>">
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

<?php

// ── TAB: History ──────────────────────────────────────────────────────────────
elseif ( $active_tab === 'history' ) :
    $per_page    = 30;
    $current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $offset      = ( $current_page - 1 ) * $per_page;

    $total = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_logs WHERE tool_name = 'skills.run'"
    );
    $logs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT l.id, l.created_at, l.tool_name, l.target_id, l.status, l.api_key_label, l.session_id
             FROM {$wpdb->prefix}aicom_logs l
             WHERE l.tool_name = 'skills.run'
             ORDER BY l.created_at DESC
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ),
        ARRAY_A
    ) ?: [];

    $total_pages = (int) ceil( $total / $per_page );
?>

<div class="aicom-card">
    <div class="aicom-card-head">
        <h2 class="aicom-card-title"><?php esc_html_e( 'Execution History', 'aicom' ); ?></h2>
        <span style="font-size:0.78em;color:var(--aicom-text-sm)"><?php printf( esc_html__( '%d total skill runs', 'aicom' ), $total ); ?></span>
    </div>
    <div class="aicom-card-body" style="padding:0">
    <?php if ( empty( $logs ) ) : ?>
        <p style="padding:24px;color:var(--aicom-text-sm)"><?php esc_html_e( 'No skills have been run yet.', 'aicom' ); ?></p>
    <?php else : ?>
        <table class="aicom-keys-table" style="width:100%">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Skill', 'aicom' ); ?></th>
                    <th><?php esc_html_e( 'Ran at', 'aicom' ); ?></th>
                    <th><?php esc_html_e( 'API Key', 'aicom' ); ?></th>
                    <th><?php esc_html_e( 'Session', 'aicom' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'aicom' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $logs as $log ) :
                $skill_id = (int) $log['target_id'];
                $skill    = $skill_id ? AICOM_Skills::get( $skill_id ) : null;
                $ok       = $log['status'] === 'success';
            ?>
            <tr>
                <td>
                    <?php if ( $skill ) : ?>
                    <strong><?php echo esc_html( $skill['name'] ); ?></strong>
                    <div style="font-size:0.78em;color:var(--aicom-text-sm)"><code><?php echo esc_html( $skill['slug'] ); ?></code></div>
                    <?php else : ?>
                    <span style="color:var(--aicom-text-sm)">skill #<?php echo $skill_id ?: '—'; ?></span>
                    <?php endif; ?>
                </td>
                <td style="font-size:0.82em"><?php echo esc_html( $log['created_at'] ); ?></td>
                <td style="font-size:0.82em"><?php echo esc_html( $log['api_key_label'] ?: '—' ); ?></td>
                <td>
                    <?php if ( $log['session_id'] ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicom-audit-logs&session_id=' . (int) $log['session_id'] ) ); ?>" style="font-size:0.82em">#<?php echo (int) $log['session_id']; ?></a>
                    <?php else : ?>—<?php endif; ?>
                </td>
                <td>
                    <span style="font-size:0.78em;color:<?php echo $ok ? '#16a34a' : '#dc2626'; ?>;font-weight:600">
                        <?php echo esc_html( $ok ? __( 'Success', 'aicom' ) : __( 'Error', 'aicom' ) ); ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
        <div style="padding:12px 16px;border-top:1px solid #eee;font-size:0.82em;color:var(--aicom-text-sm)">
            <?php printf( esc_html__( 'Page %1$d of %2$d', 'aicom' ), $current_page, $total_pages ); ?>
            <?php if ( $current_page > 1 ) : ?>
            &nbsp;<a href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $tab_url( 'history' ) ) ); ?>">&laquo; <?php esc_html_e( 'Prev', 'aicom' ); ?></a>
            <?php endif; ?>
            <?php if ( $current_page < $total_pages ) : ?>
            &nbsp;<a href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $tab_url( 'history' ) ) ); ?>"><?php esc_html_e( 'Next', 'aicom' ); ?> &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php include AICOM_DIR . 'admin/partials/layout-bottom.php'; ?>
<?php } )(); ?>
