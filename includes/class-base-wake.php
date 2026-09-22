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

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @set_time_limit( self::LINGER_MAX_S + 30 );
        AICOM_Base_Connection::audit( 'base.wake', 'success', [] );

        $result = AICOM_Base_Heartbeat::run( 'wake' );
        $waited = 0;
        // Keep polling only while the *previous* tick actually carried commands — an idle site
        // stops after the first, empty check, so a lone wake ping costs one extra heartbeat, not 20s.
        while ( ( $result['commands'] ?? 0 ) > 0 && $waited < self::LINGER_MAX_S ) {
            sleep( self::LINGER_STEP_S );
            $waited += self::LINGER_STEP_S;
            $result  = AICOM_Base_Heartbeat::run( 'wake' );
        }

        return new WP_REST_Response( [ 'ok' => (bool) ( $result['ok'] ?? false ), 'waited_s' => $waited ], 200 );
    }
}
