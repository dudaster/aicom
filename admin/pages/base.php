<?php
defined( 'ABSPATH' ) || exit;

( function () {
    global $wpdb;

    $status  = AICOM_Base_State::status();
    $ui      = AICOM_Base_Connection::ui_state();
    $state   = AICOM_Base_State::all();
    $nonce   = wp_create_nonce( AICOM_Base_Admin::NONCE );
    $notice  = (string) ( $state['notice'] ?? '' );
    $base    = AICOM_Base_State::base_url();
    $ident   = AICOM_Base_Identity::public_info();

    $labels = [
        'none'      => [ __( 'Not connected', 'aicom' ),     '' ],
        'pairing'   => [ __( 'Waiting for confirmation', 'aicom' ), 'aicom-badge-warning' ],
        'connected' => [ __( 'Connected', 'aicom' ),         'aicom-badge-success' ],
        'offline'   => [ __( 'Offline', 'aicom' ),           'aicom-badge-warning' ],
        'attention' => [ __( 'Needs attention', 'aicom' ),   'aicom-badge-warning' ],
        'paused'    => [ __( 'Remote work paused', 'aicom' ), 'aicom-badge-warning' ],
        'locked'    => [ __( 'Locked', 'aicom' ),            'aicom-badge-danger' ],
        'revoked'   => [ __( 'Revoked', 'aicom' ),           'aicom-badge-danger' ],
    ];
    [ $badge_label, $badge_class ] = $labels[ $ui ] ?? $labels['none'];

    $msgs = [
        'disconnected' => __( 'This site was disconnected from AICOMBase.', 'aicom' ),
        'synced'       => __( 'Synced with AICOMBase.', 'aicom' ),
        'sync_failed'  => __( 'Could not reach AICOMBase — see the connection log below. AICOM keeps working normally.', 'aicom' ),
        'paused'       => __( 'Remote work paused. AICOMBase will not send tasks to this site until you resume.', 'aicom' ),
        'resumed'      => __( 'Remote work resumed.', 'aicom' ),
        'saved'        => __( 'Settings saved.', 'aicom' ),
    ];
    $flash     = sanitize_key( wp_unslash( $_GET['base_msg'] ?? '' ) );
    $url_error = get_transient( 'aicom_base_admin_error' );
    if ( $url_error ) {
        delete_transient( 'aicom_base_admin_error' );
    }

    $ago = static function ( int $ts ): string {
        return $ts ? sprintf( /* translators: %s: relative time */ __( '%s ago', 'aicom' ), human_time_diff( $ts, time() ) ) : __( 'never', 'aicom' );
    };

    $log = $wpdb->get_results( "SELECT created_at, tool_name, status, result_summary_json FROM {$wpdb->prefix}aicom_logs WHERE module = 'base' ORDER BY id DESC LIMIT 10", ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $allowed = AICOM_Base_Policy::allowed_scopes();
?>
<?php include AICOM_DIR . 'admin/partials/layout-top.php'; ?>

    <div class="aicom-page-header">
        <h1><?php esc_html_e( 'AICOMBase', 'aicom' ); ?></h1>
        <p class="aicom-page-desc"><?php esc_html_e( 'Optional: connect this site to AICOMBase for monitoring, audits and centrally managed AICOM work. AICOM keeps working fully without it.', 'aicom' ); ?></p>
    </div>

    <?php if ( isset( $msgs[ $flash ] ) ) : ?>
        <div class="notice notice-<?php echo esc_attr( $flash === 'sync_failed' ? 'warning' : 'success' ); ?> is-dismissible"><p><?php echo esc_html( $msgs[ $flash ] ); ?></p></div>
    <?php endif; ?>
    <?php if ( $url_error ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html( $url_error ); ?></p></div>
    <?php endif; ?>
    <div id="aicom-base-notice" class="notice notice-error" style="<?php echo $notice ? '' : 'display:none'; ?>"><p><?php echo esc_html( $notice ); ?></p></div>

    <div class="aicom-card" id="aicom-base-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'AICOMBase', 'aicom' ); ?></h2>
            <span class="aicom-badge <?php echo esc_attr( $badge_class ); ?>" id="aicom-base-badge"><?php echo esc_html( $badge_label ); ?></span>
        </div>
        <div class="aicom-card-body">

        <?php if ( $status === AICOM_Base_State::S_NONE || $status === AICOM_Base_State::S_REVOKED ) : ?>
            <?php if ( $status === AICOM_Base_State::S_REVOKED ) : ?>
                <p style="margin:0 0 12px;color:#991b1b"><?php esc_html_e( 'This connection was revoked from AICOMBase. The pairing and keys were wiped from this site. You can connect again at any time.', 'aicom' ); ?></p>
            <?php endif; ?>
            <p style="margin:0 0 14px;font-size:0.92em">
                <?php esc_html_e( 'Connect this site to AICOMBase to monitor it, view AICOM activity and use AICOMBase services.', 'aicom' ); ?>
            </p>
            <ul style="margin:0 0 16px 18px;list-style:disc;font-size:0.86em;color:var(--aicom-text-sm)">
                <li><?php esc_html_e( 'Identifies this installation with a key pair — the private key never leaves this site.', 'aicom' ); ?></li>
                <li><?php esc_html_e( 'Sends health, versions and AICOM activity (no site content, no passwords, no API keys).', 'aicom' ); ?></li>
                <li><?php esc_html_e( 'AICOMBase can request work; every task is verified here against your local policy below, and locks always win.', 'aicom' ); ?></li>
            </ul>
            <button type="button" class="button button-primary button-hero" id="aicom-base-connect"><?php esc_html_e( 'Connect', 'aicom' ); ?></button>
            <p class="description" style="margin-top:8px"><?php
                /* translators: %s: AICOMBase URL */
                printf( esc_html__( 'You will sign in to AICOMBase (%s) in a new tab and confirm the connection there.', 'aicom' ), '<code>' . esc_html( $base ) . '</code>' );
            ?></p>

        <?php elseif ( $status === AICOM_Base_State::S_PAIRING ) : ?>
            <p style="margin:0 0 12px"><span class="spinner is-active" style="float:none;margin:0 6px 0 0"></span><?php esc_html_e( 'Waiting for you to confirm the connection in AICOMBase…', 'aicom' ); ?></p>
            <p>
                <a class="button button-primary" id="aicom-base-open" target="_blank" rel="noopener" href="<?php echo esc_url( $state['pairing']['connect_url'] ?? '#' ); ?>"><?php esc_html_e( 'Open AICOMBase to confirm', 'aicom' ); ?></a>
                <button type="button" class="button" id="aicom-base-cancel"><?php esc_html_e( 'Cancel', 'aicom' ); ?></button>
            </p>
            <p class="description"><?php esc_html_e( 'The pairing request is valid for 10 minutes.', 'aicom' ); ?></p>

        <?php else : ?>
            <table class="widefat striped" style="max-width:720px">
                <tbody>
                    <tr><th style="width:220px"><?php esc_html_e( 'Last communication', 'aicom' ); ?></th><td><?php echo esc_html( $ago( (int) ( $state['last_ok_at'] ?? 0 ) ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'AICOMBase', 'aicom' ); ?></th><td><code><?php echo esc_html( (string) ( $state['base_url'] ?? $base ) ); ?></code></td></tr>
                    <tr><th><?php esc_html_e( 'Site identity', 'aicom' ); ?></th><td><code><?php echo esc_html( substr( (string) $state['site_id'], 0, 8 ) . '…' . substr( (string) $state['site_id'], -4 ) ); ?></code></td></tr>
                    <tr><th><?php esc_html_e( 'Signing key', 'aicom' ); ?></th><td><code><?php echo esc_html( (string) ( $ident['key_id'] ?? '—' ) ); ?></code> <span class="description"><?php echo isset( $state['rotated_at'] ) ? esc_html( sprintf( /* translators: %s: relative time */ __( 'rotated %s', 'aicom' ), $ago( (int) $state['rotated_at'] ) ) ) : ''; ?></span></td></tr>
                    <tr><th><?php esc_html_e( 'AICOMBase key (pinned)', 'aicom' ); ?></th><td><code><?php echo esc_html( (string) ( $state['base_key_id'] ?? '' ) ); ?></code></td></tr>
                    <tr><th><?php esc_html_e( 'Connected since', 'aicom' ); ?></th><td><?php echo esc_html( $ago( (int) ( $state['connected_at'] ?? 0 ) ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Capabilities synced', 'aicom' ); ?></th><td><?php echo esc_html( $ago( (int) ( $state['caps_uploaded_at'] ?? 0 ) ) ); ?></td></tr>
                    <tr><th><?php esc_html_e( 'Events waiting to send', 'aicom' ); ?></th><td><?php echo esc_html( (string) AICOM_Base_Events::pending_count() ); ?></td></tr>
                    <?php if ( AICOM_Base_State::base_lock() !== 'none' ) : ?>
                        <tr><th><?php esc_html_e( 'Lock from AICOMBase', 'aicom' ); ?></th><td><strong><?php echo esc_html( AICOM_Base_State::base_lock() ); ?></strong></td></tr>
                    <?php endif; ?>
                    <?php if ( ! empty( $state['last_error'] ) ) : ?>
                        <tr><th><?php esc_html_e( 'Last problem', 'aicom' ); ?></th><td><code><?php echo esc_html( (string) $state['last_error']['code'] ); ?></code> <?php echo esc_html( $ago( (int) $state['last_error']['at'] ) ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
                <?php
                $forms = [
                    'sync_now' => [ __( 'Sync now', 'aicom' ), 'button' ],
                    AICOM_Base_State::is_paused() ? 'resume' : 'pause' => [ AICOM_Base_State::is_paused() ? __( 'Resume remote work', 'aicom' ) : __( 'Pause remote work', 'aicom' ), 'button' ],
                ];
                foreach ( $forms as $act => [ $text, $cls ] ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( AICOM_Base_Admin::NONCE ); ?>
                        <input type="hidden" name="action" value="aicom_base_action" />
                        <input type="hidden" name="base_action" value="<?php echo esc_attr( $act ); ?>" />
                        <button type="submit" class="<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $text ); ?></button>
                    </form>
                <?php endforeach; ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect this site from AICOMBase? AICOM keeps working; you can reconnect at any time. Revoke the site in AICOMBase to invalidate its keys there too.', 'aicom' ) ); ?>');">
                    <?php wp_nonce_field( AICOM_Base_Admin::NONCE ); ?>
                    <input type="hidden" name="action" value="aicom_base_action" />
                    <input type="hidden" name="base_action" value="disconnect" />
                    <button type="submit" class="button aicom-btn-danger"><?php esc_html_e( 'Disconnect', 'aicom' ); ?></button>
                </form>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Local policy + settings -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Local policy for AICOMBase work', 'aicom' ); ?></h2>
        </div>
        <div class="aicom-card-body">
            <p style="margin:0 0 12px;font-size:0.88em;color:var(--aicom-text-sm)">
                <?php esc_html_e( 'AICOMBase policy and this local policy must both allow an action. A task that asks for a permission not ticked here is refused and reported as a security event. Locks and pause always take precedence.', 'aicom' ); ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( AICOM_Base_Admin::NONCE ); ?>
                <input type="hidden" name="action" value="aicom_base_action" />
                <input type="hidden" name="base_action" value="save_settings" />

                <?php foreach ( AICOM_Auth::scope_tree() as $group => $scopes ) : ?>
                    <p style="margin:12px 0 4px;font-weight:600"><?php echo esc_html( $group ); ?></p>
                    <?php foreach ( $scopes as $slug => $def ) : ?>
                        <label style="display:block;margin:2px 0">
                            <input type="checkbox" name="allowed_scopes[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $allowed, true ) ); ?> />
                            <code><?php echo esc_html( $slug ); ?></code> — <?php echo esc_html( $def[0] ); ?>
                            <span class="aicom-badge <?php echo in_array( $def[1], [ 'high', 'critical' ], true ) ? 'aicom-badge-danger' : ''; ?>" style="margin-left:6px"><?php echo esc_html( $def[1] ); ?></span>
                        </label>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <?php if ( $status !== AICOM_Base_State::S_CONN ) : ?>
                    <p style="margin:18px 0 4px;font-weight:600"><?php esc_html_e( 'AICOMBase URL', 'aicom' ); ?></p>
                    <?php if ( AICOM_Base_State::base_url_locked_by_constant() ) : ?>
                        <code><?php echo esc_html( $base ); ?></code> <span class="description"><?php esc_html_e( 'Set by the AICOM_BASE_URL constant.', 'aicom' ); ?></span>
                    <?php else : ?>
                        <input type="url" name="base_url" class="regular-text" value="<?php echo esc_attr( (string) get_option( AICOM_Base_State::OPT_URL, '' ) ); ?>" placeholder="<?php echo esc_attr( AICOM_Base_State::DEFAULT_URL ); ?>" />
                        <p class="description"><?php esc_html_e( 'Leave empty for the default. HTTPS is required (plain http only for local development hosts).', 'aicom' ); ?></p>
                    <?php endif; ?>
                <?php endif; ?>

                <p style="margin-top:16px"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'aicom' ); ?></button></p>
            </form>
        </div>
    </div>

    <!-- Connection log -->
    <div class="aicom-card">
        <div class="aicom-card-head"><h2 class="aicom-card-title"><?php esc_html_e( 'Connection log', 'aicom' ); ?></h2></div>
        <div class="aicom-card-body">
            <?php if ( ! $log ) : ?>
                <p style="margin:0;color:var(--aicom-text-sm)"><?php esc_html_e( 'Nothing yet.', 'aicom' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e( 'When (UTC)', 'aicom' ); ?></th><th><?php esc_html_e( 'Event', 'aicom' ); ?></th><th><?php esc_html_e( 'Result', 'aicom' ); ?></th><th><?php esc_html_e( 'Detail', 'aicom' ); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ( $log as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row['created_at'] ); ?></td>
                            <td><code><?php echo esc_html( $row['tool_name'] ); ?></code></td>
                            <td><?php echo esc_html( $row['status'] ); ?></td>
                            <td><code style="font-size:0.8em"><?php echo esc_html( substr( (string) $row['result_summary_json'], 0, 140 ) ); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

<script>
( function () {
    var cfg = <?php echo wp_json_encode( [ 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => $nonce, 'status' => $status, 'i18n' => [
        'popup'  => __( 'Your browser blocked the new tab. Use the "Open AICOMBase to confirm" button.', 'aicom' ),
        'failed' => __( 'Could not start the connection.', 'aicom' ),
    ] ] ); ?>;

    function post( action, cb ) {
        var fd = new FormData();
        fd.append( 'action', action );
        fd.append( 'nonce', cfg.nonce );
        fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: fd } )
            .then( function ( r ) { return r.json(); } ).then( cb )
            .catch( function () { cb( { success: false, data: { message: cfg.i18n.failed } } ); } );
    }
    function showNotice( msg ) {
        var n = document.getElementById( 'aicom-base-notice' );
        if ( ! n ) { return; }
        n.querySelector( 'p' ).textContent = msg || '';
        n.style.display = msg ? '' : 'none';
    }

    var timer = null, started = Date.now();
    function poll() {
        post( 'aicom_base_status', function ( res ) {
            if ( ! res || ! res.success ) { return; }
            var d = res.data;
            if ( d.state === 'connected' || d.poll === 'connected' ) { window.location = window.location.pathname + '?page=aicom-base&base_msg=synced'; return; }
            if ( d.poll === 'expired' || d.poll === 'cancelled' || d.poll === 'error' || d.state === 'none' ) {
                clearInterval( timer );
                showNotice( d.message );
                if ( d.poll ) { setTimeout( function () { window.location.reload(); }, 1500 ); }
            }
        } );
        if ( Date.now() - started > 11 * 60 * 1000 ) { clearInterval( timer ); }
    }
    function startPolling() { started = Date.now(); clearInterval( timer ); timer = setInterval( poll, 3000 ); }

    var connect = document.getElementById( 'aicom-base-connect' );
    if ( connect ) {
        connect.addEventListener( 'click', function () {
            connect.disabled = true;
            showNotice( '' );
            // Open the tab synchronously (inside the click) so popup blockers allow it; point it at AICOMBase once we have the URL.
            var w = window.open( '', '_blank' );
            post( 'aicom_base_connect', function ( res ) {
                if ( ! res || ! res.success ) {
                    if ( w ) { w.close(); }
                    connect.disabled = false;
                    showNotice( ( res && res.data && res.data.message ) || cfg.i18n.failed );
                    return;
                }
                if ( w ) { w.location = res.data.connect_url; } else { showNotice( cfg.i18n.popup ); }
                window.setTimeout( function () { window.location.reload(); }, 400 );
            } );
        } );
    }
    var cancel = document.getElementById( 'aicom-base-cancel' );
    if ( cancel ) {
        cancel.addEventListener( 'click', function () { post( 'aicom_base_cancel', function () { window.location.reload(); } ); } );
    }
    if ( cfg.status === 'pairing' ) { startPolling(); poll(); }
} )();
</script>

<?php include AICOM_DIR . 'admin/partials/layout-bottom.php'; ?>
<?php } )(); ?>
