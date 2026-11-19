<?php
/**
 * Uninstall WP Plugin Health Check.
 *
 * Removes the plugin settings, its weekly cron event and cached
 * WordPress.org API responses.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wphc_settings' );

$timestamp = wp_next_scheduled( 'wphc_weekly_digest' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'wphc_weekly_digest' );
}

global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wphc_api_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wphc_api_' ) . '%'
	)
);
