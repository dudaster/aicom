<?php
/**
 * Detect active optional plugin dependencies.
 * All methods are static — no instantiation needed.
 */
class WPOPS_Module_Detector {

    public static function is_woocommerce_active(): bool {
        return class_exists( 'WooCommerce' );
    }

    public static function is_elementor_active(): bool {
        return defined( 'ELEMENTOR_VERSION' );
    }

    public static function is_polylang_active(): bool {
        // POLYLANG_VERSION = plugin is installed.
        // $GLOBALS['polylang'] set = Polylang is fully initialized (has languages or admin/REST context).
        // Without initialization, pll_* API functions will fail with null-dereference.
        return defined( 'POLYLANG_VERSION' ) && isset( $GLOBALS['polylang'] );
    }

    /**
     * Returns true if Polylang plugin is installed (regardless of initialization state).
     * Used for server.status display only.
     */
    public static function is_polylang_installed(): bool {
        return defined( 'POLYLANG_VERSION' );
    }

    /**
     * Returns list of active module slugs (used in Tool Registry filtering).
     */
    public static function get_active_modules(): array {
        $modules = [ 'wp_core', 'media', 'users', 'backup' ];

        if ( self::is_woocommerce_active() ) {
            $modules[] = 'woocommerce';
        }
        if ( self::is_elementor_active() ) {
            $modules[] = 'elementor';
        }
        if ( self::is_polylang_active() ) {
            $modules[] = 'polylang';
        }

        return $modules;
    }

    /**
     * Returns map of module => status for display in admin/server.status.
     */
    public static function get_module_status_map(): array {
        return [
            'wp_core'     => 'active',
            'media'       => 'active',
            'users'       => 'active',
            'backup'      => 'active',
            'woocommerce' => self::is_woocommerce_active() ? 'active' : 'inactive',
            'elementor'   => self::is_elementor_active()   ? 'active' : 'inactive',
            'polylang'    => self::is_polylang_installed() ? ( self::is_polylang_active() ? 'active' : 'installed_no_languages' ) : 'inactive',
        ];
    }
}
