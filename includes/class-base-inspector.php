<?php
/**
 * Internal inspection evidence for AICOMBase audits (PROTOCOL §7, PRD §29).
 *
 * Read-only, local, bounded. Only what an audit needs is returned: plugin slugs/versions,
 * detected integrations, boolean site settings and version/health signals. No content, no
 * user data (only counts), no credentials, no option values beyond booleans/whitelisted flags.
 *
 * kinds: privacy | security | technical | accessibility. `want` (optional) narrows the
 * top-level keys returned.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base_Inspector {

    /** Slug fragments → label. Matched against the plugin folder slug. */
    private static function detectors(): array {
        return [
            'analytics'  => [
                'google-site-kit' => 'Site Kit', 'google-analytics-for-wordpress' => 'MonsterInsights', 'monsterinsights' => 'MonsterInsights',
                'ga-google-analytics' => 'GA Google Analytics', 'matomo' => 'Matomo', 'wp-statistics' => 'WP Statistics',
                'pixelyoursite' => 'PixelYourSite', 'facebook-for-wordpress' => 'Meta Pixel', 'official-facebook-pixel' => 'Meta Pixel',
                'duracelltomi-google-tag-manager' => 'Google Tag Manager', 'gtm4wp' => 'Google Tag Manager', 'google-tag-manager' => 'Google Tag Manager',
                'hotjar' => 'Hotjar', 'microsoft-clarity' => 'Microsoft Clarity', 'plausible-analytics' => 'Plausible', 'koko-analytics' => 'Koko Analytics',
                'analytify' => 'Analytify', 'exactmetrics' => 'ExactMetrics', 'tracking-code-manager' => 'Tracking Code Manager', 'insert-headers-and-footers' => 'Header/footer scripts',
                'jetpack' => 'Jetpack (Stats)',
            ],
            'forms'      => [
                'contact-form-7' => 'Contact Form 7', 'wpforms' => 'WPForms', 'gravityforms' => 'Gravity Forms', 'ninja-forms' => 'Ninja Forms',
                'fluentform' => 'Fluent Forms', 'formidable' => 'Formidable Forms', 'forminator' => 'Forminator', 'happyforms' => 'Happyforms',
                'everest-forms' => 'Everest Forms', 'ws-form' => 'WS Form', 'weforms' => 'weForms', 'elementor-pro' => 'Elementor Pro (forms widget)',
                'jetformbuilder' => 'JetFormBuilder', 'kali-forms' => 'Kali Forms',
            ],
            'newsletter' => [
                'mailchimp-for-wp' => 'Mailchimp for WP', 'mailchimp-for-woocommerce' => 'Mailchimp for WooCommerce', 'mailpoet' => 'MailPoet',
                'newsletter' => 'Newsletter', 'mailin' => 'Brevo', 'sendinblue' => 'Brevo', 'klaviyo' => 'Klaviyo', 'convertkit' => 'ConvertKit',
                'mailerlite' => 'MailerLite', 'fluent-crm' => 'FluentCRM', 'fluentcrm' => 'FluentCRM', 'wysija-newsletters' => 'MailPoet 2',
            ],
            // slug fragment => [label, option names that exist once the plugin has been set up]
            'consent'    => [
                'complianz-gdpr' => [ 'Complianz', [ 'complianz_options_settings', 'cmplz_wizard_completed_once' ] ],
                'cookie-law-info' => [ 'CookieYes', [ 'CookieLawInfo-0.9', 'cookielawinfo_settings', 'cky_settings' ] ],
                'cookie-notice' => [ 'Cookie Notice', [ 'cookie_notice_options' ] ],
                'cookiebot' => [ 'Cookiebot', [ 'cookiebot-cbid' ] ],
                'borlabs-cookie' => [ 'Borlabs Cookie', [ 'BorlabsCookieStatus' ] ],
                'real-cookie-banner' => [ 'Real Cookie Banner', [ 'rcb-banner-active' ] ],
                'gdpr-cookie-compliance' => [ 'GDPR Cookie Compliance', [ 'moove_gdpr_plugin_settings' ] ],
                'iubenda' => [ 'iubenda', [ 'iubenda_cookie_law_solution' ] ],
                'termly' => [ 'Termly', [ 'termly_api_key' ] ],
                'wp-consent-api' => [ 'WP Consent API', [] ],
                'cookie-script' => [ 'Cookie Script', [] ],
            ],
        ];
    }

    // ── command entry ─────────────────────────────────────────────────────

    /** Handle an `inspect` command and post the evidence. @return bool handled */
    public static function handle( array $cmd ): bool {
        $id   = (string) ( $cmd['inspection_id'] ?? ( $cmd['payload']['inspection_id'] ?? '' ) );
        $kind = (string) ( $cmd['kind'] ?? ( $cmd['payload']['kind'] ?? '' ) );
        $want = (array) ( $cmd['want'] ?? ( $cmd['payload']['want'] ?? [] ) );
        if ( $id === '' ) {
            return true;
        }
        if ( AICOM_Base_State::is_paused() || AICOM_Base_Heartbeat::effective_lock() === 'hard' ) {
            // Inspection is read-only, but a hard lock/pause means "no remote authority" — refuse politely.
            AICOM_Base_Client::site_request( 'POST', '/api/v1/site/inspections/' . rawurlencode( $id ) . '/result', [
                'status' => 'failed', 'error' => 'refused_locked_or_paused',
            ] );
            return true;
        }
        $evidence = self::collect( $kind, array_map( 'strval', $want ) );
        $body     = $evidence === null
            ? [ 'status' => 'failed', 'error' => 'unknown_kind' ]
            : [ 'status' => 'done', 'result' => [ 'kind' => $kind, 'collected_at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ] + $evidence ];
        $res = AICOM_Base_Client::site_request( 'POST', '/api/v1/site/inspections/' . rawurlencode( $id ) . '/result', $body, [ 'timeout' => 20 ] );
        AICOM_Base_Connection::audit( 'base.inspect', $res['ok'] ? 'success' : 'error', [ 'kind' => $kind, 'inspection_id' => $id ] );
        // Leave it un-acked on transport failure so AICOMBase redelivers.
        return $res['ok'] || ( $res['status'] >= 400 && $res['status'] < 500 && $res['status'] !== 429 );
    }

    /** @return array|null null for an unknown kind */
    public static function collect( string $kind, array $want = [] ): ?array {
        switch ( $kind ) {
            case 'privacy':
                $out = self::privacy();
                break;
            case 'security':
                $out = self::security();
                break;
            case 'technical':
                $out = self::technical();
                break;
            case 'accessibility':
                $out = self::accessibility();
                break;
            default:
                return null;
        }
        $want = array_values( array_filter( $want ) );
        return $want ? array_intersect_key( $out, array_flip( $want ) ) ?: $out : $out;
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array<int,array{slug:string,name:string,version:string,active:bool}> */
    private static function plugins(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = (array) get_option( 'active_plugins', [] );
        if ( is_multisite() ) {
            $active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
        }
        $out = [];
        foreach ( get_plugins() as $file => $data ) {
            $slug  = dirname( $file ) !== '.' ? dirname( $file ) : basename( $file, '.php' );
            $out[] = [
                'slug'    => $slug,
                'name'    => (string) $data['Name'],
                'version' => (string) $data['Version'],
                'active'  => in_array( $file, $active, true ),
                'file'    => $file,
            ];
        }
        return $out;
    }

    private static function match_active( array $plugins, array $map ): array {
        $found = [];
        foreach ( $plugins as $p ) {
            if ( ! $p['active'] ) {
                continue;
            }
            foreach ( $map as $frag => $label ) {
                if ( strpos( $p['slug'], $frag ) !== false ) {
                    $found[ is_array( $label ) ? $label[0] : $label ] = $p['slug'];
                    break;
                }
            }
        }
        $out = [];
        foreach ( $found as $label => $slug ) {
            $out[] = [ 'slug' => $slug, 'name' => $label ];
        }
        return $out;
    }

    // ── privacy ───────────────────────────────────────────────────────────

    private static function privacy(): array {
        $plugins = self::plugins();
        $det     = self::detectors();

        $consent = null;
        foreach ( $plugins as $p ) {
            if ( ! $p['active'] ) {
                continue;
            }
            foreach ( $det['consent'] as $frag => [ $label, $opts ] ) {
                if ( strpos( $p['slug'], $frag ) === false ) {
                    continue;
                }
                $configured = false;
                foreach ( $opts as $o ) {
                    $v = get_option( $o, null );
                    if ( $v !== null && $v !== '' && $v !== false ) {
                        $configured = true;
                        break;
                    }
                }
                // Consent plugins with no known option (e.g. the WP Consent API bridge) can't be judged.
                $consent = [ 'slug' => $p['slug'], 'name' => $label, 'configured' => $configured ];
                break 2;
            }
        }

        $active_plugins = [];
        foreach ( $plugins as $p ) {
            if ( $p['active'] ) {
                $active_plugins[] = [ 'slug' => $p['slug'], 'version' => $p['version'], 'active' => true ];
            }
        }

        $policy_page = (int) get_option( 'wp_page_for_privacy_policy', 0 );
        return [
            'active_plugins'       => $active_plugins,
            'analytics'            => self::match_active( $plugins, $det['analytics'] ),
            'forms'                => self::match_active( $plugins, $det['forms'] ),
            'newsletter'           => self::match_active( $plugins, $det['newsletter'] ),
            'woocommerce'          => class_exists( 'WooCommerce' ),
            'registration_enabled' => (bool) get_option( 'users_can_register', 0 ),
            'comments_enabled'     => get_option( 'default_comment_status', 'open' ) === 'open',
            'consent_plugin'       => $consent,
            'wp'                   => [
                'privacy_policy_page_set'       => $policy_page > 0 && get_post_status( $policy_page ) === 'publish',
                'gravatar_enabled'              => (bool) get_option( 'show_avatars', 1 ),
                'comment_cookies_optin'         => (bool) get_option( 'show_comments_cookies_opt_in', 0 ),
                'comment_registration_required' => (bool) get_option( 'comment_registration', 0 ),
                'default_role'                  => (string) get_option( 'default_role', 'subscriber' ),
                'site_language'                 => get_locale(),
            ],
        ];
    }

    // ── security ──────────────────────────────────────────────────────────

    private static function security(): array {
        global $wpdb;
        $plugins = self::plugins();
        $updates = get_site_transient( 'update_plugins' );
        $upd     = [];
        foreach ( $plugins as $p ) {
            $r = $updates->response[ $p['file'] ] ?? null;
            if ( $r && ! empty( $r->new_version ) ) {
                $upd[] = [ 'slug' => $p['slug'], 'version' => $p['version'], 'new_version' => (string) $r->new_version, 'active' => $p['active'] ];
            }
        }
        $theme_updates = get_site_transient( 'update_themes' );
        $theme         = wp_get_theme();
        $core          = get_site_transient( 'update_core' );
        $core_new      = null;
        foreach ( (array) ( $core->updates ?? [] ) as $u ) {
            if ( ( $u->response ?? '' ) === 'upgrade' ) {
                $core_new = (string) $u->current;
                break;
            }
        }

        $admins = count( get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 200 ] ) );
        $default_admin = (bool) get_user_by( 'login', 'admin' );

        return [
            'wp'      => [ 'version' => get_bloginfo( 'version' ), 'core_update_available' => $core_new !== null, 'core_new_version' => $core_new ],
            'plugins' => [
                'total'             => count( $plugins ),
                'active'            => count( array_filter( $plugins, fn( $p ) => $p['active'] ) ),
                'inactive'          => count( array_filter( $plugins, fn( $p ) => ! $p['active'] ) ),
                'updates_available' => $upd,
            ],
            'themes'  => [
                'active'           => [ 'slug' => $theme->get_stylesheet(), 'version' => (string) $theme->get( 'Version' ) ],
                'update_available' => isset( $theme_updates->response[ $theme->get_stylesheet() ] ),
                'installed'        => count( wp_get_themes() ),
            ],
            'users'   => [ 'administrators' => $admins, 'default_admin_username_exists' => $default_admin, 'registration_enabled' => (bool) get_option( 'users_can_register', 0 ), 'default_role' => (string) get_option( 'default_role', 'subscriber' ) ],
            'config'  => [
                'https'                   => is_ssl() || strpos( (string) home_url(), 'https://' ) === 0,
                'force_ssl_admin'         => defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN,
                'xmlrpc_enabled'          => (bool) apply_filters( 'xmlrpc_enabled', true ),
                'file_edit_disabled'      => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
                'file_mods_disabled'      => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
                'wp_debug'                => defined( 'WP_DEBUG' ) && WP_DEBUG,
                'wp_debug_display'        => defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : true,
                'wp_debug_log'            => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
                'default_table_prefix'    => $wpdb->prefix === 'wp_',
                'application_passwords'   => function_exists( 'wp_is_application_passwords_available' ) ? wp_is_application_passwords_available() : null,
                'environment_type'        => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
                'auto_update_core'        => defined( 'WP_AUTO_UPDATE_CORE' ) ? WP_AUTO_UPDATE_CORE : null,
                'aicom_lockdown_mode'     => class_exists( 'AICOM_Lockdown' ) && method_exists( 'AICOM_Lockdown', 'is_enabled' ) ? (bool) AICOM_Lockdown::is_enabled() : null,
            ],
        ];
    }

    // ── technical ─────────────────────────────────────────────────────────

    private static function technical(): array {
        global $wpdb;
        $crons   = _get_cron_array();
        $overdue = 0;
        $total   = 0;
        $now     = time();
        foreach ( (array) $crons as $ts => $hooks ) {
            foreach ( (array) $hooks as $events ) {
                $total += count( (array) $events );
                if ( $ts < $now - 600 ) {
                    $overdue += count( (array) $events );
                }
            }
        }
        $theme = wp_get_theme();
        $ext   = [];
        foreach ( [ 'sodium', 'curl', 'mbstring', 'gd', 'imagick', 'zip', 'intl', 'openssl', 'xml', 'opcache' ] as $e ) {
            $ext[ $e ] = extension_loaded( $e );
        }
        return [
            'wp'    => [
                'version'          => get_bloginfo( 'version' ),
                'multisite'        => is_multisite(),
                'locale'           => get_locale(),
                'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
                'memory_limit'     => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : null,
                'permalinks_pretty' => (string) get_option( 'permalink_structure', '' ) !== '',
                'object_cache'     => (bool) wp_using_ext_object_cache(),
                'block_theme'      => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
                'theme'            => [ 'slug' => $theme->get_stylesheet(), 'version' => (string) $theme->get( 'Version' ) ],
            ],
            'php'   => [
                'version'        => PHP_VERSION,
                'sapi'           => PHP_SAPI,
                'memory_limit'   => (string) ini_get( 'memory_limit' ),
                'max_execution'  => (int) ini_get( 'max_execution_time' ),
                'upload_max'     => (string) ini_get( 'upload_max_filesize' ),
                'extensions'     => $ext,
                'meets_wp_min'   => version_compare( PHP_VERSION, '7.4', '>=' ),
            ],
            'db'    => [ 'server' => (string) $wpdb->db_version(), 'charset' => (string) $wpdb->charset ],
            'cron'  => [ 'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'scheduled_events' => $total, 'overdue_events' => $overdue ],
            'aicom' => [
                'version'    => AICOM_VERSION,
                'db_version' => (string) get_option( AICOM_DB::VERSION_OPT, '' ),
                'db_current' => get_option( AICOM_DB::VERSION_OPT ) === AICOM_DB::DB_VERSION,
                'lock'       => AICOM_Lock_Manager::get_effective_lock(),
                'event_queue' => AICOM_Base_Events::pending_count(),
            ],
        ];
    }

    // ── accessibility ─────────────────────────────────────────────────────

    private static function accessibility(): array {
        global $wpdb;
        $theme   = wp_get_theme();
        $plugins = self::plugins();
        $tags    = (array) $theme->get( 'Tags' );
        $overlay = self::match_active( $plugins, [
            'accessibe' => 'accessiBe', 'userway' => 'UserWay', 'equalweb' => 'EqualWeb', 'one-click-accessibility' => 'One Click Accessibility',
            'wp-accessibility' => 'WP Accessibility', 'pojo-accessibility' => 'One Click Accessibility', 'ally' => 'Ally', 'sienna' => 'Sienna',
        ] );
        $imgs    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $noalt   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt' WHERE p.post_type='attachment' AND p.post_mime_type LIKE 'image/%' AND (m.meta_value IS NULL OR m.meta_value = '')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return [
            'theme'   => [
                'slug'                 => $theme->get_stylesheet(),
                'version'              => (string) $theme->get( 'Version' ),
                'accessibility_ready'  => in_array( 'accessibility-ready', array_map( 'strtolower', $tags ), true ),
                'block_theme'          => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
                'html5_support'        => (bool) current_theme_supports( 'html5' ),
                'title_tag_support'    => (bool) current_theme_supports( 'title-tag' ),
                'responsive_embeds'    => (bool) current_theme_supports( 'responsive-embeds' ),
            ],
            'plugins' => [ 'accessibility_tools' => $overlay, 'elementor' => defined( 'ELEMENTOR_VERSION' ) ],
            'media'   => [ 'images_total' => $imgs, 'images_missing_alt' => $noalt ],
            'site'    => [ 'html_lang' => get_bloginfo( 'language' ), 'text_direction' => is_rtl() ? 'rtl' : 'ltr' ],
        ];
    }
}
