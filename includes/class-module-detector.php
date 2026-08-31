<?php
/**
 * Detect active optional plugin dependencies.
 * All methods are static — no instantiation needed.
 */
class AICOM_Module_Detector {

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

    public static function is_ecs_active(): bool {
        return defined( 'ECS_VERSION' );
    }

    public static function is_ecs_pro_active(): bool {
        // ELECSP_VER is defined by ele-custom-skin-pro; requires ECS free to also be active.
        return defined( 'ELECSP_VER' ) && self::is_ecs_active();
    }

    public static function is_clautron_active(): bool {
        return defined( 'CLAUTRON_VERSION' );
    }

    public static function is_yoast_active(): bool {
        return class_exists( 'WPSEO_Meta' );
    }

    public static function is_yoast_premium_active(): bool {
        return class_exists( 'WPSEO_Premium' );
    }

    public static function is_seopress_active(): bool {
        return defined( 'SEOPRESS_VERSION' );
    }

    public static function is_seopress_pro_active(): bool {
        return defined( 'SEOPRESS_PRO_VERSION' );
    }

    // Single source of truth for "is this optional dependency active" —
    // module slug (as used in tool registrations' 'dependency' field and in
    // get_active_modules()) => detector method name. Both
    // AICOM_Tool_Router's Step 5 dependency gate and get_active_modules()
    // below read from this one map, instead of each keeping their own
    // hand-written if/elseif chain in sync. That duplication is exactly what
    // let a real bug ship: the SEOPress module was added to
    // get_active_modules() and the tool registry, but the router's separate
    // copy of this same check was missed, so every seopress.* tool call
    // failed with DEPENDENCY_MISSING despite the module being active — only
    // caught by running the new tools against a live install, not by tests
    // or code review. Adding a new optional integration now only means
    // adding one line here.
    private const DEPENDENCY_CHECKS = [
        'woocommerce' => 'is_woocommerce_active',
        'elementor'   => 'is_elementor_active',
        'polylang'    => 'is_polylang_active',
        'ecs'         => 'is_ecs_active',
        'ecs_pro'     => 'is_ecs_pro_active',
        'clautron'    => 'is_clautron_active',
        'yoast'       => 'is_yoast_active',
        'seopress'    => 'is_seopress_active',
    ];

    /**
     * Whether a tool's 'dependency' value (e.g. 'seopress', 'polylang') is
     * currently active. Unknown dependency slugs are treated as inactive —
     * a typo'd or removed dependency string should fail closed, not open.
     */
    public static function is_dependency_active( string $dependency ): bool {
        $method = self::DEPENDENCY_CHECKS[ $dependency ] ?? null;
        return $method !== null && self::$method();
    }

    /**
     * Returns list of active module slugs (used in Tool Registry filtering).
     */
    public static function get_active_modules(): array {
        $modules = [ 'wp_core', 'media', 'users', 'backup' ];

        foreach ( self::DEPENDENCY_CHECKS as $slug => $method ) {
            if ( self::$method() ) {
                $modules[] = $slug;
            }
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
            'ecs'         => self::is_ecs_pro_active() ? 'active_pro' : ( self::is_ecs_active() ? 'active_free' : 'inactive' ),
            'clautron'    => self::is_clautron_active() ? 'active' : 'inactive',
            'yoast'       => self::is_yoast_premium_active() ? 'active_premium' : ( self::is_yoast_active() ? 'active_free' : 'inactive' ),
            'seopress'    => self::is_seopress_pro_active() ? 'active_pro' : ( self::is_seopress_active() ? 'active_free' : 'inactive' ),
        ];
    }
}
