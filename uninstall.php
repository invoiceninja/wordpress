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

	// Action Scheduler isn't loaded while uninstalling, so remove our pending jobs from its tables.
	$invoiceninja_groups = $wpdb->prefix . 'actionscheduler_groups';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $invoiceninja_groups ) ) === $invoiceninja_groups ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$invoiceninja_group = (int) $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$invoiceninja_groups} WHERE slug = %s", 'invoiceninja' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $invoiceninja_group > 0 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE group_id = %d AND status IN ('pending', 'in-progress')", $invoiceninja_group ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
	wp_clear_scheduled_hook( 'invoiceninja_auto_refresh' );

	$settings = get_option( 'invoiceninja_settings', array() );
	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'invoiceninja_log' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name.
	// phpcs:enable
	// delete_option() keeps a persistent object cache in step.
	$invoiceninja_options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'invoiceninja_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $invoiceninja_options as $invoiceninja_option ) {
		delete_option( $invoiceninja_option );
	}

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
