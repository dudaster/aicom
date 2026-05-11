<?php
/**
 * Soft Lock / Hard Lock state management.
 *
 * Effective lock derivation:
 *   max( manual_lock, schedule_lock ) — most restrictive wins.
 *   manual: hard_lock ON → "hard_locked"; soft_lock ON → "soft_locked"; else "unlocked"
 *   schedule: outside working hours → configured lock type; else "unlocked"
 *   schedule override: clicking Unlock outside hours bypasses schedule until next working period.
 */
class AICOM_Lock_Manager {

    const OPT_SOFT             = 'aicom_soft_lock';
    const OPT_HARD             = 'aicom_hard_lock';
    const OPT_SCHEDULE         = 'aicom_schedule';
    const OPT_SCHED_OVERRIDE   = 'aicom_schedule_override_until'; // Unix timestamp

    private static $severity = [ 'unlocked' => 0, 'soft_locked' => 1, 'hard_locked' => 2 ];

    private static function get_manual_lock(): string {
        if ( get_option( self::OPT_HARD, false ) ) { return 'hard_locked'; }
        if ( get_option( self::OPT_SOFT, false ) )  { return 'soft_locked'; }
        return 'unlocked';
    }

    public static function get_schedule_lock(): string {
        $s = self::get_schedule();
        if ( empty( $s['enabled'] ) ) { return 'unlocked'; }

        // Schedule override active (user clicked Unlock outside hours)
        $override_until = (int) get_option( self::OPT_SCHED_OVERRIDE, 0 );
        if ( $override_until > time() ) { return 'unlocked'; }

        $tz       = wp_timezone();
        $now      = new DateTimeImmutable( 'now', $tz );
        $day      = (int) $now->format( 'w' );
        $time     = $now->format( 'H:i' );
        $days     = array_map( 'intval', $s['days'] ?? [] );
        $start    = $s['start'] ?? '09:00';
        $end      = $s['end']   ?? '18:00';

        $in_hours = in_array( $day, $days, true ) && $time >= $start && $time < $end;
        return $in_hours ? 'unlocked' : ( $s['lock_type'] ?? 'soft_locked' );
    }

    /**
     * Returns the Unix timestamp when the next configured working period starts.
     * Returns 0 if schedule is disabled or we are currently inside working hours.
     * Used to determine how long a schedule override should last.
     */
    public static function get_schedule_next_start(): int {
        $s = self::get_schedule();
        if ( empty( $s['enabled'] ) ) { return 0; }

        $tz    = wp_timezone();
        $now   = new DateTimeImmutable( 'now', $tz );
        $day   = (int) $now->format( 'w' );
        $time  = $now->format( 'H:i' );
        $days  = array_map( 'intval', $s['days'] ?? [] );
        $start = $s['start'] ?? '09:00';
        $end   = $s['end']   ?? '18:00';

        // Already in working hours — no override needed
        if ( in_array( $day, $days, true ) && $time >= $start && $time < $end ) { return 0; }

        [ $sh, $sm ] = array_map( 'intval', explode( ':', $start ) );
        for ( $i = 1; $i <= 7; $i++ ) {
            $candidate = $now->modify( "+{$i} days" )->setTime( $sh, $sm, 0 );
            if ( in_array( (int) $candidate->format( 'w' ), $days, true ) ) {
                return $candidate->getTimestamp();
            }
        }
        return 0;
    }

    public static function set_schedule_override_until( int $timestamp ): void {
        update_option( self::OPT_SCHED_OVERRIDE, $timestamp );
    }

    public static function clear_schedule_override(): void {
        delete_option( self::OPT_SCHED_OVERRIDE );
    }

    public static function get_effective_lock(): string {
        $manual   = self::get_manual_lock();
        $schedule = self::get_schedule_lock();
        return ( self::$severity[ $schedule ] ?? 0 ) > ( self::$severity[ $manual ] ?? 0 )
            ? $schedule
            : $manual;
    }

    public static function get_schedule(): array {
        return json_decode( get_option( self::OPT_SCHEDULE, '{}' ), true ) ?: [];
    }

    public static function set_schedule( array $schedule ): void {
        update_option( self::OPT_SCHEDULE, wp_json_encode( $schedule ) );
    }

    public static function set_soft_lock( bool $enabled ): void {
        update_option( self::OPT_SOFT, $enabled );
    }

    public static function set_hard_lock( bool $enabled ): void {
        update_option( self::OPT_HARD, $enabled );
    }

    public static function is_soft_locked(): bool {
        return self::get_effective_lock() === 'soft_locked';
    }

    public static function is_hard_locked(): bool {
        return self::get_effective_lock() === 'hard_locked';
    }

    public static function get_state(): array {
        $override_until = (int) get_option( self::OPT_SCHED_OVERRIDE, 0 );
        return [
            'soft_lock'               => (bool) get_option( self::OPT_SOFT, false ),
            'hard_lock'               => (bool) get_option( self::OPT_HARD, false ),
            'effective_lock'          => self::get_effective_lock(),
            'schedule'                => self::get_schedule(),
            'schedule_lock'           => self::get_schedule_lock(),
            'schedule_override_until' => $override_until > time() ? $override_until : 0,
        ];
    }
}
