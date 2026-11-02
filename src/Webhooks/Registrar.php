<?php
/**
 * Creates, checks and removes this site's webhooks in Invoice Ninja.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Webhooks;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Api\Paginator;
use InvoiceNinja\Connection\ConnectionState;
use InvoiceNinja\Connection\Credentials;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Secrets;
use InvoiceNinja\Plugin;

/**
 * One webhook per event, pointing at /wp-json/invoiceninja/v1/webhook?event=<id> with the
 * webhook secret in a header. A webhook counts as ours only when its target URL is exactly
 * one of ours: anything else in Invoice Ninja is never changed or deleted. A copied site
 * (staging) has other URLs, so it never touches the live site's webhooks.
 *
 * Creating webhooks needs an admin token (and a paid plan on hosted Invoice Ninja). When
 * that isn't allowed, or Invoice Ninja can't reach this site, the plugin polls instead.
 */
final class Registrar {

	public const OPTION = 'webhooks';

	public const HEADER = 'X-Invoiceninja-Secret';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * The URL Invoice Ninja calls for an event.
	 *
	 * @param int $event Event id.
	 */
	public static function target( int $event ): string {
		return add_query_arg( 'event', $event, rest_url( 'invoiceninja/v1/webhook' ) );
	}

	/**
	 * Saved state: mode (instant, polling, off), reason, detail, hooks (event => id, url), checked_at.
	 *
	 * @return array{mode: string, reason: string, detail: string, hooks: array<int, array{id: string, url: string}>, checked_at: int}
	 */
	public function state(): array {
		$saved = $this->plugin->options->get( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$hooks = array();
		foreach ( (array) ( $saved['hooks'] ?? array() ) as $event => $hook ) {
			if ( is_array( $hook ) && Events::known( (int) $event ) ) {
				$hooks[ (int) $event ] = array(
					'id'  => (string) ( $hook['id'] ?? '' ),
					'url' => (string) ( $hook['url'] ?? '' ),
				);
			}
		}
		return array(
			'mode'       => in_array( $saved['mode'] ?? '', array( 'instant', 'polling', 'off' ), true ) ? (string) $saved['mode'] : 'off',
			'reason'     => (string) ( $saved['reason'] ?? 'not_connected' ),
			'detail'     => (string) ( $saved['detail'] ?? '' ),
			'hooks'      => $hooks,
			'checked_at' => (int) ( $saved['checked_at'] ?? 0 ),
		);
	}

	/**
	 * Whether every event has a webhook pointing at this site's current address.
	 */
	public function complete(): bool {
		$state = $this->state();
		if ( 'instant' !== $state['mode'] ) {
			return false;
		}
		foreach ( Events::ids() as $event ) {
			if ( ( $state['hooks'][ $event ]['url'] ?? '' ) !== self::target( $event ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Save the state.
	 *
	 * @param string                                      $mode   Mode.
	 * @param string                                      $reason Reason code.
	 * @param array<int, array{id: string, url: string}>  $hooks  Hooks.
	 * @param string                                      $detail Technical detail.
	 */
	private function save( string $mode, string $reason, array $hooks, string $detail = '' ): void {
		$this->plugin->options->set(
			self::OPTION,
			array(
				'mode'       => $mode,
				'reason'     => $reason,
				'detail'     => $detail,
				'hooks'      => $hooks,
				'checked_at' => time(),
			)
		);
	}

	/**
	 * Create the webhooks that are missing and repair ours that changed. Safe to run any
	 * time: existing webhooks with our URL are adopted, so an unknown result (a timeout)
	 * never leaves duplicates for long.
	 *
	 * @return array{mode: string, reason: string, detail: string, hooks: array<int, array{id: string, url: string}>, checked_at: int}
	 * @throws ApiException On a temporary failure (the queue tries again).
	 */
	public function sync(): array {
		$p     = $this->plugin;
		$hooks = $this->state()['hooks'];
		if ( 'none' === $p->credentials->source() || ! $p->credentials->hasToken() ) {
			$this->save( 'off', 'not_connected', $hooks );
			return $this->state();
		}
		if ( ! $p->environment->writesAllowed() ) {
			$this->save( 'off', 'paused', $hooks );
			return $this->state();
		}
		$secret = $p->secrets->get( 'webhook_secret' );
		try {
			$client = $p->api();
			$found  = $this->ours( $client );
			$hooks  = array();
			$extra  = array();
			foreach ( Events::ids() as $event ) {
				$target = self::target( $event );
				$active = null;
				foreach ( $found[ $target ] ?? array() as $webhook ) {
					if ( null === $active && empty( $webhook['is_deleted'] ) && empty( $webhook['archived_at'] ) ) {
						$active = $webhook;
					} else {
						$extra[] = (string) $webhook['id']; // Duplicates, or ours that Invoice Ninja archived.
					}
				}
				$body = array(
					'target_url'  => $target,
					'event_id'    => (string) $event,
					'format'      => 'JSON',
					'rest_method' => 'post',
					'headers'     => array( self::HEADER => $secret ),
				);
				if ( null !== $active ) {
					$sent = is_array( $active['headers'] ?? null ) ? (string) ( $active['headers'][ self::HEADER ] ?? '' ) : '';
					if ( ! hash_equals( $secret, $sent ) || 'post' !== strtolower( (string) ( $active['rest_method'] ?? '' ) ) ) {
						$client->put( 'webhooks/' . rawurlencode( (string) $active['id'] ), $body, array(), 10.0 );
					}
					$hooks[ $event ] = array(
						'id'  => (string) $active['id'],
						'url' => $target,
					);
					continue;
				}
				$created         = $client->post( 'webhooks', $body, array(), 10.0 )->data();
				$hooks[ $event ] = array(
					'id'  => Events::cleanId( $created['id'] ?? '' ),
					'url' => $target,
				);
			}
			if ( $extra ) {
				try {
					$client->post(
						'webhooks/bulk',
						array(
							'action' => 'delete',
							'ids'    => array_values( array_unique( $extra ) ),
						),
						array(),
						10.0,
						true
					);
				} catch ( ApiException $ignored ) {
					// Only tidying up; the next check tries again.
					unset( $ignored );
				}
			}
		} catch ( ApiException $e ) {
			if ( in_array( $e->kind, array( ErrorKind::Forbidden, ErrorKind::NonAdmin, ErrorKind::NotFound ), true ) ) {
				$this->save( 'polling', 'not_allowed', $hooks, $e->technical );
				$p->logger->warning( 'webhooks', "Invoice Ninja doesn't allow this token to create webhooks, so changes are checked every 15 minutes instead.", array( 'technical' => $e->technical ) );
				return $this->state();
			}
			if ( ErrorKind::Validation === $e->kind ) {
				$this->save( 'polling', 'unreachable', $hooks, $e->technical . ' ' . (string) wp_json_encode( $e->errors ) );
				$p->logger->warning( 'webhooks', "Invoice Ninja refused this site's webhook address (it must be public), so changes are checked every 15 minutes instead.", array( 'errors' => $e->errors ) );
				return $this->state();
			}
			if ( $e->kind->retryable() ) {
				$this->save( 'polling', 'error', $hooks, $e->technical );
				throw $e;
			}
			$this->save( 'polling', 'error', $hooks, $e->kind->value . ': ' . $e->technical );
			$p->logger->warning( 'webhooks', 'Setting up webhooks failed: ' . $e->kind->title(), array( 'technical' => $e->technical ) );
			return $this->state();
		}
		$before = $this->state();
		$this->save( 'instant', '', $hooks );
		if ( 'instant' !== $before['mode'] || count( $before['hooks'] ) !== count( $hooks ) ) {
			$p->logger->info( 'webhooks', sprintf( 'Webhooks ready: %d events reach this site within seconds', count( $hooks ) ) );
		}
		return $this->state();
	}

	/**
	 * Our webhooks in Invoice Ninja, by target URL (active and archived).
	 *
	 * @param HttpClient $client Client.
	 * @return array<string, list<array<string, mixed>>>
	 * @throws ApiException When the list can't be read.
	 */
	private function ours( HttpClient $client ): array {
		$targets = array();
		foreach ( Events::ids() as $event ) {
			$targets[ self::target( $event ) ] = true;
		}
		$found = array();
		foreach ( Paginator::each( $client, 'webhooks', array(), 200, 20 ) as $webhook ) {
			$url = (string) ( $webhook['target_url'] ?? '' );
			if ( isset( $targets[ $url ] ) && '' !== Events::cleanId( $webhook['id'] ?? '' ) ) {
				$found[ $url ][] = $webhook;
			}
		}
		// Active first, so the one kept is a working one.
		foreach ( $found as &$list ) {
			usort( $list, static fn( array $a, array $b ): int => ( empty( $a['is_deleted'] ) && empty( $a['archived_at'] ) ? 0 : 1 ) <=> ( empty( $b['is_deleted'] ) && empty( $b['archived_at'] ) ? 0 : 1 ) );
		}
		return $found;
	}

	/**
	 * Delete our webhooks (disconnect, deactivation). Best effort, in one call.
	 *
	 * @param float $budget Seconds.
	 * @return bool Whether Invoice Ninja confirmed (or there was nothing to delete).
	 */
	public function deleteAll( float $budget = 8.0 ): bool {
		$p = $this->plugin;
		if ( 'none' === $p->credentials->source() || ! $p->credentials->hasToken() ) {
			return false;
		}
		try {
			$ok = self::deleteWith( $p->api(), $budget );
		} catch ( ApiException $e ) {
			$p->logger->warning( 'webhooks', "Couldn't remove this site's webhooks from Invoice Ninja: " . $e->kind->title(), array( 'technical' => $e->technical ) );
			return false;
		}
		$this->save( 'off', 'not_connected', array() );
		$p->logger->info( 'webhooks', 'Removed this site’s webhooks from Invoice Ninja' );
		return $ok;
	}

	/**
	 * Delete every webhook whose URL is one of ours.
	 *
	 * @param HttpClient $client Client.
	 * @param float      $budget Seconds.
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	private static function deleteWith( HttpClient $client, float $budget ): bool {
		$targets = array();
		foreach ( Events::ids() as $event ) {
			$targets[ self::target( $event ) ] = true;
		}
		$ids      = array();
		$response = $client->get(
			'webhooks',
			array(
				'per_page' => 500,
				'sort'     => 'id|asc',
			),
			$budget / 2
		);
		foreach ( $response->data() as $webhook ) {
			if ( is_array( $webhook ) && isset( $targets[ (string) ( $webhook['target_url'] ?? '' ) ] ) && empty( $webhook['is_deleted'] ) ) {
				$ids[] = Events::cleanId( $webhook['id'] ?? '' );
			}
		}
		$ids = array_values( array_filter( $ids ) );
		if ( ! $ids ) {
			return true;
		}
		$client->post(
			'webhooks/bulk',
			array(
				'action' => 'delete',
				'ids'    => $ids,
			),
			array(),
			$budget / 2,
			true
		);
		return true;
	}

	/**
	 * After a company switch: the old company's webhooks can't be reached with the new
	 * token, so rotate the secret (they get 401 from now on) and start over.
	 */
	public function forgetCompany(): void {
		$this->plugin->options->delete( 'webhook_secret' );
		$this->save( 'off', 'not_connected', array() );
	}

	/**
	 * Uninstall: delete our webhooks without booting the plugin. Time-boxed and silent.
	 */
	public static function uninstall(): void {
		try {
			$options     = new Options();
			$credentials = new Credentials( $options, new Secrets( $options ) );
			if ( 'none' === $credentials->source() || ! $credentials->hasToken() || null === $credentials->token() ) {
				return;
			}
			self::deleteWith( $credentials->client( ( new ConnectionState( $options ) )->allowsPrivate( $credentials->url() ) ), 6.0 );
		} catch ( \Throwable $ignored ) {
			// Best effort: Invoice Ninja archives webhooks that start returning 404 anyway.
			unset( $ignored );
		}
	}
}
