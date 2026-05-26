<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin bootstrap: menus, assets, POST handler routing.
 */
class AICOM_Admin {

    const MENU_SLUG    = 'aicom';
    const CAPABILITY   = 'manage_options';
    const NONCE_ACTION = 'aicom_admin';

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_aicom_save', [ $this, 'handle_post' ] );
        add_action( 'admin_notices',         [ $this, 'display_key_notice' ] );
        add_action( 'wp_dashboard_setup',    [ $this, 'register_dashboard_widget' ] );
        add_action( 'admin_init',            [ $this, 'maybe_redirect_to_onboarding' ] );
        add_filter( 'admin_body_class',      [ $this, 'maybe_fullscreen_body_class' ] );
        add_filter( 'plugin_action_links_aicom/aicom.php', [ $this, 'plugin_action_links' ] );
        add_action( 'aicom_expire_keys',     [ 'AICOM_Auth', 'expire_overdue_keys' ] );
    }

    // ── First-run onboarding ─────────────────────────────────────────────

    /**
     * Tag the body with a class while the onboarding wizard is rendering so
     * the CSS can hide the WP sidebar / admin bar and give the welcome
     * screen a focused, standalone feel.
     */
    public function maybe_fullscreen_body_class( string $classes ): string {
        $page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
        if ( $page === 'aicom-onboarding' ) {
            $classes .= ' aicom-fullscreen';
        }
        return $classes;
    }

    public function maybe_redirect_to_onboarding(): void {
        // Skip CLI/AJAX/cron and unauthenticated contexts.
        if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return;
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }
        // Once completed, never auto-redirect again.
        if ( get_option( 'aicom_onboarding_done', false ) ) {
            return;
        }
        $page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
        // Already on the wizard — don't loop.
        if ( $page === 'aicom-onboarding' ) {
            return;
        }
        // Only intercept when the user clicks into AICOM — don't hijack the
        // generic WP dashboard or unrelated pages.
        if ( strpos( $page, 'aicom' ) !== 0 ) {
            return;
        }
        wp_safe_redirect( admin_url( 'admin.php?page=aicom-onboarding' ) );
        exit;
    }

    // ── WP Dashboard Widget (index.php) ───────────────────────────────────

    public function register_dashboard_widget(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }
        wp_add_dashboard_widget(
            'aicom_dashboard_widget',
            __( 'AICOM — AI Commander', 'aicom' ),
            [ $this, 'render_dashboard_widget' ],
            null,
            null,
            'side',
            'high'
        );
    }

    public function render_dashboard_widget(): void {
        $sessions     = array_slice( AICOM_Sessions::get_all(), 0, 5 );
        $api_keys_url = admin_url( 'admin.php?page=aicom-api-keys' );
        $aicom_url    = admin_url( 'admin.php?page=aicom' );
        $help_url     = admin_url( 'admin.php?page=aicom-help' );
        $audit_url    = admin_url( 'admin.php?page=aicom-audit-logs' );
        $logo_url     = AICOM_URL . 'assets/branding/aicom-logo-primary.svg';
        ?>
        <div class="aicom-dash-widget">
            <div class="aicom-dash-widget-head">
                <a href="<?php echo esc_url( $aicom_url ); ?>" class="aicom-dash-widget-logo-link">
                    <img src="<?php echo esc_url( $logo_url ); ?>" alt="aicom" class="aicom-dash-widget-logo">
                </a>
                <p class="aicom-dash-widget-tagline"><?php esc_html_e( 'AI Commander for WordPress', 'aicom' ); ?></p>
            </div>

            <div class="aicom-dash-widget-cta">
                <a href="<?php echo esc_url( $api_keys_url ); ?>" class="button button-primary button-hero aicom-dash-widget-cta-btn"><?php esc_html_e( 'Connect AI Agent', 'aicom' ); ?></a>
                <a href="<?php echo esc_url( $aicom_url ); ?>" class="aicom-dash-widget-cta-aside"><?php esc_html_e( 'or pick a quick preset →', 'aicom' ); ?></a>
            </div>

            <div class="aicom-dash-widget-section">
                <div class="aicom-dash-widget-section-head">
                    <h3><?php esc_html_e( "Today's activity", 'aicom' ); ?></h3>
                    <a href="<?php echo esc_url( $audit_url ); ?>" class="aicom-dash-widget-link"><?php esc_html_e( 'View all →', 'aicom' ); ?></a>
                </div>

                <?php if ( empty( $sessions ) ) : ?>
                    <p class="aicom-dash-widget-empty"><?php esc_html_e( 'No sessions yet. Generate a key and try a sentence — your activity will land here.', 'aicom' ); ?></p>
                <?php else : ?>
                    <ul class="aicom-dash-widget-sessions">
                        <?php foreach ( $sessions as $s ) :
                            $name      = $s['name'] ?? __( 'Untitled session', 'aicom' );
                            $req_count = (int) ( $s['request_count'] ?? 0 );
                            $opened_ts = strtotime( $s['opened_at'] ?? '' );
                            $when      = $opened_ts
                                ? sprintf( /* translators: %s = relative time */ __( '%s ago', 'aicom' ), human_time_diff( $opened_ts, current_time( 'timestamp', true ) ) )
                                : '';
                            $is_active = ( $s['status'] ?? '' ) === 'active';
                        ?>
                            <li class="aicom-dash-widget-session<?php echo $is_active ? ' is-active' : ''; ?>">
                                <span class="aicom-dash-widget-session-dot" aria-hidden="true"></span>
                                <span class="aicom-dash-widget-session-name"><?php echo esc_html( $name ); ?></span>
                                <span class="aicom-dash-widget-session-meta">
                                    <?php
                                    /* translators: %d: number of requests */
                                    printf( esc_html( _n( '%d request', '%d requests', $req_count, 'aicom' ) ), $req_count );
                                    ?>
                                    <?php if ( $when ) : ?>
                                        · <?php echo esc_html( $when ); ?>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="aicom-dash-widget-footer">
                <a href="<?php echo esc_url( $help_url ); ?>" class="aicom-dash-widget-help">
                    <span class="aicom-dash-widget-help-icon" aria-hidden="true">?</span>
                    <?php esc_html_e( 'New here? Read the four-minute guide.', 'aicom' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    public function plugin_action_links( array $links ): array {
        $setup_link = '<a href="' . esc_url( admin_url( 'admin.php?page=aicom-api-keys' ) ) . '"><strong>' . esc_html__( 'Connect AI Agent', 'aicom' ) . '</strong></a>';
        array_unshift( $links, $setup_link );
        return $links;
    }

    // ── Menu Registration ─────────────────────────────────────────────────

    public function register_menus(): void {
        $icon_svg  = @file_get_contents( AICOM_DIR . 'assets/branding/aicom-icon-mono.svg' );
        $menu_icon = $icon_svg
            ? 'data:image/svg+xml;base64,' . base64_encode( $icon_svg )
            : 'dashicons-rest-api';

        add_menu_page(
            __( 'aicom', 'aicom' ),
            __( 'aicom', 'aicom' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            [ $this, 'page_dashboard' ],
            $menu_icon,
            80
        );

        add_submenu_page( self::MENU_SLUG, __( 'Dashboard', 'aicom' ),                __( 'Dashboard', 'aicom' ),                self::CAPABILITY, self::MENU_SLUG,        [ $this, 'page_dashboard' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Connect AI Agents', 'aicom' ),       __( 'Connect AI Agents', 'aicom' ),        self::CAPABILITY, 'aicom-api-keys',       [ $this, 'page_api_keys' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Activity', 'aicom' ),                __( 'Activity', 'aicom' ),                 self::CAPABILITY, 'aicom-audit-logs',     [ $this, 'page_audit_logs' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Safety', 'aicom' ),                  __( 'Safety', 'aicom' ),                   self::CAPABILITY, 'aicom-safety',         [ $this, 'page_safety' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Capabilities', 'aicom' ),  __( 'Capabilities', 'aicom' ),   self::CAPABILITY, 'aicom-modules',        [ $this, 'page_modules' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Snapshots', 'aicom' ),               __( 'Snapshots', 'aicom' ),                self::CAPABILITY, 'aicom-backups',        [ $this, 'page_backups' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Saved Workflows', 'aicom' ),         __( 'Saved Workflows', 'aicom' ),          self::CAPABILITY, 'aicom-skills',         [ $this, 'page_skills' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Help', 'aicom' ),                    __( 'Help', 'aicom' ),                     self::CAPABILITY, 'aicom-help',           [ $this, 'page_help' ] );
        // Hidden onboarding wizard — reachable only by direct URL or by maybe_redirect_to_onboarding().
        add_submenu_page( null, __( 'Welcome to AICOM', 'aicom' ),                   '',                                        self::CAPABILITY, 'aicom-onboarding',     [ $this, 'page_onboarding' ] );
    }

    // ── Asset Enqueuing ───────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        $plugin_url = AICOM_URL;
        $version    = AICOM_VERSION;
        $on_aicom   = strpos( $hook, 'aicom' ) !== false;

        // CSS enqueued on every admin page (admin bar styles needed everywhere)
        wp_enqueue_style(
            'aicom-admin',
            $plugin_url . 'assets/admin.css',
            [],
            $version
        );

        // JS only needed on AICOM pages OR when admin bar is showing (toolbar toggle)
        wp_enqueue_script(
            'aicom-admin',
            $plugin_url . 'assets/admin.js',
            [ 'jquery' ],
            $version,
            true
        );

        wp_localize_script( 'aicom-admin', 'AICOM_MCP', [
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        ] );
    }

    // ── Page Renderers ────────────────────────────────────────────────────

    public function page_dashboard(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/dashboard.php';
    }

    public function page_api_keys(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/api-keys.php';
    }

    public function page_audit_logs(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/audit-logs.php';
    }

    public function page_safety(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/safety.php';
    }

    public function page_modules(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/modules.php';
    }

    public function page_backups(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/backups.php';
    }

    public function page_skills(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/skills.php';
    }

    public function page_help(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/help.php';
    }

    public function page_onboarding(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/onboarding.php';
    }

    // ── Admin notices (key created / rotated) ─────────────────────────────

    public function display_key_notice(): void {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id ?? '', 'aicom' ) === false ) {
            return;
        }

        $created    = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0;
        $rotated    = isset( $_GET['rotated'] ) ? absint( $_GET['rotated'] ) : 0;
        $new_key_id = $created ?: $rotated;

        if ( ! $new_key_id ) {
            return;
        }

        $plain_key = get_transient( 'aicom_new_key_' . $new_key_id );
        if ( ! $plain_key ) {
            return;
        }
        delete_transient( 'aicom_new_key_' . $new_key_id );

        $mcp_url      = get_site_url() . '/wp-json/aicom/v1/mcp';
        $fallback_url = get_site_url() . '/?aicom=1';
        $schema_url   = get_site_url() . '/wp-json/aicom/v1/schema';

        // Per-client paste-ready snippets + plain-English instructions.
        // The user picks their client and we swap both the instruction line
        // and the terminal snippet in place. No need to wade through the
        // generic "Connect to my WordPress site via MCP…" wall of text.
        $client_recipes = [
            'claude-desktop' => [
                'name'    => __( 'Claude Desktop', 'aicom' ),
                'intro'   => __( 'Open your config file (on macOS: ~/Library/Application Support/Claude/claude_desktop_config.json). Paste this into it, save, then restart Claude Desktop.', 'aicom' ),
                'snippet' => "{\n  \"mcpServers\": {\n    \"aicom\": {\n      \"url\": \"" . $mcp_url . "\",\n      \"headers\": {\n        \"Authorization\": \"Bearer " . $plain_key . "\"\n      }\n    }\n  }\n}",
            ],
            'claude-code' => [
                'name'    => __( 'Claude Code', 'aicom' ),
                'intro'   => __( 'Open a terminal in any project folder and paste this single command. Claude Code remembers it across sessions.', 'aicom' ),
                'snippet' => "claude mcp add aicom " . $mcp_url . " \\\n  --transport http \\\n  --header \"Authorization: Bearer " . $plain_key . "\"",
            ],
            'cursor' => [
                'name'    => __( 'Cursor IDE', 'aicom' ),
                'intro'   => __( 'Create .cursor/mcp.json in your project (or ~/.cursor/mcp.json to share across projects). Paste this, then reload Cursor.', 'aicom' ),
                'snippet' => "{\n  \"mcpServers\": {\n    \"aicom\": {\n      \"url\": \"" . $mcp_url . "\",\n      \"type\": \"http\",\n      \"headers\": {\n        \"Authorization\": \"Bearer " . $plain_key . "\"\n      }\n    }\n  }\n}",
            ],
            'chatgpt' => [
                'name'    => __( 'ChatGPT (Custom GPT)', 'aicom' ),
                'intro'   => __( 'Open the Custom GPT builder, add an Action, paste this URL. Set Authentication to API Key → Bearer, then paste the key above.', 'aicom' ),
                'snippet' => $schema_url,
            ],
            'generic' => [
                'name'    => __( 'Something else', 'aicom' ),
                'intro'   => __( 'Any MCP-aware client. Endpoint + Bearer header, sample request body below.', 'aicom' ),
                'snippet' => "POST " . $mcp_url . "\nContent-Type: application/json\nAuthorization: Bearer " . $plain_key . "\n\n{\n  \"jsonrpc\": \"2.0\",\n  \"method\": \"wp.posts.list\",\n  \"params\": { \"per_page\": 5 },\n  \"id\": 1\n}",
            ],
        ];
        $client_recipes_json = wp_json_encode( $client_recipes );

        $label = $created
            ? __( 'Key created', 'aicom' )
            : __( 'Key rotated', 'aicom' );
        ?>
        <div id="aicom-key-modal-overlay" class="aicom-confirm-overlay aicom-keymodal-overlay">
            <div id="aicom-key-modal" class="aicom-confirm-modal aicom-keymodal" role="dialog" aria-modal="true">
                <button id="aicom-key-modal-close" class="aicom-confirm-close" type="button" aria-label="<?php esc_attr_e( 'Close', 'aicom' ); ?>">&times;</button>

                <div class="aicom-keymodal-status">
                    <span class="aicom-keymodal-status-badge"><?php echo esc_html( $label ); ?></span>
                    <span class="aicom-keymodal-status-note"><?php esc_html_e( "Copy it now — won't be shown again", 'aicom' ); ?></span>
                </div>

                <h3><?php esc_html_e( 'Your new API key', 'aicom' ); ?></h3>

                <div class="aicom-keymodal-field">
                    <span class="aicom-confirm-scopes-label"><?php esc_html_e( 'API Key', 'aicom' ); ?></span>
                    <div class="aicom-keymodal-row">
                        <input type="text" readonly value="<?php echo esc_attr( $plain_key ); ?>"
                               id="aicom-plain-key"
                               class="aicom-keymodal-input"
                               onclick="this.select()" />
                        <button type="button" class="button aicom-copy-btn" data-target="<?php echo esc_attr( $plain_key ); ?>"><?php esc_html_e( 'Copy Key', 'aicom' ); ?></button>
                    </div>
                    <div class="aicom-keymodal-test">
                        <button type="button"
                                class="button aicom-keymodal-test-btn"
                                id="aicom-test-connection"
                                data-key="<?php echo esc_attr( $plain_key ); ?>"
                                data-mcp="<?php echo esc_attr( $mcp_url ); ?>"
                                data-fallback="<?php echo esc_attr( $fallback_url ); ?>"
                                data-label-success="<?php esc_attr_e( 'Your key works.', 'aicom' ); ?>"
                                data-label-testing="<?php esc_attr_e( 'Testing…', 'aicom' ); ?>"
                                data-label-default="<?php esc_attr_e( 'Test connection', 'aicom' ); ?>">
                            <?php esc_html_e( 'Test connection', 'aicom' ); ?>
                        </button>
                        <span class="aicom-keymodal-test-result" id="aicom-test-result" aria-live="polite"></span>
                    </div>
                </div>

                <div class="aicom-keymodal-field">
                    <span class="aicom-confirm-scopes-label"><?php esc_html_e( 'Now connect your AI client', 'aicom' ); ?></span>
                    <p class="aicom-keymodal-desc"><?php esc_html_e( "Pick what you use. We'll show you exactly what to paste — no typing required.", 'aicom' ); ?></p>

                    <div class="aicom-keymodal-clients" role="tablist">
                        <?php $first = true; foreach ( $client_recipes as $key => $r ) : ?>
                            <button type="button"
                                    class="aicom-keymodal-client<?php echo $first ? ' is-active' : ''; ?>"
                                    data-client="<?php echo esc_attr( $key ); ?>"
                                    role="tab"
                                    aria-selected="<?php echo $first ? 'true' : 'false'; ?>">
                                <?php echo esc_html( $r['name'] ); ?>
                            </button>
                        <?php $first = false; endforeach; ?>
                    </div>

                    <p class="aicom-keymodal-client-intro" id="aicom-keymodal-intro"></p>

                    <div class="aicom-keymodal-terminal-wrap">
                        <button type="button"
                                class="aicom-keymodal-terminal-icon"
                                id="aicom-agent-prompt-copy-icon"
                                data-target=""
                                aria-label="<?php esc_attr_e( 'Copy snippet', 'aicom' ); ?>"
                                title="<?php esc_attr_e( 'Copy snippet', 'aicom' ); ?>">
                            <svg class="aicom-icon-copy" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="9" y="9" width="13" height="13" rx="2"></rect>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                            </svg>
                            <svg class="aicom-icon-check" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                        </button>
                        <div class="aicom-keymodal-terminal" id="aicom-agent-prompt"></div>
                    </div>
                    <div class="aicom-keymodal-terminal-actions">
                        <button type="button" class="button" id="aicom-prompt-copy-btn" data-target=""><?php esc_html_e( 'Copy snippet', 'aicom' ); ?></button>
                    </div>
                </div>

                <div class="aicom-confirm-actions">
                    <button id="aicom-key-modal-done" type="button" class="button button-primary"><?php esc_html_e( 'Done', 'aicom' ); ?></button>
                </div>
            </div>
        </div>
        <script>
        (function () {
            var overlay = document.getElementById('aicom-key-modal-overlay');
            if (!overlay) return;
            function closeModal() { overlay.hidden = true; document.removeEventListener('keydown', onEsc); }
            function onEsc(e) { if (e.key === 'Escape') closeModal(); }
            document.getElementById('aicom-key-modal-close').addEventListener('click', closeModal);
            document.getElementById('aicom-key-modal-done').addEventListener('click', closeModal);
            overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
            document.addEventListener('keydown', onEsc);

            // ── Client picker ──────────────────────────────────────────
            var recipes = <?php echo $client_recipes_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already JSON-encoded for JS ?>;
            var clientButtons = document.querySelectorAll('.aicom-keymodal-client');
            var introEl = document.getElementById('aicom-keymodal-intro');
            var snippetEl = document.getElementById('aicom-agent-prompt');
            var iconBtn = document.getElementById('aicom-agent-prompt-copy-icon');
            var copyBtn = document.getElementById('aicom-prompt-copy-btn');

            function render(clientKey) {
                var r = recipes[clientKey];
                if (!r) return;
                clientButtons.forEach(function (b) {
                    var on = b.dataset.client === clientKey;
                    b.classList.toggle('is-active', on);
                    b.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                introEl.textContent = r.intro;
                snippetEl.textContent = r.snippet;
                if (iconBtn) iconBtn.dataset.target = r.snippet;
                if (copyBtn) copyBtn.dataset.target = r.snippet;
                try { localStorage.setItem('aicom_pref_client', clientKey); } catch (e) {}
            }

            clientButtons.forEach(function (b) {
                b.addEventListener('click', function () { render(b.dataset.client); });
            });

            // Restore preferred client, or default to first
            var pref = null;
            try { pref = localStorage.getItem('aicom_pref_client'); } catch (e) {}
            if (pref && recipes[pref]) {
                render(pref);
            } else {
                var firstBtn = document.querySelector('.aicom-keymodal-client.is-active');
                if (firstBtn) render(firstBtn.dataset.client);
            }

            // ── Test connection — pings server.status with the new key ───
            var testBtn = document.getElementById('aicom-test-connection');
            var testResult = document.getElementById('aicom-test-result');
            if (testBtn) {
                testBtn.addEventListener('click', async function () {
                    var key = testBtn.dataset.key;
                    var mcp = testBtn.dataset.mcp;
                    var fallback = testBtn.dataset.fallback;
                    var lblTesting = testBtn.dataset.labelTesting || 'Testing…';
                    var lblDefault = testBtn.dataset.labelDefault || 'Test connection';
                    var lblOk      = testBtn.dataset.labelSuccess || 'Your key works.';

                    function setResult(state, text) {
                        testResult.className = 'aicom-keymodal-test-result is-' + state;
                        testResult.textContent = text;
                    }
                    function callEndpoint(url) {
                        return fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Authorization': 'Bearer ' + key
                            },
                            body: JSON.stringify({ jsonrpc: '2.0', method: 'server.status', id: 1 })
                        }).then(function (r) {
                            return r.json().then(function (b) { return { status: r.status, body: b }; });
                        });
                    }

                    testBtn.disabled = true;
                    var origText = testBtn.textContent;
                    testBtn.textContent = lblTesting;
                    setResult('testing', '');

                    try {
                        var res = await callEndpoint(mcp);
                        if (res.body && res.body.result) {
                            setResult('ok', '✓ ' + lblOk);
                        } else if (res.body && res.body.error && res.body.error.code === 'AUTH_FAILED') {
                            // Likely Apache stripped the Authorization header — try the fallback.
                            var res2 = await callEndpoint(fallback);
                            if (res2.body && res2.body.result) {
                                setResult('warn', '⚠ Use the fallback URL — your host strips the Authorization header on the primary endpoint.');
                            } else {
                                setResult('err', '✗ Authentication failed. Check that the snippet uses the exact key shown above.');
                            }
                        } else {
                            setResult('err', '✗ ' + (res.body && res.body.error && res.body.error.message ? res.body.error.message : 'Unknown error from server.'));
                        }
                    } catch (e) {
                        setResult('err', '✗ Network couldn’t reach the endpoint. Check your site URL is reachable from this browser.');
                    } finally {
                        testBtn.disabled = false;
                        testBtn.textContent = origText;
                    }
                });
            }

            // ── Copy buttons (work with the swapped data-target) ────────
            function copyText(btn, text, isIcon) {
                var ok = function () {
                    btn.classList.add('is-copied');
                    setTimeout(function () { btn.classList.remove('is-copied'); }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(ok).catch(function () {
                        var ta = document.createElement('textarea');
                        ta.value = text;
                        document.body.appendChild(ta);
                        ta.select();
                        try { document.execCommand('copy'); } catch (e) {}
                        document.body.removeChild(ta);
                        ok();
                    });
                }
            }
            if (iconBtn) {
                iconBtn.addEventListener('click', function () { copyText(iconBtn, iconBtn.dataset.target || '', true); });
            }
            if (copyBtn) {
                copyBtn.addEventListener('click', function () {
                    var origLabel = copyBtn.textContent;
                    copyText(copyBtn, copyBtn.dataset.target || '', false);
                    copyBtn.textContent = '✓ Copied';
                    setTimeout(function () { copyBtn.textContent = origLabel; }, 1500);
                });
            }
        })();
        </script>
        <?php
    }

    // ── POST Handler ──────────────────────────────────────────────────────

    public function handle_post(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'aicom' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION );

        // All $_POST reads happen here, after nonce verification, then passed as typed params.
        $action = sanitize_key( wp_unslash( $_POST['aicom_action'] ?? '' ) );

        switch ( $action ) {
            case 'set_lock':
                $this->handle_set_lock(
                    ! empty( $_POST['soft_lock'] ),
                    ! empty( $_POST['hard_lock'] )
                );
                break;

            case 'generate_pairing_token':
                // One-time bootstrap token for AICOM Hub pairing (PRD §16.2).
                // Plaintext is stashed in a short-lived transient so the next
                // pageview can reveal it exactly once.
                $token = AICOM_Hub_Pairing::create_token();
                set_transient( 'aicom_new_pairing_token', $token, 600 );
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-safety&pairing_generated=1' ) );
                exit;

            case 'create_key':
                $raw_scopes = filter_input( INPUT_POST, 'scopes', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ) ?? [];
                $scopes = array_map( 'sanitize_text_field', wp_unslash( $raw_scopes ) );
                $this->handle_create_key(
                    sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
                    $scopes,
                    ! empty( $_POST['dry_run_only'] ),
                    sanitize_textarea_field( wp_unslash( $_POST['ip_allowlist'] ?? '' ) ),
                    sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) ),
                    ! empty( $_POST['ip_lock'] ),
                    self::read_working_hours_from_post()
                );
                break;

            case 'quick_create_key':
                $preset_slug = sanitize_key( wp_unslash( $_POST['preset'] ?? '' ) );
                $this->handle_quick_create_key( $preset_slug );
                break;

            case 'create_from_task':
                $task_slug = sanitize_key( wp_unslash( $_POST['task'] ?? '' ) );
                $this->handle_create_from_task( $task_slug );
                break;

            case 'complete_onboarding':
                $preset_slug = sanitize_key( wp_unslash( $_POST['preset'] ?? '' ) );
                $this->handle_complete_onboarding( $preset_slug );
                break;

            case 'skip_onboarding':
                update_option( 'aicom_onboarding_done', true );
                wp_safe_redirect( admin_url( 'admin.php?page=aicom' ) );
                exit;

            case 'rotate_key':
                $this->handle_rotate_key( absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) );
                break;

            case 'revoke_key':
                $this->handle_revoke_key( absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) );
                break;

            case 'suspend_key':
                $this->handle_suspend_key( absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) );
                break;

            case 'unsuspend_key':
                $this->handle_unsuspend_key( absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) );
                break;

            case 'edit_key':
                $raw_scopes  = filter_input( INPUT_POST, 'scopes', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ) ?? [];
                $scopes      = array_map( 'sanitize_text_field', wp_unslash( $raw_scopes ) );
                $this->handle_edit_key(
                    absint( wp_unslash( $_POST['key_id'] ?? 0 ) ),
                    $scopes,
                    ! empty( $_POST['dry_run_only'] ),
                    sanitize_textarea_field( wp_unslash( $_POST['ip_allowlist'] ?? '' ) ),
                    sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) ),
                    ! empty( $_POST['rotate_secret'] ),
                    ! empty( $_POST['ip_lock'] ),
                    self::read_working_hours_from_post()
                );
                break;

            case 'reset_ip_lock':
                $key_id = absint( wp_unslash( $_POST['key_id'] ?? 0 ) );
                AICOM_Auth::reset_ip_lock( $key_id );
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&ip_lock_reset=' . $key_id ) );
                exit;

            case 'archive_key':
                AICOM_Auth::archive_key( absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) );
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&archived=' . absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) ) );
                exit;

            case 'unarchive_key':
                AICOM_Auth::unarchive_key( absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) );
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&unarchived=' . absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) ) );
                exit;

            case 'delete_backup':
                $this->handle_delete_backup( absint( wp_unslash( $_POST['backup_id'] ?? 0 ) ) );
                break;

            case 'restore_session':
                $this->handle_restore_session( absint( wp_unslash( $_POST['session_id'] ?? 0 ) ) );
                break;

            case 'save_backup_settings':
                update_option( 'aicom_backup_max_age_days', absint( wp_unslash( $_POST['max_age_days'] ?? 0 ) ) );
                update_option( 'aicom_backup_max_size_mb',  absint( wp_unslash( $_POST['max_size_mb']  ?? 0 ) ) );
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-backups&settings_saved=1' ) );
                exit;

            case 'save_schedule':
                $this->handle_save_schedule();
                break;

            case 'skills_activate':
                $skill_id = absint( wp_unslash( $_POST['skill_id'] ?? 0 ) );
                AICOM_Skills::set_status( $skill_id, 'active' );
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-skills&tab=skills&updated=activated' ) );
                exit;

            case 'skills_save_draft':
                $skill_id = absint( wp_unslash( $_POST['skill_id'] ?? 0 ) );
                AICOM_Skills::set_status( $skill_id, 'draft' );
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-skills&tab=skills&updated=draft' ) );
                exit;

            case 'skills_archive':
                $skill_id = absint( wp_unslash( $_POST['skill_id'] ?? 0 ) );
                AICOM_Skills::set_status( $skill_id, 'archived' );
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-skills&tab=skills&updated=archived' ) );
                exit;

            case 'skills_delete':
                $skill_id = absint( wp_unslash( $_POST['skill_id'] ?? 0 ) );
                if ( ! empty( $_POST['confirm'] ) ) {
                    AICOM_Skills::delete( $skill_id );
                }
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-skills&tab=suggested&updated=rejected' ) );
                exit;

            case 'skills_accept_proposal':
                $proposal_id = absint( wp_unslash( $_POST['proposal_id'] ?? 0 ) );
                $original_id = absint( wp_unslash( $_POST['original_id'] ?? 0 ) );
                $proposal    = AICOM_Skills::get( $proposal_id );
                if ( $proposal && $original_id ) {
                    $patch = array_intersect_key( $proposal, array_flip( [ 'name', 'description', 'steps', 'rules', 'tags', 'input_schema', 'permissions', 'type' ] ) );
                    AICOM_Skills::update( $original_id, $patch );
                    AICOM_Skills::delete( $proposal_id );
                }
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-skills&tab=proposals&updated=accepted' ) );
                exit;

            case 'skills_toggle_suggestions':
                $enabled = ! empty( $_POST['suggestions_enabled'] );
                update_option( 'aicom_skill_suggestions', $enabled ? '1' : '0' );
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-skills&settings_saved=1' ) );
                exit;

            case 'skills_export':
                $skill_id = absint( wp_unslash( $_POST['skill_id'] ?? 0 ) );
                $skill    = AICOM_Skills::get( $skill_id );
                if ( ! $skill ) {
                    wp_safe_redirect( admin_url( 'admin.php?page=aicom-skills&error=not_found' ) );
                    exit;
                }
                $export = array_filter( [
                    'name'         => $skill['name'],
                    'slug'         => $skill['slug'],
                    'description'  => $skill['description'] ?: null,
                    'type'         => $skill['type'],
                    'input_schema' => $skill['input_schema'] ?: null,
                    'rules'        => $skill['rules'] ?: null,
                    'steps'        => $skill['steps'] ?: null,
                    'permissions'  => $skill['permissions'] ?: null,
                    'tags'         => $skill['tags'] ?: null,
                ], fn( $v ) => $v !== null );
                nocache_headers();
                header( 'Content-Type: application/json; charset=utf-8' );
                header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $skill['slug'] ) . '.json"' );
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                exit;

            case 'skills_import_json':
                $json_raw = sanitize_textarea_field( wp_unslash( $_POST['skill_json'] ?? '' ) );
                $data     = json_decode( $json_raw, true );
                if ( ! is_array( $data ) || empty( $data['name'] ) ) {
                    wp_safe_redirect( admin_url( 'admin.php?page=aicom-skills&tab=skills&import=1&import_error=invalid_json' ) );
                    exit;
                }
                if ( empty( $data['slug'] ) ) {
                    $data['slug'] = sanitize_title( $data['name'] );
                }
                $data['status'] = 'draft';
                $result = AICOM_Skills::create( $data );
                if ( is_wp_error( $result ) ) {
                    wp_safe_redirect( admin_url( 'admin.php?page=aicom-skills&tab=skills&import=1&import_error=' . urlencode( $result->get_error_code() ) ) );
                    exit;
                }
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-skills&tab=skills&imported=' . (int) $result['id'] ) );
                exit;

            default:
                wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&error=unknown_action' ) );
                exit;
        }
    }

    // ── Action Handlers ───────────────────────────────────────────────────

    private function handle_set_lock( bool $soft_lock, bool $hard_lock ): void {
        AICOM_Lock_Manager::set_soft_lock( $soft_lock );
        AICOM_Lock_Manager::set_hard_lock( $hard_lock );

        AICOM_Audit_Logger::log( [
            'remote_ip'     => AICOM_Tool_Router::remote_ip(),
            'tool_name'     => 'admin.set_lock',
            'module'        => 'admin',
            'status'        => 'success',
            'http_status'   => 200,
            'api_key_label' => wp_get_current_user()->user_login,
            'result_summary_json' => wp_json_encode( [
                'soft_lock' => $soft_lock,
                'hard_lock' => $hard_lock,
            ] ),
        ] );

        wp_safe_redirect( admin_url( 'admin.php?page=aicom-safety&updated=lock' ) );
        exit;
    }

    private function handle_save_schedule(): void {
        $enabled   = ! empty( $_POST['schedule_enabled'] );
        $raw_days  = filter_input( INPUT_POST, 'schedule_days', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ) ?? [];
        $days      = array_values( array_map( 'intval', (array) $raw_days ) );
        $start     = sanitize_text_field( wp_unslash( $_POST['schedule_start'] ?? '09:00' ) );
        $end       = sanitize_text_field( wp_unslash( $_POST['schedule_end']   ?? '18:00' ) );
        $lock_type = in_array( wp_unslash( $_POST['schedule_lock_type'] ?? '' ), [ 'soft_locked', 'hard_locked' ], true )
            ? sanitize_key( wp_unslash( $_POST['schedule_lock_type'] ) )
            : 'soft_locked';

        if ( ! preg_match( '/^\d{2}:\d{2}$/', $start ) ) { $start = '09:00'; }
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $end ) )   { $end   = '18:00'; }

        AICOM_Lock_Manager::set_schedule( [
            'enabled'   => $enabled,
            'days'      => $days,
            'start'     => $start,
            'end'       => $end,
            'lock_type' => $lock_type,
        ] );

        wp_safe_redirect( admin_url( 'admin.php?page=aicom-safety&schedule_saved=1' ) );
        exit;
    }

    private function parse_lines( string $raw ): array {
        return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $raw ) ) ) );
    }

    private function apply_resource_restrictions( array &$restrictions ): void {
        $fields = [
            'res_post_types' => 'post_types',
            'res_taxonomies' => 'taxonomies',
            'res_meta_keys'  => 'meta_keys',
            'res_options'    => 'options',
            'res_file_paths' => 'file_paths',
        ];
        foreach ( $fields as $post_key => $restriction_key ) {
            $raw = sanitize_textarea_field( wp_unslash( $_POST[ $post_key ] ?? '' ) );
            if ( $raw !== '' ) {
                $restrictions[ $restriction_key ] = $this->parse_lines( $raw );
            }
        }
        if ( AICOM_Module_Detector::is_polylang_active() ) {
            $raw = sanitize_textarea_field( wp_unslash( $_POST['res_languages'] ?? '' ) );
            if ( $raw !== '' ) {
                $restrictions['languages'] = $this->parse_lines( $raw );
            }
        }
    }

    private function handle_create_key( string $label, array $scopes, bool $dry_run_only, string $ip_allowlist_raw, string $expires_raw = '', bool $ip_lock = false, array $working_hours = [] ): void {
        if ( ! $label ) {
            wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&error=missing_label' ) );
            exit;
        }

        $restrictions = [];
        if ( $dry_run_only ) {
            $restrictions['dry_run_only'] = true;
        }
        if ( $ip_allowlist_raw ) {
            $restrictions['ip_allowlist'] = array_filter( array_map( 'trim', explode( "\n", $ip_allowlist_raw ) ) );
        }
        if ( $ip_lock ) {
            $restrictions['ip_lock'] = true;
        }
        if ( ! empty( $working_hours['enabled'] ) ) {
            $restrictions['working_hours'] = $working_hours;
        }
        $this->apply_resource_restrictions( $restrictions );

        $expires_ts  = $expires_raw ? strtotime( $expires_raw ) : false;
        $expires_at  = ( $expires_ts !== false ) ? gmdate( 'Y-m-d 23:59:59', $expires_ts ) : null;
        if ( $expires_raw && $expires_ts === false ) {
            wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&error=invalid_date' ) );
            exit;
        }

        $result = AICOM_Auth::create_key( $label, $scopes, $restrictions, $expires_at );

        // Store the plain key in a short-lived transient for one-time display
        set_transient( 'aicom_new_key_' . $result['id'], $result['plain_key'], 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&created=' . $result['id'] ) );
        exit;
    }

    private function handle_complete_onboarding( string $preset_slug ): void {
        $presets = self::system_presets();
        if ( ! isset( $presets[ $preset_slug ] ) ) {
            // Bad preset — bounce back to step 2 with an error.
            wp_safe_redirect( admin_url( 'admin.php?page=aicom-onboarding&step=2&error=invalid_preset' ) );
            exit;
        }
        $preset = $presets[ $preset_slug ];
        $label  = self::next_available_label( 'First Key — ' . $preset['name'] );

        $result = AICOM_Auth::create_key( $label, $preset['scopes'], [], null );
        set_transient( 'aicom_new_key_' . $result['id'], $result['plain_key'], 120 );

        // Mark onboarding complete so the welcome wizard never reappears.
        update_option( 'aicom_onboarding_done', true );

        // Land on the AICOM dashboard with the key-created modal open.
        wp_safe_redirect( admin_url( 'admin.php?page=aicom&created=' . $result['id'] . '&onboarded=1' ) );
        exit;
    }

    private function handle_create_from_task( string $task_slug ): void {
        $tasks = self::task_presets();
        if ( ! isset( $tasks[ $task_slug ] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=aicom&error=invalid_task' ) );
            exit;
        }
        $task = $tasks[ $task_slug ];
        $label = self::next_available_label( $task['name'] );

        $restrictions = [];
        if ( ! empty( $task['dry_run'] ) ) {
            $restrictions['dry_run_only'] = true;
        }

        $result = AICOM_Auth::create_key( $label, $task['scopes'], $restrictions, null );
        set_transient( 'aicom_new_key_' . $result['id'], $result['plain_key'], 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=aicom&created=' . $result['id'] ) );
        exit;
    }

    private function handle_quick_create_key( string $preset_slug ): void {
        $presets = self::system_presets();
        if ( ! isset( $presets[ $preset_slug ] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=aicom&error=invalid_preset' ) );
            exit;
        }
        $preset = $presets[ $preset_slug ];
        $label  = self::next_available_label( 'Quick ' . $preset['name'] );

        $result = AICOM_Auth::create_key( $label, $preset['scopes'], [], null );
        set_transient( 'aicom_new_key_' . $result['id'], $result['plain_key'], 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=aicom&created=' . $result['id'] ) );
        exit;
    }

    private function handle_rotate_key( int $key_id ): void {
        $new_key = AICOM_Auth::rotate_key( $key_id );
        if ( $new_key ) {
            set_transient( 'aicom_new_key_' . $key_id, $new_key, 60 );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&rotated=' . $key_id ) );
        exit;
    }

    private function handle_revoke_key( int $key_id ): void {
        AICOM_Auth::revoke_key( $key_id );
        wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&revoked=' . $key_id ) );
        exit;
    }

    private function handle_suspend_key( int $key_id ): void {
        AICOM_Auth::suspend_key( $key_id );
        wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&suspended=' . $key_id ) );
        exit;
    }

    private function handle_unsuspend_key( int $key_id ): void {
        AICOM_Auth::unsuspend_key( $key_id );
        wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&unsuspended=' . $key_id ) );
        exit;
    }

    private function handle_edit_key( int $id, array $scopes, bool $dry_run_only, string $ip_raw, string $expires_raw, bool $rotate, bool $ip_lock = false, array $working_hours = [] ): void {
        if ( ! $id ) {
            wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&error=invalid_key' ) );
            exit;
        }

        global $wpdb;
        $existing_row          = $wpdb->get_row( $wpdb->prepare( "SELECT restrictions_json FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $existing_restrictions = json_decode( $existing_row['restrictions_json'] ?? '{}', true ) ?: [];

        $restrictions = [];
        if ( $dry_run_only ) {
            $restrictions['dry_run_only'] = true;
        }
        if ( $ip_raw ) {
            $restrictions['ip_allowlist'] = array_filter( array_map( 'trim', explode( "\n", $ip_raw ) ) );
        }
        if ( $ip_lock ) {
            $restrictions['ip_lock'] = true;
            // Preserve auto-bound IP if ip_lock remains on and the admin left the allowlist field blank.
            if ( empty( $restrictions['ip_allowlist'] ) && ! empty( $existing_restrictions['ip_lock_bound_at'] ) ) {
                $restrictions['ip_allowlist']     = $existing_restrictions['ip_allowlist'] ?? [];
                $restrictions['ip_lock_bound_at'] = $existing_restrictions['ip_lock_bound_at'];
            }
        }
        if ( ! empty( $working_hours['enabled'] ) ) {
            $restrictions['working_hours'] = $working_hours;
        }
        $this->apply_resource_restrictions( $restrictions );
        $expires_ts  = $expires_raw ? strtotime( $expires_raw ) : false;
        $expires_at  = ( $expires_ts !== false ) ? gmdate( 'Y-m-d 23:59:59', $expires_ts ) : null;
        if ( $expires_raw && $expires_ts === false ) {
            wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&error=invalid_date' ) );
            exit;
        }

        $diff    = AICOM_Auth::update_key( $id, $scopes, $restrictions, $expires_at );
        $rotated = false;

        if ( $rotate ) {
            $new_key = AICOM_Auth::rotate_key( $id );
            if ( $new_key ) {
                set_transient( 'aicom_new_key_' . $id, $new_key, 60 );
                $rotated = true;
            }
        }

        set_transient( 'aicom_edit_result_' . $id, wp_json_encode( [
            'added'   => $diff['added'],
            'removed' => $diff['removed'],
            'rotated' => $rotated,
        ] ), 30 );

        wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&edited=' . $id . ( $rotated ? '&rotated=' . $id : '' ) ) );
        exit;
    }

    private function handle_delete_backup( int $id ): void {
        global $wpdb;
        if ( $id ) {
            $wpdb->delete( $wpdb->prefix . 'aicom_backups', [ 'id' => $id ] );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=aicom-backups&deleted=' . $id ) );
        exit;
    }

    private function handle_restore_session( int $session_id ): void {
        if ( ! $session_id ) {
            wp_safe_redirect( admin_url( 'admin.php?page=aicom-audit-logs&tab=sessions&restore_error=1' ) );
            exit;
        }
        $restored = self::do_restore_session( $session_id );
        wp_safe_redirect( admin_url( 'admin.php?page=aicom-audit-logs&tab=sessions&restored=' . $restored ) );
        exit;
    }

    /**
     * Shared system preset definitions — used by both the API Keys page (full list)
     * and the dashboard Quick API key card (first four). Keeping a single source
     * means the dashboard never drifts from the API Keys page.
     */
    public static function system_presets(): array {
        $all_scopes = AICOM_Auth::scope_slugs();
        return [
            'read-only' => [
                'name'   => __( 'Read-only', 'aicom' ),
                'scopes' => [ 'read.wp', 'read.users' ],
                'risk'   => 'low',
                'desc'   => __( 'Safe browsing only', 'aicom' ),
            ],
            'content-assistant' => [
                'name'   => __( 'Content Assistant', 'aicom' ),
                'scopes' => [ 'read.wp', 'write.wp.posts', 'manage.meta', 'manage.taxonomies' ],
                'risk'   => 'med',
                'desc'   => __( 'Write & publish content', 'aicom' ),
            ],
            'elementor-editor' => [
                'name'   => __( 'Elementor Editor', 'aicom' ),
                'scopes' => [ 'read.wp', 'write.wp.posts', 'manage.meta', 'manage.elementor', 'manage.media' ],
                'risk'   => 'med',
                'desc'   => __( 'Build Elementor pages', 'aicom' ),
            ],
            'woocommerce-catalog' => [
                'name'   => __( 'WooCommerce Catalog', 'aicom' ),
                'scopes' => [ 'read.wp', 'write.wp.posts', 'manage.meta', 'manage.woocommerce.products' ],
                'risk'   => 'med',
                'desc'   => __( 'Manage products', 'aicom' ),
            ],
            'site-maintenance' => [
                'name'   => __( 'Site Maintenance', 'aicom' ),
                'scopes' => [ 'read.wp', 'manage.plugins', 'manage.backups', 'manage.wordpress.settings' ],
                'risk'   => 'high',
                'desc'   => __( 'Updates, backups, settings', 'aicom' ),
            ],
            'full-admin' => [
                'name'   => __( 'Full Admin', 'aicom' ),
                'scopes' => $all_scopes,
                'risk'   => 'critical',
                'desc'   => __( 'All permissions', 'aicom' ),
            ],
        ];
    }

    /**
     * Read working_hours form fields out of $_POST into the shape stored in
     * restrictions_json. Returns an empty array when the user didn't enable it.
     * Caller is responsible for nonce/cap; $_POST is already verified by handle_post().
     */
    public static function read_working_hours_from_post(): array {
        if ( empty( $_POST['working_hours_enabled'] ) ) {
            return [];
        }
        $raw_days = filter_input( INPUT_POST, 'working_hours_days', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ) ?? [];
        $days     = array_values( array_unique( array_filter( array_map( 'intval', $raw_days ), function ( $d ) {
            return $d >= 0 && $d <= 6;
        } ) ) );
        $start    = sanitize_text_field( wp_unslash( $_POST['working_hours_start'] ?? '09:00' ) );
        $end      = sanitize_text_field( wp_unslash( $_POST['working_hours_end']   ?? '18:00' ) );

        // Validate HH:MM format; fall back to defaults if malformed.
        if ( ! preg_match( '/^[0-2]\d:[0-5]\d$/', $start ) ) { $start = '09:00'; }
        if ( ! preg_match( '/^[0-2]\d:[0-5]\d$/', $end )   ) { $end   = '18:00'; }

        return [
            'enabled' => true,
            'days'    => $days ?: [ 1, 2, 3, 4, 5 ],
            'start'   => $start,
            'end'     => $end,
        ];
    }

    /**
     * Task-oriented "quick start" recipes (mirrors AICOM Hub keys → Quick Start).
     * Each card represents a real-world job the user wants done; the recipe
     * pre-fills sensible scopes (and dry-run where it's safer) so the user
     * doesn't have to think in terms of permissions.
     */
    public static function task_presets(): array {
        return [
            'drafts' => [
                'icon'    => 'pencil',
                'name'    => __( 'Drafts new content', 'aicom' ),
                'desc'    => __( 'Writes posts and pages, sets categories, saves as drafts. Nothing publishes without your nod.', 'aicom' ),
                'scopes'  => [ 'read.wp', 'write.wp.posts', 'manage.meta', 'manage.taxonomies' ],
                'dry_run' => false,
            ],
            'seo' => [
                'icon'    => 'search',
                'name'    => __( 'Polishes SEO across the site', 'aicom' ),
                'desc'    => __( 'Reads and improves Yoast meta titles, descriptions, and Open Graph fields.', 'aicom' ),
                'scopes'  => [ 'read.wp', 'manage.yoast' ],
                'dry_run' => false,
            ],
            'a11y' => [
                'icon'    => 'image',
                'name'    => __( 'Keeps the media library tidy', 'aicom' ),
                'desc'    => __( 'Adds alt text to your images, runs accessibility audits, helps describe what each picture shows.', 'aicom' ),
                'scopes'  => [ 'read.wp', 'manage.media', 'manage.a11y' ],
                'dry_run' => false,
            ],
            'pages' => [
                'icon'    => 'layout',
                'name'    => __( 'Designs pages in Elementor', 'aicom' ),
                'desc'    => __( 'Edits existing Elementor pages, duplicates templates, swaps copy across widgets in one go.', 'aicom' ),
                'scopes'  => [ 'read.wp', 'write.wp.posts', 'manage.meta', 'manage.elementor', 'manage.media' ],
                'dry_run' => false,
            ],
            'shop' => [
                'icon'    => 'cart',
                'name'    => __( 'Manages your shop', 'aicom' ),
                'desc'    => __( 'WooCommerce products, prices, stock, attributes. Tells you what looks off and offers fixes.', 'aicom' ),
                'scopes'  => [ 'read.wp', 'manage.woocommerce.products' ],
                'dry_run' => true,
            ],
            'observer' => [
                'icon'    => 'eye',
                'name'    => __( 'Just watches', 'aicom' ),
                'desc'    => __( 'Read-only access to everything — content, users, settings. Cannot change a thing. Safest mode.', 'aicom' ),
                'scopes'  => [ 'read.wp', 'read.users' ],
                'dry_run' => false,
            ],
        ];
    }

    /**
     * Pick a label not already in use, with macOS-style " (N)" suffix.
     * "Quick Content Assistant" → "Quick Content Assistant (2)" if base is taken.
     */
    public static function next_available_label( string $base ): string {
        global $wpdb;
        $table = $wpdb->prefix . 'aicom_api_keys';
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT label FROM {$table} WHERE label = %s OR label LIKE %s",
            $base,
            $wpdb->esc_like( $base . ' (' ) . '%)'
        ) );
        if ( empty( $rows ) ) return $base;
        $base_taken = false;
        $max = 1;
        foreach ( $rows as $label ) {
            if ( $label === $base ) {
                $base_taken = true;
            } elseif ( preg_match( '/^' . preg_quote( $base, '/' ) . ' \((\d+)\)$/', $label, $m ) ) {
                $n = (int) $m[1];
                if ( $n > $max ) $max = $n;
            }
        }
        if ( ! $base_taken ) return $base;
        return $base . ' (' . ( $max + 1 ) . ')';
    }

    /**
     * Core restore logic — replays all backups for a session in reverse order.
     * Returns the number of objects restored.
     * Public so tests can call it directly without going through the POST handler.
     */
    public static function do_restore_session( int $session_id ): int {
        global $wpdb;

        $backups = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aicom_backups WHERE session_id = %d ORDER BY created_at DESC",
                $session_id
            ),
            ARRAY_A
        );

        $restored = 0;

        foreach ( $backups as $backup ) {
            $payload = json_decode( $backup['payload_json'], true );
            if ( ! $payload ) {
                continue;
            }

            if ( $backup['target_type'] === 'post' && ! empty( $payload['post']['ID'] ) ) {
                $post_data       = $payload['post'];
                $original_id     = (int) $post_data['ID'];
                unset( $post_data['post_modified'], $post_data['post_modified_gmt'] );

                // Check at DB level — get_post() uses object cache which may be stale.
                $exists = (bool) $wpdb->get_var( $wpdb->prepare(
                    "SELECT 1 FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $original_id
                ) );

                if ( $exists ) {
                    $post_data['ID'] = $original_id;
                    wp_update_post( $post_data );
                } else {
                    // Re-insert with the original ID via import_id (wp_insert_post INSERT branch).
                    unset( $post_data['ID'] );
                    $post_data['import_id'] = $original_id;
                    wp_insert_post( $post_data );
                    $post_data['ID'] = $original_id; // restore for meta/terms below
                }

                foreach ( $payload['meta'] ?? [] as $key => $values ) {
                    delete_post_meta( $original_id, $key );
                    foreach ( (array) $values as $val ) {
                        add_post_meta( $original_id, $key, maybe_unserialize( $val ) );
                    }
                }
                foreach ( $payload['terms'] ?? [] as $tax => $term_ids ) {
                    wp_set_post_terms( $original_id, $term_ids, $tax );
                }
                clean_post_cache( $original_id );
                $restored++;

            } elseif ( $backup['target_type'] === 'term' && ! empty( $payload['term']['term_id'] ) ) {
                $t = $payload['term'];
                wp_update_term( (int) $t['term_id'], $t['taxonomy'] ?? '', [
                    'name'        => $t['name']        ?? '',
                    'slug'        => $t['slug']        ?? '',
                    'description' => $t['description'] ?? '',
                    'parent'      => $t['parent']      ?? 0,
                ] );

                foreach ( $payload['meta'] ?? [] as $key => $values ) {
                    delete_term_meta( (int) $t['term_id'], $key );
                    foreach ( (array) $values as $val ) {
                        add_term_meta( (int) $t['term_id'], $key, maybe_unserialize( $val ) );
                    }
                }
                $restored++;
            }
        }

        return $restored;
    }

    /**
     * Delete old backups per configured age/size caps. Called by daily cron.
     */
    public static function run_backup_cleanup(): void {
        global $wpdb;

        $max_age = (int) get_option( 'aicom_backup_max_age_days', 0 );
        $max_mb  = (int) get_option( 'aicom_backup_max_size_mb',  0 );

        if ( $max_age > 0 ) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}aicom_backups WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)",
                    current_time( 'mysql', true ),
                    $max_age
                )
            );
        }

        if ( $max_mb > 0 ) {
            $max_bytes = $max_mb * 1024 * 1024;
            for ( $i = 0; $i < 10000; $i++ ) { // hard limit to avoid infinite loop
                $total = (int) $wpdb->get_var( "SELECT SUM(LENGTH(payload_json)) FROM {$wpdb->prefix}aicom_backups" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                if ( $total <= $max_bytes ) {
                    break;
                }
                $oldest = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}aicom_backups ORDER BY created_at ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                if ( ! $oldest ) {
                    break;
                }
                $wpdb->delete( $wpdb->prefix . 'aicom_backups', [ 'id' => $oldest ] );
            }
        }
    }

    // ── Admin Bar ─────────────────────────────────────────────────────────

    public static function register_admin_bar( WP_Admin_Bar $bar ): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        global $wpdb;
        $keys = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT id, label, status FROM {$wpdb->prefix}aicom_api_keys
             WHERE status IN ('active','suspended')
             ORDER BY created_at DESC LIMIT 20",
            ARRAY_A
        );

        $manage_url   = admin_url( 'admin.php?page=aicom-api-keys' );
        $active_count = count( array_filter( $keys, fn( $k ) => $k['status'] === 'active' ) );
        $count_html   = $active_count > 0
            ? ' <span class="aicom-tb-count">' . $active_count . '</span>'
            : '';

        $lock_status = AICOM_Lock_Manager::get_effective_lock();
        $tb_class    = 'aicom-tb-parent';
        if ( $lock_status === 'hard_locked' ) {
            $tb_class .= ' aicom-tb-hardlocked';
        } elseif ( $lock_status === 'soft_locked' ) {
            $tb_class .= ' aicom-tb-softlocked';
        }

        $bar->add_node( [
            'id'    => 'aicom-toolbar',
            'title' => '<span class="ab-icon aicom-tb-icon"></span>'
                     . '<span class="ab-label">aicom keys</span>'
                     . $count_html,
            'href'  => $manage_url,
            'meta'  => [ 'class' => $tb_class ],
        ] );

        // ── Lock controls (first child) ────────────────────────────────────
        $lock_nonce = wp_create_nonce( 'aicom_toolbar_lock' );
        $bar->add_node( [
            'id'     => 'aicom-toolbar-lock',
            'parent' => 'aicom-toolbar',
            'title'  => '<button class="aicom-tb-lock-btn' . ( $lock_status === 'unlocked' ? ' is-active' : '' ) . '"'
                      . ' data-lock="unlock" data-nonce="' . esc_attr( $lock_nonce ) . '">Unlock</button>'
                      . '<button class="aicom-tb-lock-btn' . ( $lock_status === 'soft_locked' ? ' is-active' : '' ) . '"'
                      . ' data-lock="soft" data-nonce="' . esc_attr( $lock_nonce ) . '">Soft Lock</button>'
                      . '<button class="aicom-tb-lock-btn aicom-tb-lock-hard' . ( $lock_status === 'hard_locked' ? ' is-active' : '' ) . '"'
                      . ' data-lock="hard" data-nonce="' . esc_attr( $lock_nonce ) . '">Hard Lock</button>',
            'href'   => false,
            'meta'   => [ 'class' => 'aicom-tb-lock-row' ],
        ] );

        foreach ( $keys as $key ) {
            $is_active  = $key['status'] === 'active';
            $dot        = $is_active ? '🟢' : '🟡';
            $action     = $is_active ? 'suspend_key' : 'unsuspend_key';
            $btn_label  = $is_active ? 'Suspend' : 'Unsuspend';
            $btn_class  = $is_active ? 'aicom-tb-btn-suspend' : 'aicom-tb-btn-unsuspend';

            $bar->add_node( [
                'id'     => 'aicom-key-' . (int) $key['id'],
                'parent' => 'aicom-toolbar',
                'title'  => '<span class="aicom-tb-dot">' . $dot . '</span>'
                          . '<span class="aicom-tb-label">' . esc_html( $key['label'] ) . '</span>'
                          . '<button class="aicom-tb-btn ' . esc_attr( $btn_class ) . '"'
                          . ' data-key-id="' . (int) $key['id'] . '"'
                          . ' data-action="' . esc_attr( $action ) . '"'
                          . ' data-nonce="' . esc_attr( wp_create_nonce( 'aicom_toolbar_' . (int) $key['id'] ) ) . '">'
                          . esc_html( $btn_label )
                          . '</button>',
                'href'   => false,
                'meta'   => [ 'class' => 'aicom-tb-key' ],
            ] );
        }

        $bar->add_node( [
            'id'     => 'aicom-toolbar-manage',
            'parent' => 'aicom-toolbar',
            'title'  => 'Manage API Keys',
            'href'   => $manage_url,
            'meta'   => [ 'class' => 'aicom-tb-manage' ],
        ] );
    }

    public static function ajax_save_preset(): void {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( 'bad_nonce', 403 );
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }

        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        if ( ! $name ) {
            wp_send_json_error( 'missing_name', 400 );
        }

        $raw_scopes = filter_input( INPUT_POST, 'scopes', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ) ?? [];
        $scopes     = array_values( array_map( 'sanitize_text_field', wp_unslash( $raw_scopes ) ) );
        if ( empty( $scopes ) ) {
            wp_send_json_error( 'no_scopes', 400 );
        }

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'aicom_presets',
            [
                'name'                => $name,
                'scopes_json'         => wp_json_encode( $scopes ),
                'created_at'          => current_time( 'mysql' ),
                'created_by_user_id'  => get_current_user_id(),
            ]
        );

        wp_send_json_success( [
            'id'     => (int) $wpdb->insert_id,
            'name'   => $name,
            'scopes' => $scopes,
        ] );
    }

    public static function ajax_delete_preset(): void {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( 'bad_nonce', 403 );
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }

        $id = absint( $_POST['preset_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'invalid_id', 400 );
        }

        global $wpdb;
        $deleted = $wpdb->delete( $wpdb->prefix . 'aicom_presets', [ 'id' => $id ] );
        if ( ! $deleted ) {
            wp_send_json_error( 'not_found', 404 );
        }

        wp_send_json_success( [ 'deleted' => $id ] );
    }

    public static function ajax_rename_preset(): void {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( 'bad_nonce', 403 );
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }

        $id   = absint( wp_unslash( $_POST['id'] ?? 0 ) );
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        if ( ! $id || ! $name ) {
            wp_send_json_error( 'invalid_data', 400 );
        }

        global $wpdb;
        $updated = $wpdb->update( $wpdb->prefix . 'aicom_presets', [ 'name' => $name ], [ 'id' => $id ] );
        if ( $updated === false ) {
            wp_send_json_error( 'db_error', 500 );
        }

        wp_send_json_success( [ 'id' => $id, 'name' => $name ] );
    }

    public static function ajax_duplicate_preset(): void {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( 'bad_nonce', 403 );
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }

        $id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
        if ( ! $id ) {
            wp_send_json_error( 'invalid_id', 400 );
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aicom_presets WHERE id = %d", $id ),
            ARRAY_A
        );
        if ( ! $row ) {
            wp_send_json_error( 'not_found', 404 );
        }

        $new_name = 'Copy of ' . $row['name'];
        $wpdb->insert( $wpdb->prefix . 'aicom_presets', [
            'name'               => $new_name,
            'scopes_json'        => $row['scopes_json'],
            'created_at'         => current_time( 'mysql', true ),
            'created_by_user_id' => get_current_user_id(),
        ] );
        $new_id = (int) $wpdb->insert_id;
        $scopes = json_decode( $row['scopes_json'], true ) ?: [];

        wp_send_json_success( [
            'id'     => $new_id,
            'name'   => $new_name,
            'scopes' => $scopes,
            'count'  => count( $scopes ),
        ] );
    }

    public static function ajax_toolbar_toggle(): void {
        $key_id = absint( $_POST['key_id'] ?? 0 );
        $action = sanitize_key( wp_unslash( $_POST['aicom_action'] ?? '' ) );

        if ( ! $key_id || ! in_array( $action, [ 'suspend_key', 'unsuspend_key' ], true ) ) {
            wp_send_json_error( 'invalid_params', 400 );
        }

        if ( ! check_ajax_referer( 'aicom_toolbar_' . $key_id, 'nonce', false ) ) {
            wp_send_json_error( 'bad_nonce', 403 );
        }

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }

        $ok = $action === 'suspend_key'
            ? AICOM_Auth::suspend_key( $key_id )
            : AICOM_Auth::unsuspend_key( $key_id );

        if ( ! $ok ) {
            wp_send_json_error( 'no_change', 409 );
        }

        $new_status    = $action === 'suspend_key' ? 'suspended' : 'active';
        $new_dot       = $new_status === 'active' ? '🟢' : '🟡';
        $new_action    = $new_status === 'active' ? 'suspend_key' : 'unsuspend_key';
        $new_btn_label = $new_status === 'active' ? 'Suspend' : 'Unsuspend';
        $new_btn_class = $new_status === 'active' ? 'aicom-tb-btn-suspend' : 'aicom-tb-btn-unsuspend';
        $new_nonce     = wp_create_nonce( 'aicom_toolbar_' . $key_id );

        wp_send_json_success( [
            'new_status'    => $new_status,
            'new_dot'       => $new_dot,
            'new_action'    => $new_action,
            'new_btn_label' => $new_btn_label,
            'new_btn_class' => $new_btn_class,
            'new_nonce'     => $new_nonce,
        ] );
    }

    public static function ajax_toolbar_lock(): void {
        if ( ! check_ajax_referer( 'aicom_toolbar_lock', 'nonce', false ) ) {
            wp_send_json_error( 'bad_nonce', 403 );
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }

        $mode = sanitize_key( wp_unslash( $_POST['lock_mode'] ?? '' ) );
        if ( ! in_array( $mode, [ 'unlock', 'soft', 'hard' ], true ) ) {
            wp_send_json_error( 'invalid_mode', 400 );
        }

        AICOM_Lock_Manager::set_hard_lock( $mode === 'hard' );
        AICOM_Lock_Manager::set_soft_lock( $mode === 'soft' );

        if ( $mode === 'unlock' ) {
            // If schedule would lock us, override it until the next working period
            $next = AICOM_Lock_Manager::get_schedule_next_start();
            if ( $next > 0 ) {
                AICOM_Lock_Manager::set_schedule_override_until( $next );
            } else {
                AICOM_Lock_Manager::clear_schedule_override();
            }
        } else {
            // Manual lock applied — clear any schedule override
            AICOM_Lock_Manager::clear_schedule_override();
        }

        $new_status = AICOM_Lock_Manager::get_effective_lock();
        wp_send_json_success( [
            'lock_status' => $new_status,
            'new_nonce'   => wp_create_nonce( 'aicom_toolbar_lock' ),
        ] );
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    private function require_cap(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'aicom' ) );
        }
    }
}
