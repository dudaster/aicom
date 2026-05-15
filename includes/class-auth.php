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
