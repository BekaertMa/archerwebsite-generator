<?php
/**
 * Uninstall routine.
 *
 * Only destroys data when the administrator explicitly opted in, because a
 * consent log is evidence that may still be needed after the plugin is gone.
 *
 * @package Archer_Cookie_Consent
 */

// SECURITY: this file must only ever run through WordPress' uninstall flow.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$archer_cc_settings = get_option( 'archer_cc_settings', array() );

if ( ! is_array( $archer_cc_settings ) || empty( $archer_cc_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$archer_cc_table = $wpdb->prefix . 'archer_cc_consent_log';

// SECURITY: identifier is derived from $wpdb->prefix and a literal; identifiers
// cannot be bound through prepare().
$wpdb->query( "DROP TABLE IF EXISTS {$archer_cc_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.NoCaching

delete_option( 'archer_cc_settings' );
delete_option( 'archer_cc_db_version' );

wp_clear_scheduled_hook( 'archer_cc_purge_consent_log' );
