<?php
/**
 * Clear whatever caching plugin (if any) a site already has configured, after
 * an AI-driven write.
 *
 * Checked first: simulating WordPress's own "a plugin was updated" signal
 * (upgrader_process_complete) looked like an appealing one-hook-fits-all
 * shortcut, but doesn't hold up — none of the major cache plugins purge on
 * it by default (WP Fastest Cache and LiteSpeed Cache only do it behind a
 * setting that's off out of the box; W3 Total Cache and WP Super Cache don't
 * listen to it at all), and firing it with a fake/null $upgrader argument
 * actively crashes OTHER code that legitimately listens for it expecting a
 * real WP_Upgrader instance (confirmed: WooCommerce's own PluginInstaller
 * throws a TypeError on exactly that). So instead, this calls each cache
 * plugin's own documented "purge everything" entry point directly, detected
 * the same way AICOM_Module_Detector checks for WooCommerce/Elementor/etc.
 *
 * A detected plugin whose purge call fails anyway (e.g. it renamed or
 * removed the method between versions) is logged, never fatal — one
 * cache plugin's own update breaking things is not this tool call's problem,
 * but it shouldn't vanish silently either.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Cache_Buster {

    /** @return array<string,string> plugin label => error message, for any detected plugin whose purge call failed; empty when everything that ran succeeded. */
    public static function purge_all(): array {
        $problems = [];

        foreach ( self::detected_purgers() as $label => $purger ) {
            try {
                $purger();
            } catch ( Throwable $e ) {
                $problems[ $label ] = $e->getMessage();
            }
        }

        if ( $problems ) {
            error_log( 'AICOM cache purge failed for: ' . wp_json_encode( $problems ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate: surfaces a cache plugin's own API change without touching the actual tool response.
        }

        wp_cache_flush();

        return $problems;
    }

    /** Only plugins actually detected active — same style as AICOM_Module_Detector. @return array<string,callable> */
    private static function detected_purgers(): array {
        $purgers = [];

        if ( has_action( 'litespeed_purge_all' ) ) {
            $purgers['LiteSpeed Cache'] = function () {
                do_action( 'litespeed_purge_all' );
            };
        }

        if ( class_exists( 'WpFastestCache' ) ) {
            $purgers['WP Fastest Cache'] = function () {
                do_action( 'wpfc_clear_all_cache' );
            };
        }

        if ( defined( 'W3TC' ) ) {
            $purgers['W3 Total Cache'] = function () {
                do_action( 'w3tc_flush_all' );
            };
        }

        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            $purgers['WP Super Cache'] = function () {
                wp_cache_clear_cache();
            };
        }

        if ( function_exists( 'rocket_clean_domain' ) ) {
            $purgers['WP Rocket'] = function () {
                rocket_clean_domain();
            };
        }

        if ( class_exists( 'WPO_Page_Cache' ) ) {
            $purgers['WP-Optimize'] = function () {
                WPO_Page_Cache::instance()->purge();
            };
        }

        if ( class_exists( 'autoptimizeCache' ) ) {
            $purgers['Autoptimize'] = function () {
                autoptimizeCache::clearall();
            };
        }

        return $purgers;
    }
}
