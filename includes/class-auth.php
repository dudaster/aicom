<?php
/**
 * API Key generation, validation, rotation, and revocation.
 *
 * Key format: aicom_ (6) + 8-char alphanumeric prefix + _ (1) + 40-char hex secret = 55 chars
 * DB stores:  key_prefix = "aicom_XXXXXXXX" (14 chars), key_hash = password_hash(full_key)
 * Lookup:     Extract prefix (substr 0..13) → query → password_verify full key
 */
class AICOM_Auth {

    const PREFIX_MARKER = 'aicom_';
    const PREFIX_LEN    = 14; // "aicom_" (6) + 8 random chars

    // ── Authoritative scope vocabulary ────────────────────────────────────
    /**
     * The complete, ordered scope tree displayed by the manual API Keys form
     * and validated against by the Hub management channel. This is the
     * single source of truth for what permissions a key can hold on this
     * Local site. Adding a scope here is the only way to make it minteable.
     *
     * Shape: [ group_label => [ scope_slug => [ description, risk_level ] ] ]
     */
    public static function scope_tree(): array {
        return [
            __( 'WordPress Core', 'aicom' ) => [
                'read.wp'           => [ __( 'Read posts, terms, meta, settings', 'aicom' ), 'low' ],
                'write.wp.posts'    => [ __( 'Create & edit posts/pages',          'aicom' ), 'med' ],
                'delete.wp.posts'   => [ __( 'Trash & delete posts',               'aicom' ), 'med' ],
                'manage.meta'       => [ __( 'Post meta read/write',               'aicom' ), 'med' ],
                'manage.taxonomies' => [ __( 'Categories, tags, taxonomies',       'aicom' ), 'med' ],
                'manage.menus'      => [ __( 'Navigation menus',                   'aicom' ), 'med' ],
            ],
            __( 'Media & Files', 'aicom' ) => [
                'manage.media' => [ __( 'Upload & manage media library',          'aicom' ), 'med' ],
                'manage.files' => [ __( 'Direct file system access',              'aicom' ), 'high' ],
                'manage.a11y'  => [ __( 'Accessibility audits & alt text fixes',  'aicom' ), 'med' ],
            ],
            __( 'Users & Roles', 'aicom' ) => [
                'read.users'   => [ __( 'List & read user profiles',   'aicom' ), 'med' ],
                'manage.users' => [ __( 'Create & update users',       'aicom' ), 'high' ],
                'delete.users' => [ __( 'Delete users',                'aicom' ), 'high' ],
                'manage.roles' => [ __( 'Manage roles & capabilities', 'aicom' ), 'high' ],
            ],
            __( 'Skills', 'aicom' ) => [
                'read.skills'   => [ __( 'List, search and run Skills',                          'aicom' ), 'low'  ],
                'manage.skills' => [ __( 'Create, update, archive and delete Skills',            'aicom' ), 'high' ],
                'learn.skills'  => [ __( 'Suggest and propose Skill updates from sessions',      'aicom' ), 'med'  ],
            ],
            __( 'Site Configuration', 'aicom' ) => [
                'manage.wordpress.settings' => [ __( 'WordPress options/settings',  'aicom' ), 'critical' ],
                'manage.plugins'            => [ __( 'Plugin management & updates',  'aicom' ), 'critical' ],
                'manage.backups'            => [ __( 'Backup & restore data',        'aicom' ), 'high' ],
            ],
            __( 'Integrations', 'aicom' ) => [
                'read.woocommerce'            => [ __( 'Read WooCommerce data (products, categories, settings)', 'aicom' ), 'low' ],
                'manage.woocommerce.products' => [ __( 'Manage WooCommerce products',     'aicom' ), 'med' ],
                'manage.woocommerce.settings' => [ __( 'Manage WooCommerce settings',     'aicom' ), 'high' ],
                'read.elementor'              => [ __( 'Read Elementor pages and widgets', 'aicom' ), 'low' ],
                'manage.elementor'            => [ __( 'Edit Elementor pages and widgets', 'aicom' ), 'med' ],
                'read.polylang'               => [ __( 'Read Polylang languages and translation links', 'aicom' ), 'low', __( 'View configured languages, and see which language a post/term/string has and which translations are linked to it. No changes allowed.', 'aicom' ) ],
                'manage.polylang'             => [ __( 'Manage Polylang Post translations',    'aicom' ), 'med', __( 'Set the language of a post/page, and link or unlink its translations. Does not allow changing category/tag languages, site string translations, or Polylang\'s own settings.', 'aicom' ) ],
                'manage.polylang.settings'    => [ __( 'Manage Polylang Term & String translations', 'aicom' ), 'high', __( 'Set the language of categories/tags and their translation links, and translate site strings (site title, tagline, date/time formats). Does not allow adding/removing languages or changing Polylang\'s own settings.', 'aicom' ) ],
                'manage.yoast'                => [ __( 'Edit Yoast SEO fields (reading is covered by Read posts/terms/meta)', 'aicom' ), 'med' ],
                'manage.clautron'             => [ __( 'Edit Clautron blueprints (reading is covered by Read posts/terms/meta)', 'aicom' ), 'med' ],
            ],
        ];
    }

    /** Flattened slug → [description, risk] map. Useful for validation. */
    public static function scope_flat(): array {
        return array_merge( ...array_values( self::scope_tree() ) );
    }

    /** Just the slugs, ordered. Used by validators. */
    public static function scope_slugs(): array {
        return array_keys( self::scope_flat() );
    }

    // ── Key Generation ────────────────────────────────────────────────────

    /**
     * Generate a new key triple (plain_key, key_prefix, key_hash).
     * Returns array — caller must store plain_key only for one-time display.
     */
    public static function generate_key(): array {
        $random_prefix = bin2hex( random_bytes( 4 ) ); // 8 hex chars = 32 bits of CSPRNG entropy
        $secret        = bin2hex( random_bytes( 20 ) ); // 40 hex chars
        $plain         = self::PREFIX_MARKER . $random_prefix . '_' . $secret;

        return [
            'plain_key'  => $plain,
            'key_prefix' => self::PREFIX_MARKER . $random_prefix,
            'key_hash'   => password_hash( $plain, PASSWORD_BCRYPT, [ 'cost' => 12 ] ),
        ];
    }

    // ── CRUD ──────────────────────────────────────────────────────────────

    /**
     * Insert a new API key row. Returns ['id', 'plain_key', 'key_prefix'].
     */
    public static function create_key( string $label, array $scopes, array $restrictions = [], ?string $expires_at = null ): array {
        global $wpdb;

        $key_data = self::generate_key();
        $now      = current_time( 'mysql', true ); // UTC

        $wpdb->insert(
            $wpdb->prefix . 'aicom_api_keys',
            [
                'label'              => sanitize_text_field( $label ),
                'key_prefix'         => $key_data['key_prefix'],
                'key_hash'           => $key_data['key_hash'],
                'scopes_json'        => wp_json_encode( $scopes ),
                'restrictions_json'  => ! empty( $restrictions ) ? wp_json_encode( $restrictions ) : null,
                'status'             => 'active',
                'created_at'         => $now,
                'updated_at'         => $now,
                'created_by_user_id' => get_current_user_id() ?: null,
                'expires_at'         => $expires_at ?: null,
            ]
        );

        return [
            'id'         => (int) $wpdb->insert_id,
            'plain_key'  => $key_data['plain_key'],
            'key_prefix' => $key_data['key_prefix'],
        ];
    }

    /**
     * Validate a plain-text API key. Returns key row (ARRAY_A) or null.
     */
    public static function validate_key( string $plain_key ): ?array {
        global $wpdb;

        if ( strlen( $plain_key ) < self::PREFIX_LEN + 2 ) {
            return null;
        }

        $prefix = substr( $plain_key, 0, self::PREFIX_LEN );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aicom_api_keys
                 WHERE key_prefix = %s AND status = 'active'
                 AND (expires_at IS NULL OR expires_at > %s)",
                $prefix,
                current_time( 'mysql', true )
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            // Always run a dummy verify to prevent timing-based prefix enumeration.
            password_verify( $plain_key, '$2y$12$invalidhashpaddingthat.isexactly60chars.longXXXXXXXXXXXX' );
            return null;
        }

        if ( ! password_verify( $plain_key, $row['key_hash'] ) ) {
            return null;
        }

        return $row;
    }

    /**
     * Rotate a key (new random secret, same label/scopes/restrictions).
     * Returns new plain key or null if key not found.
     */
    public static function rotate_key( int $key_id ): ?string {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d AND status NOT IN ('revoked','archived')",
                $key_id
            )
        );

        if ( ! $exists ) {
            return null;
        }

        $key_data = self::generate_key();

        $wpdb->update(
            $wpdb->prefix . 'aicom_api_keys',
            [
                'key_prefix' => $key_data['key_prefix'],
                'key_hash'   => $key_data['key_hash'],
                'updated_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $key_id ]
        );

        return $key_data['plain_key'];
    }

    /**
     * Suspend a key temporarily (status → suspended). Reversible via unsuspend_key().
     */
    public static function suspend_key( int $key_id ): bool {
        global $wpdb;

        $rows = $wpdb->update(
            $wpdb->prefix . 'aicom_api_keys',
            [
                'status'     => 'suspended',
                'updated_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $key_id, 'status' => 'active' ]
        );

        return $rows !== false && $rows > 0;
    }

    /**
     * Unsuspend a key (status → active). Only works on suspended keys.
     */
    public static function unsuspend_key( int $key_id ): bool {
        global $wpdb;

        $rows = $wpdb->update(
            $wpdb->prefix . 'aicom_api_keys',
            [
                'status'     => 'active',
                'updated_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $key_id, 'status' => 'suspended' ]
        );

        return $rows !== false && $rows > 0;
    }

    /**
     * Revoke a key immediately (status → revoked).
     */
    public static function revoke_key( int $key_id ): bool {
        global $wpdb;

        $rows = $wpdb->update(
            $wpdb->prefix . 'aicom_api_keys',
            [
                'status'     => 'revoked',
                'revoked_at' => current_time( 'mysql', true ),
                'updated_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $key_id ]
        );

        return $rows !== false && $rows > 0;
    }

    /**
     * Update scopes, restrictions, and expiry on an existing key.
     * Returns diff: ['old_scopes', 'new_scopes', 'added', 'removed'].
     */
    public static function update_key( int $id, array $scopes, array $restrictions, ?string $expires_at ): array {
        global $wpdb;

        $old_scopes = json_decode(
            $wpdb->get_var( $wpdb->prepare( "SELECT scopes_json FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d", $id ) ) ?? '[]',
            true
        ) ?: [];

        $wpdb->update(
            $wpdb->prefix . 'aicom_api_keys',
            [
                'scopes_json'       => wp_json_encode( array_values( $scopes ) ),
                'restrictions_json' => ! empty( $restrictions ) ? wp_json_encode( $restrictions ) : null,
                'expires_at'        => $expires_at ?: null,
                'updated_at'        => current_time( 'mysql', true ),
            ],
            [ 'id' => $id ]
        );

        return [
            'old_scopes' => $old_scopes,
            'new_scopes' => $scopes,
            'added'      => array_values( array_diff( $scopes, $old_scopes ) ),
            'removed'    => array_values( array_diff( $old_scopes, $scopes ) ),
        ];
    }

    /**
     * Archive a key (hidden from main list, kept for audit). Reversible via unarchive_key().
     */
    public static function archive_key( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'aicom_api_keys',
            [ 'status' => 'archived', 'updated_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id ]
        );
    }

    /**
     * Unarchive a key — restores to 'suspended' status.
     */
    public static function unarchive_key( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'aicom_api_keys',
            [ 'status' => 'suspended', 'updated_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id, 'status' => 'archived' ]
        );
    }

    /**
     * Mark active keys as 'expired' when their expires_at is in the past. Called by cron hourly.
     */
    public static function expire_overdue_keys(): void {
        global $wpdb;
        $now = current_time( 'mysql', true );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}aicom_api_keys
             SET status = 'expired', updated_at = %s
             WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= %s",
            $now, $now
        ) );
    }

    /**
     * Update last_used_at and last_used_ip for a key.
     */
    public static function touch_key( int $key_id, string $ip ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'aicom_api_keys',
            [
                'last_used_at' => current_time( 'mysql', true ),
                'last_used_ip' => substr( $ip, 0, 45 ),
            ],
            [ 'id' => $key_id ]
        );
    }

    // ── Policy Checks ─────────────────────────────────────────────────────

    /**
     * Returns true if no IP restriction or if $ip matches the allowlist.
     */
    public static function check_ip_allowlist( array $key_record, string $ip ): bool {
        $restrictions = json_decode( $key_record['restrictions_json'] ?? '{}', true ) ?: [];
        $allowlist    = $restrictions['ip_allowlist'] ?? [];

        if ( empty( $allowlist ) ) {
            return true;
        }

        foreach ( $allowlist as $entry ) {
            if ( self::ip_matches( $ip, $entry ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if the key is currently within its configured working hours,
     * OR the key has no working_hours restriction at all (most keys).
     *
     * Mirrors AICOM_Lock_Manager::get_schedule_lock() but scoped per-key.
     * Stored under restrictions_json.working_hours:
     *   { enabled, days:[0..6], start:'HH:MM', end:'HH:MM' }
     */
    public static function check_working_hours( array $key_record ): bool {
        $restrictions = json_decode( $key_record['restrictions_json'] ?? '{}', true ) ?: [];
        $wh = $restrictions['working_hours'] ?? null;
        if ( empty( $wh ) || empty( $wh['enabled'] ) ) {
            return true; // no schedule on this key
        }

        $tz    = wp_timezone();
        $now   = new DateTimeImmutable( 'now', $tz );
        $day   = (int) $now->format( 'w' );
        $time  = $now->format( 'H:i' );
        $days  = array_map( 'intval', $wh['days'] ?? [] );
        $start = $wh['start'] ?? '09:00';
        $end   = $wh['end']   ?? '18:00';

        return in_array( $day, $days, true ) && $time >= $start && $time < $end;
    }

    /**
     * Returns true if key has ALL required scopes.
     */
    public static function check_scopes( array $key_record, array $required_scopes ): bool {
        return empty( self::missing_scopes( $key_record, $required_scopes ) );
    }

    public static function missing_scopes( array $key_record, array $required_scopes ): array {
        if ( empty( $required_scopes ) ) {
            return [];
        }
        $key_scopes = json_decode( $key_record['scopes_json'], true ) ?: [];
        $key_scopes = self::expand_implied_scopes( $key_scopes );
        return array_values( array_filter( $required_scopes, fn( $s ) => ! in_array( $s, $key_scopes, true ) ) );
    }

    /**
     * Expand a key's declared scopes with implied ones so a write-level scope
     * automatically grants the matching read-level scope. Keeps old keys
     * (issued before read.* scopes for integrations existed) working without
     * forcing the user to re-mint them.
     *
     * Implication rule: holding any `manage.*` (or `write.*`/`delete.*`) on an
     * integration also satisfies `read.<integration>`.
     */
    public static function expand_implied_scopes( array $scopes ): array {
        $implications = self::scope_implications();

        foreach ( $implications as $write_scope => $implied_read ) {
            if ( in_array( $write_scope, $scopes, true ) && ! in_array( $implied_read, $scopes, true ) ) {
                $scopes[] = $implied_read;
            }
        }

        return $scopes;
    }

    /**
     * Map of write-level scope → implied read scope. Single source of truth so
     * both check_scopes() and the admin UI hint stay aligned.
     */
    public static function scope_implications(): array {
        return [
            'manage.woocommerce.products' => 'read.woocommerce',
            'manage.woocommerce.settings' => 'read.woocommerce',
            'manage.elementor'            => 'read.elementor',
            'manage.polylang'             => 'read.polylang',
            'manage.polylang.settings'    => 'read.polylang',
        ];
    }

    // ── Request Parsing ───────────────────────────────────────────────────

    /**
     * Extract API key from Authorization or X-API-Key headers.
     */
    public static function extract_key_from_request(): ?string {
        $auth = isset( $_SERVER['HTTP_AUTHORIZATION'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) )
            : ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) : '' );

        if ( ! empty( $auth ) && stripos( $auth, 'Bearer ' ) === 0 ) {
            return trim( substr( $auth, 7 ) );
        }

        $x_key = isset( $_SERVER['HTTP_X_API_KEY'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_API_KEY'] ) ) : '';
        if ( ! empty( $x_key ) ) {
            return trim( $x_key );
        }

        return null;
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    /**
     * If ip_lock is enabled on this key and no IP has been bound yet,
     * bind the current request IP by writing it into ip_allowlist.
     * Modifies $key_record in-place so check_ip_allowlist() passes on first use.
     */
    public static function maybe_auto_bind_ip( array &$key_record, string $ip ): void {
        $restrictions = json_decode( $key_record['restrictions_json'] ?? '{}', true ) ?: [];

        if ( empty( $restrictions['ip_lock'] ) ) {
            return; // feature not enabled on this key
        }
        if ( ! empty( $restrictions['ip_allowlist'] ) ) {
            return; // already bound (or manual allowlist set)
        }

        $restrictions['ip_allowlist']     = [ $ip ];
        $restrictions['ip_lock_bound_at'] = current_time( 'mysql', true );
        $new_json                         = wp_json_encode( $restrictions );
        $now                              = current_time( 'mysql', true );

        global $wpdb;
        // Atomic conditional UPDATE: only succeeds if no ip_lock_bound_at was set yet.
        // Prevents two concurrent first-requests from binding different IPs simultaneously.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}aicom_api_keys
             SET restrictions_json = %s, updated_at = %s
             WHERE id = %d AND restrictions_json NOT LIKE %s",
            $new_json, $now, (int) $key_record['id'], '%"ip_lock_bound_at"%'
        ) );

        if ( $affected ) {
            $key_record['restrictions_json'] = $new_json;
        }
    }

    /**
     * Reset an IP-locked key back to "waiting for first use".
     * Clears ip_allowlist and ip_lock_bound_at but keeps ip_lock: true.
     */
    public static function reset_ip_lock( int $key_id ): bool {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT id, restrictions_json FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d",
                $key_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return false;
        }

        $restrictions = json_decode( $row['restrictions_json'] ?? '{}', true ) ?: [];

        if ( empty( $restrictions['ip_lock'] ) ) {
            return false;
        }

        unset( $restrictions['ip_allowlist'], $restrictions['ip_lock_bound_at'] );

        return (bool) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'aicom_api_keys',
            [
                'restrictions_json' => wp_json_encode( $restrictions ),
                'updated_at'        => current_time( 'mysql', true ),
            ],
            [ 'id' => $key_id ]
        );
    }

    private static function ip_matches( string $ip, string $range ): bool {
        if ( $ip === $range ) {
            return true;
        }

        // CIDR notation support (IPv4 only)
        if ( strpos( $range, '/' ) !== false ) {
            [ $subnet, $bits ] = explode( '/', $range, 2 );
            $bits        = (int) $bits;
            $ip_long     = ip2long( $ip );
            $subnet_long = ip2long( $subnet );

            if ( $bits < 0 || $bits > 32 || $ip_long === false || $subnet_long === false ) {
                return false;
            }

            $mask = $bits === 0 ? 0 : ( -1 << ( 32 - $bits ) );
            return ( $ip_long & $mask ) === ( $subnet_long & $mask );
        }

        return false;
    }
}
