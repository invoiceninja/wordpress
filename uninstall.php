<?php
/**
 * Runs when the plugin is deleted.
 *
 * Always removes scheduled jobs. Settings, the log table and capabilities are removed
 * only when "Delete all plugin data when uninstalling" is on. Nothing in Invoice Ninja
 * is touched. On multisite this runs for every site.
 *
 * @package InvoiceNinja
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 */
function invoiceninja_uninstall_site() {
	global $wpdb;

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'invoiceninja' );
	}
	wp_clear_scheduled_hook( 'invoiceninja_auto_refresh' );

	$settings = get_option( 'invoiceninja_settings', array() );
	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'invoiceninja_log' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'invoiceninja_' ) . '%' ) );
	// phpcs:enable

	foreach ( wp_roles()->role_objects as $role ) {
		$role->remove_cap( 'manage_invoiceninja' );
		$role->remove_cap( 'view_invoiceninja' );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $invoiceninja_site_id ) {
		switch_to_blog( $invoiceninja_site_id );
		invoiceninja_uninstall_site();
		restore_current_blog();
	}
} else {
	invoiceninja_uninstall_site();
}
