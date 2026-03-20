<?php
/**
 * Soft Lock / Hard Lock state management.
 *
 * Effective lock derivation (SPEC 8.1):
 *   hard_lock ON  → "hard_locked"
 *   soft_lock ON  → "soft_locked"
 *   both OFF      → "unlocked"
 */
class ACL_Lock_Manager {

    const OPT_SOFT = 'acl_soft_lock';
    const OPT_HARD = 'acl_hard_lock';

    public static function get_effective_lock(): string {
        if ( get_option( self::OPT_HARD, false ) ) {
            return 'hard_locked';
        }
        if ( get_option( self::OPT_SOFT, false ) ) {
            return 'soft_locked';
        }
        return 'unlocked';
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
        return [
            'soft_lock'      => (bool) get_option( self::OPT_SOFT, false ),
            'hard_lock'      => (bool) get_option( self::OPT_HARD, false ),
            'effective_lock' => self::get_effective_lock(),
        ];
    }
}
