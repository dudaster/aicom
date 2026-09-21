<?php
/**
 * AICOMBase connector bootstrap: cron schedule, hooks, opportunistic tick.
 *
 * AICOM works fully standalone; everything here is inert until an admin connects the
 * site, and every network call is time-bounded, off the front-end request path, and
 * fails soft (exponential backoff) — an unreachable AICOMBase never affects the site.
 *
 * Wire contract: ~/Projects/aicombase/docs/PROTOCOL.md — see AICOMBASE-CONNECTOR.md.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base {

    const CRON_HEARTBEAT = 'aicom_base_heartbeat';
    const SCHEDULE       = 'aicom_minute';

    public static function register(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'cron_schedules' ] );
        add_action( self::CRON_HEARTBEAT, [ __CLASS__, 'cron_heartbeat' ] );
        add_action( AICOM_Base_Connection::CRON_POLL, [ 'AICOM_Base_Connection', 'cron_poll' ] );
        AICOM_Base_Events::register();

        // Self-heal: keep the heartbeat scheduled while connected.
        if ( AICOM_Base_State::is_connected() ) {
            self::schedule_heartbeat();
            add_action( 'admin_init', [ __CLASS__, 'maybe_admin_tick' ] );
        } elseif ( AICOM_Base_State::status() === AICOM_Base_State::S_PAIRING ) {
            AICOM_Base_Connection::schedule_poll();
        }

        if ( is_admin() ) {
            new AICOM_Base_Admin();
        }
    }

    public static function cron_schedules( array $schedules ): array {
        $schedules[ self::SCHEDULE ] = [ 'interval' => 60, 'display' => __( 'Every minute (AICOMBase)', 'aicom' ) ];
        return $schedules;
    }

    public static function schedule_heartbeat(): void {
        if ( ! wp_next_scheduled( self::CRON_HEARTBEAT ) ) {
            wp_schedule_event( time() + 5, self::SCHEDULE, self::CRON_HEARTBEAT );
        }
    }

    public static function cron_heartbeat(): void {
        AICOM_Base_Heartbeat::run( 'cron' );
    }

    /**
     * Opportunistic tick from wp-admin for hosts where WP-Cron is starved or disabled:
     * only when the last heartbeat is clearly stale, throttled, and run AFTER the response
     * is sent (shutdown) with a short timeout so admin pages never wait on AICOMBase.
     */
    public static function maybe_admin_tick(): void {
        if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $last = (int) AICOM_Base_State::get( 'last_hb_at', 0 );
        if ( time() - $last < 180 || get_transient( 'aicom_base_admin_tick' ) ) {
            return;
        }
        set_transient( 'aicom_base_admin_tick', 1, 60 );
        add_action( 'shutdown', static function (): void {
            if ( function_exists( 'fastcgi_finish_request' ) ) {
                fastcgi_finish_request();
            }
            AICOM_Base_Heartbeat::run( 'admin' );
        }, 100 );
    }

    public static function unschedule_all(): void {
        wp_clear_scheduled_hook( self::CRON_HEARTBEAT );
        wp_clear_scheduled_hook( AICOM_Base_Connection::CRON_POLL );
    }
}
