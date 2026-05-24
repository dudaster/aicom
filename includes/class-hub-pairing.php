<?php
/**
 * Pairing token lifecycle + pairing record storage.
 *
 * One pairing token at a time: generating a new one invalidates any previous.
 * Tokens are short-lived (10 min), single-use, stored as sha256 hash via
 * transient so they auto-expire even if never consumed. The plaintext token
 * is shown to the admin once and never persisted.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Hub_Pairing {

    const TOKEN_TRANSIENT = 'aicom_hub_pairing_token';
    const TOKEN_TTL       = 600; // 10 minutes

    /** Create a new pairing token, invalidate any previous, return the plaintext. */
    public static function create_token(): string {
        $plain = bin2hex( random_bytes( 32 ) );
        set_transient( self::TOKEN_TRANSIENT, hash( 'sha256', $plain ), self::TOKEN_TTL );
        return $plain;
    }

    /**
     * Validate and consume a pairing token. Returns true once; subsequent
     * calls with the same token return false (single-use).
     */
    public static function consume_token( string $plain ): bool {
        $stored = get_transient( self::TOKEN_TRANSIENT );
        if ( ! is_string( $stored ) ) {
            return false;
        }
        if ( ! hash_equals( $stored, hash( 'sha256', $plain ) ) ) {
            return false;
        }
        delete_transient( self::TOKEN_TRANSIENT );
        return true;
    }

    /**
     * Persist a new pairing (or replace an existing one for the same hub_id).
     * Returns the inserted row id.
     */
    public static function store(
        string $hub_id,
        string $hub_url,
        string $management_key_id,
        string $management_secret
    ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'aicom_hub_pairings';
        $now   = current_time( 'mysql', true );

        // Re-pairing: drop any previous record for this hub.
        $wpdb->delete( $table, [ 'hub_id' => $hub_id ] );

        $wpdb->insert( $table, [
            'hub_id'                      => $hub_id,
            'hub_url'                     => untrailingslashit( $hub_url ),
            'management_key_id'           => $management_key_id,
            'management_secret_encrypted' => AICOM_Hub_Crypto::encrypt( $management_secret ),
            'paired_at'                   => $now,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** Look up a pairing by the management key_id. Returns null if unknown. */
    public static function get_by_key_id( string $key_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'aicom_hub_pairings';
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM $table WHERE management_key_id = %s", $key_id ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }
        $secret = AICOM_Hub_Crypto::decrypt( $row['management_secret_encrypted'] );
        if ( $secret === null ) {
            return null;
        }
        $row['management_secret'] = $secret;
        return $row;
    }

    /** All paired hubs (used by the cron sync push). */
    public static function all(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'aicom_hub_pairings';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows  = $wpdb->get_results( "SELECT * FROM $table", ARRAY_A ) ?: [];
        foreach ( $rows as &$row ) {
            $row['management_secret'] = AICOM_Hub_Crypto::decrypt( $row['management_secret_encrypted'] );
        }
        return $rows;
    }

    /** Record nonce as seen. Returns false if already seen (replay). */
    public static function mark_nonce( string $key_id, string $nonce ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'aicom_hub_nonces';
        $now   = current_time( 'mysql', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO $table (key_id, nonce, seen_at) VALUES (%s, %s, %s)",
            $key_id, $nonce, $now
        ) );
        return $ok === 1;
    }

    /** Bump the last_seen heartbeat for a pairing. */
    public static function touch( int $pairing_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'aicom_hub_pairings',
            [ 'last_seen' => current_time( 'mysql', true ) ],
            [ 'id' => $pairing_id ]
        );
    }

    /** Daily cleanup — drop nonces older than 2× the signer skew window. */
    public static function gc_nonces(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'aicom_hub_nonces';
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table WHERE seen_at < DATE_SUB(%s, INTERVAL %d SECOND)",
            current_time( 'mysql', true ),
            AICOM_Hub_Signer::MAX_SKEW * 2
        ) );
    }
}
