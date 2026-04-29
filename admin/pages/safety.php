<?php
defined( 'ABSPATH' ) || exit;

( function () {
$lock_state = AICOM_Lock_Manager::get_state();

if ( $lock_state['effective_lock'] === 'hard_locked' ) {
    $badge_label = 'Hard Lock Active';
} elseif ( $lock_state['effective_lock'] === 'soft_locked' ) {
    $badge_label = 'Soft Lock Active';
} else {
    $badge_label = 'Unlocked';
}
if ( $lock_state['effective_lock'] === 'hard_locked' ) {
    $badge_class = 'aicom-badge-danger';
} elseif ( $lock_state['effective_lock'] === 'soft_locked' ) {
    $badge_class = 'aicom-badge-warning';
} else {
    $badge_class = 'aicom-badge-success';
}
?>
<div class="wrap aicom-wrap">
    <h1>Safety Controls</h1>

    <?php if ( isset( $_GET['updated'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p>Lock settings updated.</p></div>
    <?php endif; ?>

    <!-- Current Status -->
    <div class="aicom-card">
        <h2>Current Lock Status</h2>
        <p class="aicom-badge <?php echo esc_attr( $badge_class ); ?>" style="font-size:1.1em"><?php echo esc_html( $badge_label ); ?></p>
        <p class="description">
            <?php
            if ( $lock_state['effective_lock'] === 'hard_locked' ) {
                $lock_desc = 'Only <code>server.status</code> is allowed. All other tools are blocked.';
            } elseif ( $lock_state['effective_lock'] === 'soft_locked' ) {
                $lock_desc = 'Write, destructive, and admin-sensitive tools are blocked. Read and discovery tools are permitted.';
            } else {
                $lock_desc = 'All tools permitted (subject to scope and allowlist checks).';
            }
            echo wp_kses( $lock_desc, [ 'code' => [] ] );
            ?>
        </p>
    </div>

    <!-- Lock Controls -->
    <div class="aicom-card">
        <h2>Lock Controls</h2>
        <p class="description">Hard Lock overrides Soft Lock. Changes take effect immediately for all subsequent requests.</p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="aicom_save" />
            <input type="hidden" name="aicom_action" value="set_lock" />
            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th>Soft Lock</th>
                    <td>
                        <label>
                            <input type="checkbox" name="soft_lock" value="1"
                                <?php checked( $lock_state['soft_lock'] ); ?>
                                <?php disabled( $lock_state['hard_lock'] ); ?>
                                id="aicom-soft-lock"
                            />
                            Enable Soft Lock
                        </label>
                        <p class="description">Permits <em>public</em>, <em>discovery</em>, <em>read</em> tools only.<br>Blocks: write, destructive, admin_sensitive.</p>
                    </td>
                </tr>
                <tr>
                    <th>Hard Lock</th>
                    <td>
                        <label>
                            <input type="checkbox" name="hard_lock" value="1"
                                <?php checked( $lock_state['hard_lock'] ); ?>
                                id="aicom-hard-lock"
                            />
                            Enable Hard Lock
                        </label>
                        <p class="description">Permits <em>public</em> tools only (<code>server.status</code>).<br><strong>Hard Lock overrides Soft Lock.</strong></p>
                    </td>
                </tr>
            </table>

            <?php if ( $lock_state['hard_lock'] ) : ?>
            <div class="notice notice-warning inline"><p><strong>Hard Lock is active.</strong> Soft Lock is disabled and ignored.</p></div>
            <?php endif; ?>

            <?php submit_button( 'Apply Lock Settings', 'primary', 'submit', false ); ?>
        </form>
    </div>

    <!-- Lock Behavior Reference -->
    <div class="aicom-card">
        <h2>Lock Permission Matrix</h2>
        <table class="wp-list-table widefat fixed">
            <thead>
                <tr>
                    <th>Tool Class</th>
                    <th>Examples</th>
                    <th>Unlocked</th>
                    <th>Soft Lock</th>
                    <th>Hard Lock</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $matrix = [
                    [ 'public',          'server.status',              '✓', '✓', '✓' ],
                    [ 'discovery',       'tools/list, wp.post_types.list', '✓', '✓', '✗' ],
                    [ 'read',            'wp.posts.list, wp.posts.get', '✓', '✓', '✗' ],
                    [ 'write',           'wp.posts.create, wp.posts.update', '✓', '✗', '✗' ],
                    [ 'destructive',     'wp.posts.delete, wp.terms.delete', '✓', '✗', '✗' ],
                    [ 'admin_sensitive', 'wp.options.set, wp.users.create', '✓', '✗', '✗' ],
                ];
                foreach ( $matrix as [ $class, $examples, $unlocked, $soft, $hard ] ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $class ); ?></code></td>
                    <td><?php echo esc_html( $examples ); ?></td>
                    <td class="aicom-cell-<?php echo esc_attr( $unlocked === '✓' ? 'yes' : 'no' ); ?>"><?php echo esc_html( $unlocked ); ?></td>
                    <td class="aicom-cell-<?php echo esc_attr( $soft === '✓' ? 'yes' : 'no' ); ?>"><?php echo esc_html( $soft ); ?></td>
                    <td class="aicom-cell-<?php echo esc_attr( $hard === '✓' ? 'yes' : 'no' ); ?>"><?php echo esc_html( $hard ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
} )();
