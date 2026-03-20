<?php
/**
 * Uninstall ACL - AI Control Layer
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
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}acl_api_keys`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}acl_logs`" );
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}acl_backups`" );

// Remove plugin options.
delete_option( 'acl_db_version' );
delete_option( 'acl_soft_lock' );
delete_option( 'acl_hard_lock' );

// Remove any leftover transients.
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '_transient_acl_new_key_%'" );
$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE '_transient_timeout_acl_new_key_%'" );
