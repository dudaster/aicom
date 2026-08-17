<?php
/**
 * Idempotency for write/destructive/admin_sensitive tool calls.
 *
 * A client (or a flaky retry from a weak local model) may resend the exact
 * same write tool call — this prevents that from duplicating side effects
 * (e.g. creating the same post twice). Opt-in: applies only when the caller
 * passes an "idempotency_key" argument.
 *
 * Claim is atomic via INSERT IGNORE on the composite PRIMARY KEY (api_key_id,
 * idempotency_key) — same pattern as AICOM_Hub_Pairing's nonce replay guard.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Idempotency {

    /** In-flight rows older than this are treated as abandoned (crashed request), not blocking. */
    const STALENESS_SECONDS = 300; // 5 minutes

    /** Rows older than this are pruned by the daily cron, regardless of status. */
    const GC_HOURS = 48;

    /** Argument keys never included in the args_hash — router-injected or reserved. */
    const HASH_EXCLUDE_KEYS = [ 'idempotency_key', 'dry_run', 'confirm', '_param_warnings', '_aliases_applied' ];

    /**
     * Attempt to claim an idempotency key for this call. Returns one of:
     *   [ 'status' => 'claimed' ]                              — proceed to execute
     *   [ 'status' => 'replay',   'result' => array ]           — return cached result, skip execution
     *   [ 'status' => 'conflict' ]                               — same key, different tool/args
     *   [ 'status' => 'in_progress' ]                            — another request is still executing
     */
    public static function claim( int $api_key_id, string $idempotency_key, string $tool_name, array $arguments ): array {
        global $wpdb;
        $table     = $wpdb->prefix . 'aicom_idempotency_keys';
        $args_hash = self::hash_args( $arguments );
        $now       = current_time( 'mysql', true );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO $table (api_key_id, idempotency_key, tool_name, args_hash, status, created_at)
             VALUES (%d, %s, %s, %s, 'in_progress', %s)",
            $api_key_id, $idempotency_key, $tool_name, $args_hash, $now
        ) );

        if ( $inserted === 1 ) {
            return [ 'status' => 'claimed' ];
        }

        // Row already existed — inspect it.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE api_key_id = %d AND idempotency_key = %s",
            $api_key_id, $idempotency_key
        ), ARRAY_A );

        if ( ! $row ) {
            // Vanishingly unlikely (deleted between INSERT IGNORE and SELECT) — treat as claimable.
            return [ 'status' => 'claimed' ];
        }

        if ( $row['tool_name'] !== $tool_name || $row['args_hash'] !== $args_hash ) {
            return [ 'status' => 'conflict' ];
        }

        if ( $row['status'] === 'completed' ) {
            $cached = json_decode( $row['result_json'] ?? '', true );
            return [ 'status' => 'replay', 'result' => is_array( $cached ) ? $cached : [] ];
        }

        // status === 'in_progress' — reclaim if stale (likely a crashed/timed-out request),
        // otherwise tell the caller another request is still executing this exact call.
        $age = time() - strtotime( $row['created_at'] . ' UTC' );
        if ( $age > self::STALENESS_SECONDS ) {
            $wpdb->update(
                $table,
                [ 'created_at' => $now ],
                [ 'api_key_id' => $api_key_id, 'idempotency_key' => $idempotency_key ]
            );
            return [ 'status' => 'claimed' ];
        }

        return [ 'status' => 'in_progress' ];
    }

    /** Mark a claimed key as completed and cache its (pre-serialization) internal result. */
    public static function complete( int $api_key_id, string $idempotency_key, array $result ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'aicom_idempotency_keys',
            [
                'status'      => 'completed',
                'result_json' => wp_json_encode( $result ),
            ],
            [ 'api_key_id' => $api_key_id, 'idempotency_key' => $idempotency_key ]
        );
    }

    /** Daily cleanup — drop rows older than GC_HOURS regardless of status. */
    public static function gc(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'aicom_idempotency_keys';
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table WHERE created_at < DATE_SUB(%s, INTERVAL %d HOUR)",
            current_time( 'mysql', true ),
            self::GC_HOURS
        ) );
    }

    /**
     * Canonicalize arguments (recursive ksort, reserved keys excluded) and
     * sha256 them. Single documented algorithm so identical logical calls
     * with different key order never false-positive as conflicts.
     */
    public static function hash_args( array $arguments ): string {
        foreach ( self::HASH_EXCLUDE_KEYS as $key ) {
            unset( $arguments[ $key ] );
        }
        self::ksort_recursive( $arguments );
        return hash( 'sha256', wp_json_encode( $arguments ) ?: '' );
    }

    private static function ksort_recursive( array &$arr ): void {
        ksort( $arr );
        foreach ( $arr as &$value ) {
            if ( is_array( $value ) ) {
                self::ksort_recursive( $value );
            }
        }
    }
}
