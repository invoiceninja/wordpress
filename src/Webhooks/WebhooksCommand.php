<?php
/**
 * `wp invoiceninja webhooks`.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Webhooks;

use InvoiceNinja\Api\ApiException;

/**
 * Show, create or remove this site's Invoice Ninja webhooks.
 */
final class WebhooksCommand {

	/**
	 * Constructor.
	 *
	 * @param WebhooksModule $module Module.
	 */
	public function __construct( private WebhooksModule $module ) {}

	/**
	 * Show whether webhooks are registered.
	 *
	 * @subcommand status
	 */
	public function status(): void {
		$s = $this->module->summary();
		\WP_CLI::line( sprintf( '%s: %d of %d events. %s', $s['mode'], $s['registered'], $s['expected'], $s['message'] ) );
	}

	/**
	 * Create missing webhooks and repair ours.
	 *
	 * @subcommand register
	 */
	public function register(): void {
		try {
			$state = $this->module->registrar->sync();
		} catch ( ApiException $e ) {
			\WP_CLI::error( $e->kind->title() . '. ' . $e->kind->fix() );
			return;
		}
		$this->status();
		if ( 'instant' !== $state['mode'] ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Delete this site's webhooks from Invoice Ninja (others are never touched).
	 *
	 * @subcommand delete
	 */
	public function delete(): void {
		if ( ! $this->module->registrar->deleteAll() ) {
			\WP_CLI::error( "Couldn't remove the webhooks. See Status → Activity." );
		}
		\WP_CLI::success( 'Removed.' );
	}
}
