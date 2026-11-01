<?php
/**
 * Adds the plugin's checks to Tools → Site Health.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Connection;

use InvoiceNinja\Plugin;

/**
 * Direct tests only use stored state, so Site Health stays fast; the full checks with
 * network calls live in Status → Checks.
 */
final class SiteHealth {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Register tests.
	 *
	 * @param array<string, mixed> $tests Tests.
	 * @return array<string, mixed>
	 */
	public function tests( array $tests ): array {
		$tests['direct']['invoiceninja_connection'] = array(
			'label' => __( 'Invoice Ninja connection', 'invoiceninja' ),
			'test'  => array( $this, 'connection' ),
		);
		$tests['direct']['invoiceninja_orders']     = array(
			'label' => __( 'Invoice Ninja orders from this site', 'invoiceninja' ),
			'test'  => array( $this, 'orders' ),
		);
		return $tests;
	}

	/**
	 * Connection test.
	 *
	 * @return array<string, mixed>
	 */
	public function connection(): array {
		$state  = $this->plugin->state->get();
		$status = 'connected' === $state['status'] ? 'good' : ( 'none' === $this->plugin->credentials->source() ? 'recommended' : 'critical' );
		$label  = match ( $status ) {
			'good'        => __( 'Invoice Ninja is connected', 'invoiceninja' ),
			'recommended' => __( 'Invoice Ninja is not connected yet', 'invoiceninja' ),
			default       => __( 'The Invoice Ninja connection has a problem', 'invoiceninja' ),
		};
		return $this->result( 'invoiceninja_connection', $status, $label, __( 'Status → Checks explains the problem and how to fix it.', 'invoiceninja' ) );
	}

	/**
	 * Staging / clone test.
	 *
	 * @return array<string, mixed>
	 */
	public function orders(): array {
		if ( 'none' === $this->plugin->credentials->source() || $this->plugin->environment->writesAllowed() ) {
			return $this->result( 'invoiceninja_orders', 'good', __( 'Orders are allowed from this site', 'invoiceninja' ), '' );
		}
		return $this->result( 'invoiceninja_orders', 'recommended', __( 'Invoice Ninja orders are paused on this site', 'invoiceninja' ), __( 'This looks like a staging copy or the site moved. Allow orders in Invoice Ninja → Status → Tools when this is your live site.', 'invoiceninja' ) );
	}

	/**
	 * Build a Site Health result.
	 *
	 * @param string $test        Test id.
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Label.
	 * @param string $description Description.
	 * @return array<string, mixed>
	 */
	private function result( string $test, string $status, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Invoice Ninja', 'invoiceninja' ),
				'color' => 'good' === $status ? 'blue' : 'orange',
			),
			'description' => '' !== $description ? '<p>' . esc_html( $description ) . '</p>' : '',
			'actions'     => '<p><a href="' . esc_url( admin_url( 'admin.php?page=invoiceninja-status' ) ) . '">' . esc_html__( 'Open Status', 'invoiceninja' ) . '</a></p>',
			'test'        => $test,
		);
	}
}
