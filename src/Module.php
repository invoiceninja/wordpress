<?php
/**
 * A feature module (portal, forms, subscriptions, webhooks, WooCommerce).
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja;

/**
 * Modules are created with the Plugin and register their own hooks, REST routes (through
 * the invoiceninja_rest_routes action), settings (invoiceninja_setting_defaults filter),
 * tables (invoiceninja_create_tables), daily work (invoiceninja_daily_tasks), checks
 * (invoiceninja_diagnostics_checks) and admin tabs (invoiceninja_admin_assets).
 */
interface Module {

	/**
	 * Register hooks. Called once per request.
	 */
	public function register(): void;
}
