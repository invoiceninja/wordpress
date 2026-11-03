<?php
/**
 * Runs when the plugin is deleted.
 *
 * Always removes scheduled jobs, puts back the subscription settings this plugin changed
 * in Invoice Ninja and removes the webhooks it created there (nothing else in Invoice
 * Ninja is touched). Settings, tables, synced products, quote requests, links and
 * capabilities are removed only when "Delete all plugin data when uninstalling" is on.
 * On multisite this runs for every site.
 *
 * @package InvoiceNinja
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 */
function invoiceninja_uninstall_site() {
	global $wpdb;

	// Undo our changes in Invoice Ninja (each has its own short time budget and fails quietly).
	if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
		require_once __DIR__ . '/vendor/autoload.php';
		foreach ( array( array( 'InvoiceNinja\\Subscriptions\\SubscriptionsModule', 'uninstall' ), array( 'InvoiceNinja\\Webhooks\\Registrar', 'uninstall' ) ) as $invoiceninja_undo ) {
			if ( is_callable( $invoiceninja_undo ) ) {
				try {
					call_user_func( $invoiceninja_undo );
				} catch ( \Throwable $invoiceninja_error ) {
					unset( $invoiceninja_error ); // Uninstall must finish regardless.
				}
			}
		}
	}

	// Action Scheduler isn't loaded while uninstalling, so remove our pending jobs from its tables.
	$invoiceninja_groups = $wpdb->prefix . 'actionscheduler_groups';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $invoiceninja_groups ) ) === $invoiceninja_groups ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$invoiceninja_group = (int) $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$invoiceninja_groups} WHERE slug = %s", 'invoiceninja' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $invoiceninja_group > 0 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE group_id = %d AND status IN ('pending', 'in-progress')", $invoiceninja_group ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
	wp_clear_scheduled_hook( 'invoiceninja_auto_refresh' );
	delete_option( 'invoiceninja_parked_jobs' ); // Jobs set aside at deactivation.

	$settings = get_option( 'invoiceninja_settings', array() );
	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	foreach ( array( 'invoiceninja_log', 'invoiceninja_orders', 'invoiceninja_map' ) as $invoiceninja_table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $invoiceninja_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table names.
	}
	// phpcs:enable
	// Synced products and quote requests (Invoice Ninja keeps the originals).
	$invoiceninja_posts = get_posts(
		array(
			'post_type'   => array( 'invoiceninja_product', 'invoiceninja_lead' ),
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	foreach ( $invoiceninja_posts as $invoiceninja_post ) {
		wp_delete_post( (int) $invoiceninja_post, true );
	}
	// Our meta on WooCommerce orders and products, and on users (links and verification).
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_invoiceninja_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_invoiceninja_' ) . '%' ) );
	$invoiceninja_hpos = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $invoiceninja_hpos ) ) === $invoiceninja_hpos ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$invoiceninja_hpos} WHERE meta_key LIKE %s", $wpdb->esc_like( '_invoiceninja_' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our check above.
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_invoiceninja_' ) . '%', $wpdb->esc_like( '_transient_timeout_invoiceninja_' ) . '%' ) );
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
