<?php
/**
 * Plugin Name:       Invoice Ninja
 * Plugin URI:        https://invoiceninja.com
 * Description:       Sell, bill and get paid from WordPress, with Invoice Ninja keeping the books.
 * Version:           2.0.0-alpha.1
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            Invoice Ninja
 * Author URI:        https://invoiceninja.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       invoiceninja
 *
 * @package InvoiceNinja
 */

// This file must stay parseable on old PHP so the version guard can explain itself.

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'INVOICENINJA_VERSION' ) ) {
	define( 'INVOICENINJA_VERSION', '2.0.0-alpha.1' );
}
if ( ! defined( 'INVOICENINJA_FILE' ) ) {
	define( 'INVOICENINJA_FILE', __FILE__ );
}

/**
 * Show why the plugin isn't running. The message is built when the notice renders,
 * because translations must not load this early.
 *
 * @param callable $message Returns the message.
 */
function invoiceninja_requirements_notice( $message ) {
	add_action(
		'admin_notices',
		function () use ( $message ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p><strong>Invoice Ninja:</strong> ' . esc_html( call_user_func( $message ) ) . '</p></div>';
			}
		}
	);
}

if ( version_compare( PHP_VERSION, '8.1', '<' ) || version_compare( isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '0', '6.7', '<' ) ) {
	invoiceninja_requirements_notice(
		function () {
			/* translators: 1: PHP version, 2: WordPress version */
			return sprintf( __( 'This version needs PHP 8.1+ and WordPress 6.7+. This site runs PHP %1$s and WordPress %2$s. Your settings are safe; update PHP or WordPress, or install version 1.0.16.', 'invoiceninja' ), PHP_VERSION, isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '?' );
		}
	);
	return;
}

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	invoiceninja_requirements_notice(
		function () {
			return __( 'The plugin files are incomplete (the vendor folder is missing). Download the release zip from WordPress.org and install it again.', 'invoiceninja' );
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

register_activation_hook(
	__FILE__,
	function () {
		update_option( 'invoiceninja_flush_rewrite', 1 );
		if ( \InvoiceNinja\Plugin::booted() ) {
			\InvoiceNinja\Plugin::instance()->installer->maybeUpgrade();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		\InvoiceNinja\Core\Queue::clear();
		wp_clear_scheduled_hook( 'invoiceninja_auto_refresh' );
		flush_rewrite_rules();
	}
);

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

\InvoiceNinja\Plugin::boot( __FILE__ );

// 1.x features keep running until 2.0 replaces them.
define(
	'INVOICENINJA_DEFAULT_PRODUCT_TEMPLATE',
	'<a href="$page_url" class="wp-invoiceninja">
  <h3 class="truncated">$product</h3>
  <div class="divider"></div>
  <h5 class="truncated">$description</h5>
  <h5><b>$price</b></h5>
  [invoiceninja_purchase product_id="$product_id"]
  $image
</a>'
);

define(
	'INVOICENINJA_DEFAULT_IMAGE_TEMPLATE',
	'<figure class="wp-block-post-featured-image">
  <img src="$image_url" alt="$product" decoding="async"/>
</figure>'
);

\InvoiceNinja\Init::start();
