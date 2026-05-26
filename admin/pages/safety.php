<?php
defined( 'ABSPATH' ) || exit;

( function () {
$lock_state = AICOM_Lock_Manager::get_state();

if ( $lock_state['effective_lock'] === 'hard_locked' ) {
    $badge_label = __( 'Hard Lock Active', 'aicom' );
    $badge_class = 'aicom-badge-danger';
    $lock_desc   = __( 'Only <code>server.status</code> is allowed. All other tools are blocked.', 'aicom' );
} elseif ( $lock_state['effective_lock'] === 'soft_locked' ) {
    $badge_label = __( 'Soft Lock Active', 'aicom' );
    $badge_class = 'aicom-badge-warning';
    $lock_desc   = __( 'Write, destructive, and admin-sensitive tools are blocked. Read and discovery tools are permitted.', 'aicom' );
} else {
    $badge_label = __( 'Unlocked', 'aicom' );
    $badge_class = 'aicom-badge-success';
    $lock_desc   = __( 'All tools permitted (subject to scope and allowlist checks).', 'aicom' );
}
?>
<?php include AICOM_DIR . 'admin/partials/layout-top.php'; ?>

    <div class="aicom-page-header">
        <h1><?php esc_html_e( 'Safety Controls', 'aicom' ); ?></h1>
        <p class="aicom-page-desc"><?php esc_html_e( 'Control lock mode to restrict what AI agents can do site-wide.', 'aicom' ); ?></p>
    </div>

    <?php if ( isset( $_GET['updated'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Lock settings updated.', 'aicom' ); ?></p></div>
    <?php endif; ?>

    <!-- Current Status -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Current Lock Status', 'aicom' ); ?></h2>
            <span class="aicom-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span>
        </div>
        <div class="aicom-card-body">
            <p style="margin:0;color:var(--aicom-text-sm);font-size:0.88em"><?php echo wp_kses( $lock_desc, [ 'code' => [], 'strong' => [], 'em' => [] ] ); ?></p>
            <?php
            $sched = AICOM_Lock_Manager::get_schedule();
            if ( ! empty( $sched['enabled'] ) ) :
                $sched_lock     = AICOM_Lock_Manager::get_schedule_lock();
                $override_until = $lock_state['schedule_override_until'] ?? 0;
                $is_override    = $override_until > time();
                $in_hours       = $sched_lock === 'unlocked';
            ?>
            <p style="margin:8px 0 0;font-size:0.82em;color:var(--aicom-text-sm)">
                <?php if ( $is_override ) : ?>
                    &#128275; <?php printf(
                        esc_html__( 'Schedule: overridden until %s (next working period).', 'aicom' ),
                        esc_html( wp_date( 'D H:i', $override_until ) )
                    ); ?>
                <?php elseif ( $in_hours ) : ?>
                    &#128336; <?php esc_html_e( 'Schedule: within working hours — no additional lock.', 'aicom' ); ?>
                <?php else : ?>
                    &#128274; <?php printf(
                        esc_html__( 'Schedule: outside working hours — %s enforced.', 'aicom' ),
                        $sched_lock === 'hard_locked'
                            ? esc_html__( 'Hard Lock', 'aicom' )
                            : esc_html__( 'Soft Lock', 'aicom' )
                    ); ?>
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Lock Controls -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Lock Controls', 'aicom' ); ?></h2>
        </div>
        <div class="aicom-card-body">
            <p style="margin:0 0 20px;font-size:0.85em;color:var(--aicom-text-sm)"><?php esc_html_e( 'Hard Lock overrides Soft Lock. Changes take effect immediately for all subsequent requests.', 'aicom' ); ?></p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="aicom_save" />
                <input type="hidden" name="aicom_action" value="set_lock" />
                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>

                <div class="aicom-form">

                    <div class="aicom-field-row">
                        <label class="aicom-field-label" for="aicom-soft-lock">
                            <span class="aicom-term" tabindex="0" data-define="<?php esc_attr_e( 'Agents can read and browse the site but cannot create, change, or delete anything. Safe pause button.', 'aicom' ); ?>"><?php esc_html_e( 'Soft Lock', 'aicom' ); ?></span>
                            <small><?php esc_html_e( 'Read-only mode', 'aicom' ); ?></small>
                        </label>
                        <div class="aicom-field-control">
                            <label class="aicom-toggle-label">
                                <input type="checkbox" name="soft_lock" value="1"
                                    id="aicom-soft-lock"
                                    <?php checked( $lock_state['soft_lock'] ); ?>
                                    <?php disabled( $lock_state['hard_lock'] ); ?> />
                                <?php esc_html_e( 'Enable Soft Lock', 'aicom' ); ?>
                            </label>
                            <p class="aicom-field-desc"><?php echo wp_kses( __( 'Permits <em>public</em>, <em>discovery</em>, <em>read</em> tools only. Blocks write, destructive, admin_sensitive.', 'aicom' ), [ 'em' => [] ] ); ?></p>
                        </div>
                    </div>

                    <div class="aicom-field-row">
                        <label class="aicom-field-label" for="aicom-hard-lock">
                            <span class="aicom-term" tabindex="0" data-define="<?php esc_attr_e( 'Total freeze. Only server.status responds. Agents are blocked from doing anything. Use when something feels wrong.', 'aicom' ); ?>"><?php esc_html_e( 'Hard Lock', 'aicom' ); ?></span>
                            <small><?php esc_html_e( 'Emergency freeze', 'aicom' ); ?></small>
                        </label>
                        <div class="aicom-field-control">
                            <label class="aicom-toggle-label">
                                <input type="checkbox" name="hard_lock" value="1"
                                    id="aicom-hard-lock"
                                    <?php checked( $lock_state['hard_lock'] ); ?> />
                                <?php esc_html_e( 'Enable Hard Lock', 'aicom' ); ?>
                            </label>
                            <p class="aicom-field-desc"><?php echo wp_kses( __( 'Permits <em>public</em> tools only (<code>server.status</code>). <strong>Hard Lock overrides Soft Lock.</strong>', 'aicom' ), [ 'em' => [], 'code' => [], 'strong' => [] ] ); ?></p>
                        </div>
                    </div>

                </div>

                <?php if ( $lock_state['hard_lock'] ) : ?>
                <div class="notice notice-warning inline" style="margin:0 0 16px"><p><?php echo wp_kses( __( '<strong>Hard Lock is active.</strong> Soft Lock is disabled and ignored.', 'aicom' ), [ 'strong' => [] ] ); ?></p></div>
                <?php endif; ?>

                <div class="aicom-form-footer">
                    <?php submit_button( __( 'Apply Lock Settings', 'aicom' ), 'primary', 'submit', false ); ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Working Hours Schedule -->
    <?php
    $sched        = AICOM_Lock_Manager::get_schedule();
    $s_enabled    = ! empty( $sched['enabled'] );
    $s_days       = array_map( 'intval', $sched['days'] ?? [ 1, 2, 3, 4, 5 ] );
    $s_start      = $sched['start']     ?? '09:00';
    $s_end        = $sched['end']       ?? '18:00';
    $s_lock_type  = $sched['lock_type'] ?? 'soft_locked';
    $day_labels   = [ 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat' ];
    ?>

    <?php if ( isset( $_GET['schedule_saved'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Schedule saved.', 'aicom' ); ?></p></div>
    <?php endif; ?>

    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Working Hours Schedule', 'aicom' ); ?></h2>
            <span class="aicom-badge <?php echo $s_enabled ? 'aicom-badge-success' : 'aicom-badge-neutral'; ?>">
                <?php echo $s_enabled ? esc_html__( 'Enabled', 'aicom' ) : esc_html__( 'Disabled', 'aicom' ); ?>
            </span>
        </div>
        <div class="aicom-card-body">
            <p style="margin:0 0 16px;color:var(--aicom-text-sm);font-size:0.88em">
                <?php esc_html_e( 'Outside working hours, all MCP requests are automatically locked at the configured level. Manual locks always take precedence.', 'aicom' ); ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action"       value="aicom_save" />
                <input type="hidden" name="aicom_action" value="save_schedule" />
                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>

                <div class="aicom-form">

                    <div class="aicom-field-row">
                        <label class="aicom-field-label"><?php esc_html_e( 'Enable Schedule', 'aicom' ); ?></label>
                        <div class="aicom-field-control">
                            <label class="aicom-toggle-label">
                                <input type="checkbox" name="schedule_enabled" value="1" <?php checked( $s_enabled ); ?> />
                                <?php esc_html_e( 'Lock site outside working hours', 'aicom' ); ?>
                            </label>
                        </div>
                    </div>

                    <div class="aicom-field-row">
                        <label class="aicom-field-label"><?php esc_html_e( 'Working Days', 'aicom' ); ?></label>
                        <div class="aicom-field-control">
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <?php foreach ( $day_labels as $d_num => $d_label ) : ?>
                                <label style="display:flex;flex-direction:column;align-items:center;gap:3px;cursor:pointer">
                                    <span style="font-size:0.72em;font-weight:600;color:var(--aicom-text-xs)"><?php echo esc_html( $d_label ); ?></span>
                                    <input type="checkbox" name="schedule_days[]" value="<?php echo (int) $d_num; ?>"
                                           <?php checked( in_array( $d_num, $s_days, true ) ); ?>
                                           style="width:18px;height:18px;cursor:pointer" />
                                </label>
                            <?php endforeach; ?>
                            </div>
                            <p class="aicom-field-desc"><?php esc_html_e( 'Checked days are treated as working days.', 'aicom' ); ?></p>
                        </div>
                    </div>

                    <div class="aicom-field-row">
                        <label class="aicom-field-label"><?php esc_html_e( 'Working Hours', 'aicom' ); ?></label>
                        <div class="aicom-field-control">
                            <div style="display:flex;align-items:center;gap:10px">
                                <input type="time" name="schedule_start" value="<?php echo esc_attr( $s_start ); ?>"
                                       style="font-size:0.88em;padding:5px 8px;border:1px solid var(--aicom-border);border-radius:4px" />
                                <span style="color:var(--aicom-text-xs);font-size:0.85em"><?php esc_html_e( 'to', 'aicom' ); ?></span>
                                <input type="time" name="schedule_end" value="<?php echo esc_attr( $s_end ); ?>"
                                       style="font-size:0.88em;padding:5px 8px;border:1px solid var(--aicom-border);border-radius:4px" />
                            </div>
                            <p class="aicom-field-desc">
                                <?php printf(
                                    esc_html__( 'Site timezone: %s', 'aicom' ),
                                    esc_html( wp_timezone_string() )
                                ); ?>
                            </p>
                        </div>
                    </div>

                    <div class="aicom-field-row">
                        <label class="aicom-field-label"><?php esc_html_e( 'Outside Hours Lock', 'aicom' ); ?></label>
                        <div class="aicom-field-control">
                            <label class="aicom-toggle-label" style="margin-bottom:8px">
                                <input type="radio" name="schedule_lock_type" value="soft_locked"
                                       <?php checked( $s_lock_type, 'soft_locked' ); ?> />
                                <?php esc_html_e( 'Soft Lock — read-only mode (discovery & read tools still work)', 'aicom' ); ?>
                            </label>
                            <label class="aicom-toggle-label">
                                <input type="radio" name="schedule_lock_type" value="hard_locked"
                                       <?php checked( $s_lock_type, 'hard_locked' ); ?> />
                                <?php esc_html_e( 'Hard Lock — full block (only public tools work)', 'aicom' ); ?>
                            </label>
                        </div>
                    </div>

                </div>
                <div class="aicom-form-footer">
                    <?php submit_button( __( 'Save Schedule', 'aicom' ), 'primary', 'submit', false ); ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Lock Permission Matrix -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Lock Permission Matrix', 'aicom' ); ?></h2>
        </div>
        <div class="aicom-card-body" style="padding:0">
            <table class="aicom-keys-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Tool Class', 'aicom' ); ?></th>
                        <th><?php esc_html_e( 'Examples', 'aicom' ); ?></th>
                        <th style="text-align:center"><?php esc_html_e( 'Unlocked', 'aicom' ); ?></th>
                        <th style="text-align:center"><?php esc_html_e( 'Soft Lock', 'aicom' ); ?></th>
                        <th style="text-align:center"><?php esc_html_e( 'Hard Lock', 'aicom' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $matrix = [
                        [ 'public',          'server.status',                    '✓', '✓', '✓' ],
                        [ 'discovery',       'tools/list, wp.post_types.list',   '✓', '✓', '✗' ],
                        [ 'read',            'wp.posts.list, wp.posts.get',      '✓', '✓', '✗' ],
                        [ 'write',           'wp.posts.create, wp.posts.update', '✓', '✗', '✗' ],
                        [ 'destructive',     'wp.posts.delete, wp.terms.delete', '✓', '✗', '✗' ],
                        [ 'admin_sensitive', 'wp.options.set, wp.users.create',  '✓', '✗', '✗' ],
                    ];
                    foreach ( $matrix as [ $class, $examples, $unlocked, $soft, $hard ] ) : ?>
                    <tr>
                        <td><span class="aicom-class-badge aicom-class-<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $class ); ?></span></td>
                        <td style="font-size:0.82em;color:var(--aicom-text-sm)"><?php echo esc_html( $examples ); ?></td>
                        <td class="aicom-cell-<?php echo esc_attr( $unlocked === '✓' ? 'yes' : 'no' ); ?>"><?php echo esc_html( $unlocked ); ?></td>
                        <td class="aicom-cell-<?php echo esc_attr( $soft === '✓' ? 'yes' : 'no' ); ?>"><?php echo esc_html( $soft ); ?></td>
                        <td class="aicom-cell-<?php echo esc_attr( $hard === '✓' ? 'yes' : 'no' ); ?>"><?php echo esc_html( $hard ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Hub Pairing (PRD §16.2) -->
    <?php
    $pairing_token = ! empty( $_GET['pairing_generated'] ) ? get_transient( 'aicom_new_pairing_token' ) : '';
    if ( $pairing_token ) {
        delete_transient( 'aicom_new_pairing_token' );
    }
    global $wpdb;
    $paired_hubs = $wpdb->get_results( "SELECT hub_id, hub_url, paired_at, last_seen FROM {$wpdb->prefix}aicom_hub_pairings ORDER BY paired_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    ?>
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'AICOM Hub Pairing', 'aicom' ); ?></h2>
            <span class="aicom-badge <?php echo $paired_hubs ? 'aicom-badge-success' : ''; ?>">
                <?php echo $paired_hubs
                    ? esc_html( sprintf( _n( '%d paired hub', '%d paired hubs', count( $paired_hubs ), 'aicom' ), count( $paired_hubs ) ) )
                    : esc_html__( 'Not paired', 'aicom' ); ?>
            </span>
        </div>
        <div class="aicom-card-body">
            <p style="margin:0 0 14px;color:var(--aicom-text-sm);font-size:0.88em">
                <?php esc_html_e( 'Generate a one-time pairing token to connect this site to an AICOM Hub. The token is valid for 10 minutes and consumed on first use.', 'aicom' ); ?>
            </p>

            <?php if ( $pairing_token ) : ?>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px;margin-bottom:14px">
                    <p style="margin:0 0 6px;font-size:0.82em;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:.5px">
                        <?php esc_html_e( 'Pairing token — copy now, shown once', 'aicom' ); ?>
                    </p>
                    <input type="text" readonly value="<?php echo esc_attr( $pairing_token ); ?>"
                           onclick="this.select()"
                           style="width:100%;font-family:'JetBrains Mono',monospace;font-size:13px;padding:9px 12px;border:1.5px solid #bbf7d0;border-radius:7px;background:#fff" />
                    <p style="margin:8px 0 0;font-size:0.78em;color:#15803d">
                        <?php esc_html_e( 'Paste this into AICOM Hub → Sites → Pair a site within 10 minutes.', 'aicom' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                <input type="hidden" name="action" value="aicom_save" />
                <input type="hidden" name="aicom_action" value="generate_pairing_token" />
                <button type="submit" class="button button-primary">
                    <?php echo $pairing_token ? esc_html__( 'Generate new token', 'aicom' ) : esc_html__( 'Generate pairing token', 'aicom' ); ?>
                </button>
            </form>

            <?php if ( $paired_hubs ) : ?>
                <table class="widefat" style="margin-top:16px">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Hub ID', 'aicom' ); ?></th>
                            <th><?php esc_html_e( 'Hub URL', 'aicom' ); ?></th>
                            <th><?php esc_html_e( 'Paired', 'aicom' ); ?></th>
                            <th><?php esc_html_e( 'Last seen', 'aicom' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $paired_hubs as $h ) : ?>
                            <tr>
                                <td><code style="font-size:0.85em"><?php echo esc_html( $h['hub_id'] ); ?></code></td>
                                <td><a href="<?php echo esc_url( $h['hub_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $h['hub_url'] ); ?></a></td>
                                <td><?php echo esc_html( $h['paired_at'] ); ?></td>
                                <td><?php echo esc_html( $h['last_seen'] ?: '—' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

<?php include AICOM_DIR . 'admin/partials/layout-bottom.php'; ?>
<?php
} )();
