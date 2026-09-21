<?php
/**
 * Credential rotation with overlap (PROTOCOL §8, PRD §68).
 *
 *   1. new keypair generated locally (pending)          — private key stays here
 *   2. POST /site/rotate/propose {rotation_id,new_public_key}  signed with the OLD key
 *   3. POST /site/rotate/confirm {rotation_id}                 signed with the NEW key
 *   4. new key promoted to active, old key discarded (AICOMBase keeps it `rotating` ≤ 5 min)
 *
 * If a step fails the pending key is kept and resumed on the next heartbeat; after
 * MAX_ATTEMPTS the rotation is abandoned (old key stays active) and audited.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base_Rotation {

    const MAX_ATTEMPTS = 6;

    public static function run( string $rotation_id ): bool {
        if ( $rotation_id === '' || ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $rotation_id ) ) {
            return false;
        }
        AICOM_Base_State::update( [ 'rotation' => [ 'id' => $rotation_id, 'stage' => 'new', 'attempts' => 0 ] ] );
        AICOM_Base_Connection::audit( 'base.rotation_started', 'success', [ 'rotation_id' => $rotation_id ] );
        return self::step();
    }

    /** Continue an unfinished rotation (called at the top of every heartbeat). */
    public static function resume(): void {
        if ( AICOM_Base_State::get( 'rotation' ) ) {
            self::step();
        }
    }

    private static function step(): bool {
        $r = (array) AICOM_Base_State::get( 'rotation', [] );
        if ( empty( $r['id'] ) ) {
            return false;
        }
        if ( (int) $r['attempts'] >= self::MAX_ATTEMPTS ) {
            AICOM_Base_Identity::abort_rotation();
            AICOM_Base_State::update( [ 'rotation' => null ] );
            AICOM_Base_Connection::audit( 'base.rotation_failed', 'error', [ 'rotation_id' => $r['id'], 'reason' => 'max_attempts' ] );
            return false;
        }
        $r['attempts'] = (int) $r['attempts'] + 1;
        AICOM_Base_State::update( [ 'rotation' => $r ] );

        $pending = AICOM_Base_Identity::begin_rotation( (string) $r['id'] );

        if ( $r['stage'] === 'new' ) {
            $res = AICOM_Base_Client::site_request( 'POST', '/api/v1/site/rotate/propose', [
                'rotation_id'    => $r['id'],
                'new_public_key' => $pending['public_key'],
            ], [ 'key' => 'active' ] );
            if ( ! $res['ok'] ) {
                return self::failed( $r, $res, 'propose' );
            }
            $r['stage'] = 'proposed';
            AICOM_Base_State::update( [ 'rotation' => $r ] );
        }

        $res = AICOM_Base_Client::site_request( 'POST', '/api/v1/site/rotate/confirm', [ 'rotation_id' => $r['id'] ], [ 'key' => 'pending' ] );
        if ( ! $res['ok'] ) {
            return self::failed( $r, $res, 'confirm' );
        }
        AICOM_Base_Identity::commit_rotation();
        AICOM_Base_State::update( [ 'rotation' => null, 'rotated_at' => time() ] );
        AICOM_Base_Connection::audit( 'base.rotation_completed', 'success', [ 'rotation_id' => $r['id'], 'key_id' => AICOM_Base_Identity::public_info()['key_id'] ?? '' ] );
        return true;
    }

    private static function failed( array $r, array $res, string $stage ): bool {
        // A definitive 4xx (other than rate limit / auth-time issues) means AICOMBase will not accept this rotation.
        if ( $res['status'] >= 400 && $res['status'] < 500 && ! in_array( $res['status'], [ 401, 429 ], true ) ) {
            AICOM_Base_Identity::abort_rotation();
            AICOM_Base_State::update( [ 'rotation' => null ] );
        }
        AICOM_Base_Connection::audit( 'base.rotation_failed', 'error', [ 'rotation_id' => $r['id'], 'stage' => $stage, 'error' => $res['error'] ] );
        return false;
    }
}
