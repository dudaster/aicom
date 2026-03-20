<?php
/**
 * Uninstall AICOM - AI Commander for WordPress
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * Removes all plugin data: database tables and options.
 */

// Security: only run when WordPress uninstalls the plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop plugin tables.
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}aicom_api_keys`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}aicom_logs`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}aicom_backups`" );

// Remove plugin options.
delete_option( 'aicom_db_version' );
delete_option( 'aicom_soft_lock' );
delete_option( 'aicom_hard_lock' );

// Remove any leftover transients.
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '_transient_aicom_new_key_%'" );
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '_transient_timeout_aicom_new_key_%'" );
