<?php
/**
 * Customers and the customer portal (M3).
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Portal;

use InvoiceNinja\Module;
use InvoiceNinja\Plugin;
use InvoiceNinja\Rest\Routes;

/**
 * Verified emails, links between users and Invoice Ninja clients, the Account and Pay
 * Invoice blocks, the verified redirects, and the Users screens.
 */
final class PortalModule implements Module {

	public readonly Verification $verification;
	public readonly Links $links;
	public readonly Account $account;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {
		$this->verification = new Verification( $plugin );
		$this->links        = new Links( $plugin );
		$this->account      = new Account( $plugin, $this->verification, $this->links );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'invoiceninja_setting_defaults', array( $this, 'defaults' ) );
		$this->verification->register();
		$this->links->register();
		$this->account->register();
		( new ConfirmEmail( $this->plugin, $this->account ) )->register();
		( new Users( $this->plugin, $this->verification, $this->links ) )->register();
		add_action( 'invoiceninja_rest_routes', array( $this, 'routes' ) );
		add_filter( 'invoiceninja_diagnostics_checks', array( $this, 'checks' ) );
		add_action( 'invoiceninja_admin_assets', array( $this, 'adminAssets' ) );
	}

	/**
	 * Settings.
	 *
	 * @param array<string, mixed> $defaults Defaults.
	 * @return array<string, mixed>
	 */
	public function defaults( array $defaults ): array {
		return $defaults + array(
			'customers' => array(
				'sync'  => 'purchase',
				'roles' => 'customer,subscriber',
			),
		);
	}

	/**
	 * Admin routes.
	 *
	 * @param Routes $routes Routes.
	 */
	public function routes( Routes $routes ): void {
		$routes->add(
			'/customers/export',
			'POST',
			fn(): \WP_REST_Response => new \WP_REST_Response( array( 'queued' => $this->links->export() ) ),
			'manage'
		);
		$routes->add( '/customers/verify-role', 'POST', array( $this, 'verifyRole' ), 'manage_site' );
	}

	/**
	 * POST /customers/verify-role {role}: mark every user with a role verified.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function verifyRole( \WP_REST_Request $request ): \WP_REST_Response {
		$role = sanitize_key( (string) $request->get_param( 'role' ) );
		if ( '' === $role || ! get_role( $role ) ) {
			return Routes::fail( 'invalid_role', __( 'Choose a role.', 'invoiceninja' ), 400 );
		}
		$ids = get_users(
			array(
				'role'   => $role,
				'fields' => 'ID',
			)
		);
		foreach ( $ids as $id ) {
			$this->verification->mark( (int) $id, 'bulk' );
		}
		return new \WP_REST_Response( array( 'verified' => count( $ids ) ) );
	}

	/**
	 * Diagnostics: the portal password.
	 *
	 * @param array<int, array<string, string>> $checks Checks.
	 * @return array<int, array<string, string>>
	 */
	public function checks( array $checks ): array {
		$company = (array) ( $this->plugin->state->get()['company'] ?? array() );
		if ( ! empty( $company['portal_password'] ) ) {
			$checks[] = array(
				'id'     => 'portal_password',
				'label'  => __( 'Billing portal password', 'invoiceninja' ),
				'status' => 'warn',
				'detail' => __( 'Invoice Ninja asks customers for their portal password before the Billing portal opens. Links from invoices and payment emails still work.', 'invoiceninja' ),
				'action' => '',
			);
		}
		return $checks;
	}

	/**
	 * The Customers settings tab.
	 */
	public function adminAssets(): void {
		wp_enqueue_script( 'invoiceninja-portal-admin', $this->plugin->url() . 'assets/admin/portal.js', array( 'invoiceninja-admin' ), INVOICENINJA_VERSION, true );
		wp_set_script_translations( 'invoiceninja-portal-admin', 'invoiceninja' );
	}
}
