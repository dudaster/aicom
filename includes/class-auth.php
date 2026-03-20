<?php
/**
 * API Key generation, validation, rotation, and revocation.
 *
 * Key format: acl_ (4) + 8-char alphanumeric prefix + _ (1) + 40-char hex secret = 53 chars
 * DB stores:  key_prefix = "acl_XXXXXXXX" (12 chars), key_hash = password_hash(full_key)
 * Lookup:     Extract prefix (substr 0..11) → query → password_verify full key
 */
class ACL_Auth {

    const PREFIX_MARKER = 'acl_';
    const PREFIX_LEN    = 12; // "acl_" (4) + 8 random chars

    // ── Key Generation ────────────────────────────────────────────────────

    /**
     * Generate a new key triple (plain_key, key_prefix, key_hash).
     * Returns array — caller must store plain_key only for one-time display.
     */
    public static function generate_key(): array {
        $random_prefix = wp_generate_password( 8, false, false );
        $secret        = bin2hex( random_bytes( 20 ) ); // 40 hex chars
        $plain         = self::PREFIX_MARKER . $random_prefix . '_' . $secret;

        return [
            'plain_key'  => $plain,
            'key_prefix' => self::PREFIX_MARKER . $random_prefix,
            'key_hash'   => password_hash( $plain, PASSWORD_BCRYPT ),
        ];
    }

    // ── CRUD ──────────────────────────────────────────────────────────────

    /**
     * Insert a new API key row. Returns ['id', 'plain_key', 'key_prefix'].
     */
    public static function create_key( string $label, array $scopes, array $restrictions = [] ): array {
        global $wpdb;

        $key_data = self::generate_key();
        $now      = current_time( 'mysql', true ); // UTC

        $wpdb->insert(
            $wpdb->prefix . 'acl_api_keys',
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
        $table  = $wpdb->prefix . 'acl_api_keys';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE key_prefix = %s AND status = 'active'",
                $prefix
            ),
            ARRAY_A
        );

        if ( ! $row ) {
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
            $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}acl_api_keys WHERE id = %d", $key_id )
        );

        if ( ! $exists ) {
            return null;
        }

        $key_data = self::generate_key();

        $wpdb->update(
            $wpdb->prefix . 'acl_api_keys',
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
     * Revoke a key immediately (status → revoked).
     */
    public static function revoke_key( int $key_id ): bool {
        global $wpdb;

        $rows = $wpdb->update(
            $wpdb->prefix . 'acl_api_keys',
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
     * Update last_used_at and last_used_ip for a key.
     */
    public static function touch_key( int $key_id, string $ip ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'acl_api_keys',
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
     * Returns true if key has ALL required scopes.
     */
    public static function check_scopes( array $key_record, array $required_scopes ): bool {
        if ( empty( $required_scopes ) ) {
            return true;
        }

        $key_scopes = json_decode( $key_record['scopes_json'], true ) ?: [];

        foreach ( $required_scopes as $scope ) {
            if ( ! in_array( $scope, $key_scopes, true ) ) {
                return false;
            }
        }

        return true;
    }

    // ── Request Parsing ───────────────────────────────────────────────────

    /**
     * Extract API key from Authorization or X-API-Key headers.
     */
    public static function extract_key_from_request(): ?string {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' );

        if ( ! empty( $auth ) && stripos( $auth, 'Bearer ' ) === 0 ) {
            return trim( substr( $auth, 7 ) );
        }

        $x_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if ( ! empty( $x_key ) ) {
            return trim( $x_key );
        }

        return null;
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    private static function ip_matches( string $ip, string $range ): bool {
        if ( $ip === $range ) {
            return true;
        }

        // CIDR notation support (IPv4 only)
        if ( strpos( $range, '/' ) !== false ) {
            [ $subnet, $bits ] = explode( '/', $range, 2 );
            $ip_long     = ip2long( $ip );
            $subnet_long = ip2long( $subnet );

            if ( $ip_long === false || $subnet_long === false ) {
                return false;
            }

            $mask = -1 << ( 32 - (int) $bits );
            return ( $ip_long & $mask ) === ( $subnet_long & $mask );
        }

        return false;
    }
}
