<?php
/**
 * Wake endpoint (PROTOCOL §11): lets AICOMBase nudge this site to check in right now instead of
 * waiting for its next scheduled heartbeat — the usual latency comes almost entirely from waiting
 * for wp-cron to fire on a real visit, not from the conversation itself once it starts.
 *
 * Auth reuses the exact same token format/verifier as an execution authorization (§9) — a Base-signed
 * token with an empty scope set and a `purpose:"wake"` claim the executor itself never checks, so no
 * new crypto is needed on either side. Best-effort only: if this endpoint is never called (older AICOM
 * version, request lost, site unreachable), the normal heartbeat cron still delivers the same queued
 * work — this purely shaves latency, never a hard dependency.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base_Wake {

    /** Bounded long-poll after the immediate tick: keep checking briefly for the next step of the
     *  same multi-step session before going back to sleep, instead of forcing a fresh wake round-trip
     *  for every single step. Kept well under common hosting proxy timeouts (~30-60s). */
    const LINGER_MAX_S  = 20;
    const LINGER_STEP_S = 3;

    public static function register_routes(): void {
        register_rest_route( 'aicom/v1', '/wake', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle' ],
            // Authentication is the signed token inside the body, verified below — same posture as
            // the execute command's authorization (checked in-callback, not via permission_callback).
            'permission_callback' => '__return_true',
        ] );
    }

    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        if ( ! AICOM_Base_State::is_connected() ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'not_connected' ], 409 );
        }
        $body  = json_decode( $request->get_body() ?: '{}', true );
        $token = is_array( $body ) ? (string) ( $body['token'] ?? '' ) : '';
        $v     = AICOM_Base_Signer::verify_token(
            $token,
            (string) AICOM_Base_State::get( 'base_public_key', '' ),
            (string) AICOM_Base_State::get( 'base_key_id', '' ),
            AICOM_Base_State::site_id()
        );
        if ( $v['ok'] && ( $v['claims']['purpose'] ?? '' ) !== 'wake' ) {
            $v = [ 'ok' => false, 'error' => 'wrong_purpose' ];
        }
        if ( $v['ok'] ) {
            $claims = $v['claims'];
            if ( ! AICOM_Base_Executor::consume_nonce( (string) $claims['nonce'], (int) $claims['expires_at'] ) ) {
                $v = [ 'ok' => false, 'error' => 'replayed' ];
            }
        }
        if ( ! $v['ok'] ) {
            if ( in_array( $v['error'], [ 'bad_signature', 'wrong_audience', 'wrong_issuer', 'malformed_token', 'replayed' ], true ) ) {
                AICOM_Base_Events::security( 'credential_anomaly', 'high', [ 'reason' => 'wake_' . $v['error'] ] );
            }
            AICOM_Base_Connection::audit( 'base.wake', 'blocked', [ 'reason' => $v['error'] ] );
            return new WP_REST_Response( [ 'ok' => false, 'error' => $v['error'] ], 401 );
        }

        AICOM_Base_Connection::audit( 'base.wake', 'success', [] );

        // Answer at once and do the work after the response is out (shutdown runs after the REST server sent it).
        // AICOMBase doesn't wait for the work, and on LiteSpeed a caller hanging up would end this PHP process
        // in the middle of a command. Set before returning: the caller may hang up while the response is sent.
        ignore_user_abort( true );
        add_action( 'shutdown', [ __CLASS__, 'run_after_response' ], 100 );
        return new WP_REST_Response( [ 'ok' => true, 'accepted' => true ], 200 );
    }

    /** The check-in a wake asked for, plus the bounded linger — after the caller has its answer. */
    public static function run_after_response(): void {
        AICOM_Base::detach_from_client();
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit( self::LINGER_MAX_S + 30 );

        $result = AICOM_Base_Heartbeat::run( 'wake' );
        $waited = 0;
        while ( self::keep_lingering( $result, $waited ) ) {
            sleep( self::LINGER_STEP_S );
            $waited += self::LINGER_STEP_S;
            $result  = AICOM_Base_Heartbeat::run( 'wake' );
        }
    }

    /**
     * Poll again only while the previous tick carried commands — an idle site stops after the first, empty check,
     * so a lone wake ping costs one extra heartbeat, not 20 s — or while another check-in held the lock: it may have
     * pulled before this wake's command was queued, and the command would otherwise wait for the next scheduled
     * heartbeat (2026-11-05).
     */
    public static function keep_lingering( array $result, int $waited ): bool {
        if ( $waited >= self::LINGER_MAX_S ) {
            return false;
        }
        return ( $result['commands'] ?? 0 ) > 0 || ( $result['skipped'] ?? '' ) === 'locked';
    }
}
