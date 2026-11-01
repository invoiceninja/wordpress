<?php
/**
 * What we last learned about the connection.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Connection;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Core\Options;

/**
 * Stored in `invoiceninja_connection`. Holds only the company allowlist, never secrets.
 */
final class ConnectionState {

	public const OPTION = 'connection';

	/**
	 * Constructor.
	 *
	 * @param Options $options Options.
	 */
	public function __construct( private Options $options ) {}

	/**
	 * The full state with defaults.
	 *
	 * @return array{status: string, company: array<string, mixed>, version: string, admin: bool, webhooks: bool, gateways: list<string>, allow_private: bool, checked_at: int, connected_at: int, error: array{kind: string, technical: string, at: int}|null}
	 */
	public function get(): array {
		$state = $this->options->get( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		return array(
			'status'        => (string) ( $state['status'] ?? 'disconnected' ),
			'company'       => is_array( $state['company'] ?? null ) ? $state['company'] : array(),
			'version'       => (string) ( $state['version'] ?? '' ),
			'admin'         => (bool) ( $state['admin'] ?? false ),
			'webhooks'      => (bool) ( $state['webhooks'] ?? false ),
			'gateways'      => array_values( array_map( 'strval', (array) ( $state['gateways'] ?? array() ) ) ),
			'allow_private' => (bool) ( $state['allow_private'] ?? false ),
			'checked_at'    => (int) ( $state['checked_at'] ?? 0 ),
			'connected_at'  => (int) ( $state['connected_at'] ?? 0 ),
			'error'         => is_array( $state['error'] ?? null ) ? array(
				'kind'      => (string) ( $state['error']['kind'] ?? '' ),
				'technical' => (string) ( $state['error']['technical'] ?? '' ),
				'at'        => (int) ( $state['error']['at'] ?? 0 ),
			) : null,
		);
	}

	/**
	 * Whether we're connected and the last check didn't break the connection.
	 */
	public function connected(): bool {
		return 'connected' === $this->get()['status'];
	}

	/**
	 * Record a successful connection or refresh.
	 *
	 * @param array<string, mixed> $company  Company summary.
	 * @param string               $version  Invoice Ninja version.
	 * @param bool                 $admin    Admin token.
	 * @param bool                 $webhooks Webhooks allowed.
	 * @param list<string>         $gateways Gateway labels.
	 * @param bool                 $allow_private Private host allowed.
	 */
	public function markConnected( array $company, string $version, bool $admin, bool $webhooks, array $gateways, bool $allow_private ): void {
		$previous = $this->get();
		$this->options->set(
			self::OPTION,
			array(
				'status'        => 'connected',
				'company'       => $company,
				'version'       => $version,
				'admin'         => $admin,
				'webhooks'      => $webhooks,
				'gateways'      => $gateways,
				'allow_private' => $allow_private,
				'checked_at'    => time(),
				'connected_at'  => 'connected' === $previous['status'] && $previous['connected_at'] ? $previous['connected_at'] : time(),
				'error'         => null,
			),
			true
		);
	}

	/**
	 * Record a failure. Errors that break the connection change the status.
	 *
	 * @param ApiException $e Failure.
	 */
	public function markError( ApiException $e ): void {
		$state          = $this->get();
		$state['error'] = array(
			'kind'      => $e->kind->value,
			'technical' => $e->technical,
			'at'        => time(),
		);
		if ( $e->kind->breaksConnection() && 'disconnected' !== $state['status'] ) {
			$state['status'] = 'error';
		}
		$state['checked_at'] = time();
		$this->options->set( self::OPTION, $state, true );
	}

	/**
	 * Forget the connection (the company summary is kept to detect a company switch).
	 */
	public function markDisconnected(): void {
		$state           = $this->get();
		$state['status'] = 'disconnected';
		$state['error']  = null;
		$this->options->set( self::OPTION, $state, true );
	}
}
