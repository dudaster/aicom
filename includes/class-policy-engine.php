<?php
/**
 * Policy Engine: lock × tool-class permission matrix + allowlist checks.
 *
 * Lock Permission Matrix (SPEC Table 6):
 *   hard_locked  → only 'public'
 *   soft_locked  → 'public', 'discovery', 'read'
 *   unlocked     → all classes
 */
class AICOM_Policy_Engine {

    // ── Lock Matrix ───────────────────────────────────────────────────────

    private static function allowed_classes( string $effective_lock ): array {
        switch ( $effective_lock ) {
            case 'hard_locked':
                return [ 'public' ];
            case 'soft_locked':
                return [ 'public', 'discovery', 'read' ];
            default:
                return [ 'public', 'discovery', 'read', 'write', 'destructive', 'admin_sensitive' ];
        }
    }

    public static function is_tool_allowed_by_lock( string $tool_class, string $effective_lock ): bool {
        return in_array( $tool_class, self::allowed_classes( $effective_lock ), true );
    }

    // ── Confirm Flag ──────────────────────────────────────────────────────

    /**
     * Destructive/admin_sensitive tools require arguments.confirm === true.
     */
    public static function check_confirm_flag( array $tool_meta, array $arguments ): bool {
        if ( ! $tool_meta['requires_confirm'] ) {
            return true;
        }
        return isset( $arguments['confirm'] ) && $arguments['confirm'] === true;
    }

    // ── Allowlist Checks (delegate to key restrictions_json) ──────────────

    public static function check_post_type_allowlist( array $key_record, string $post_type ): bool {
        $r = self::restrictions( $key_record );
        return empty( $r['post_types'] ) || in_array( $post_type, $r['post_types'], true );
    }

    public static function check_taxonomy_allowlist( array $key_record, string $taxonomy ): bool {
        $r = self::restrictions( $key_record );
        return empty( $r['taxonomies'] ) || in_array( $taxonomy, $r['taxonomies'], true );
    }

    public static function check_meta_key_allowlist( array $key_record, string $meta_key ): bool {
        $r = self::restrictions( $key_record );
        return empty( $r['meta_keys'] ) || in_array( $meta_key, $r['meta_keys'], true );
    }

    public static function check_option_allowlist( array $key_record, string $option_key ): bool {
        $r = self::restrictions( $key_record );
        return empty( $r['options'] ) || in_array( $option_key, $r['options'], true );
    }

    /**
     * File path: default DENY unless path starts with an allowlisted directory.
     */
    public static function check_file_path_allowlist( array $key_record, string $path ): bool {
        $r         = self::restrictions( $key_record );
        $allowlist = $r['file_paths'] ?? [];

        if ( empty( $allowlist ) ) {
            return false; // Deny by default
        }

        // Reject non-existent paths — can't safely verify containment without a canonical path.
        $real = realpath( $path );
        if ( $real === false ) {
            return false;
        }

        foreach ( $allowlist as $allowed ) {
            $allowed_dir = rtrim( realpath( $allowed ) ?: $allowed, '/' ) . '/';
            if ( strpos( rtrim( $real, '/' ) . '/', $allowed_dir ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    public static function check_language_allowlist( array $key_record, string $lang ): bool {
        $r = self::restrictions( $key_record );
        return empty( $r['languages'] ) || in_array( $lang, $r['languages'], true );
    }

    public static function is_dry_run_only( array $key_record ): bool {
        $r = self::restrictions( $key_record );
        return ! empty( $r['dry_run_only'] );
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    private static function restrictions( array $key_record ): array {
        return json_decode( $key_record['restrictions_json'] ?? '{}', true ) ?: [];
    }
}
