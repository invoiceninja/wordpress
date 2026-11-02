<?php
/**
 * Links between WordPress users and Invoice Ninja clients.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Portal;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Plugin;

/**
 * A link is the client and the user's own contact in it (user meta). By default users are
 * linked when they buy or ask for a quote; Settings > Customers can also create clients
 * when users register, for chosen roles, and export existing users in the background.
 */
final class Links {

	public const CLIENT  = '_invoiceninja_client_id';
	public const CONTACT = '_invoiceninja_contact_id';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'invoiceninja_order_placed', array( $this, 'fromOrder' ) );
		add_action( 'user_register', array( $this, 'registered' ) );
		$this->plugin->queue->handle( 'invoiceninja_link_user', array( $this, 'linkJob' ) );
		$this->plugin->queue->handle( 'invoiceninja_export_users', array( $this, 'exportJob' ) );
	}

	/**
	 * The user's client and contact ids.
	 *
	 * @param int $user_id User id.
	 * @return array{client_id: string, contact_id: string}|null
	 */
	public function get( int $user_id ): ?array {
		$client = (string) get_user_meta( $user_id, self::CLIENT, true );
		return '' === $client ? null : array(
			'client_id'  => $client,
			'contact_id' => (string) get_user_meta( $user_id, self::CONTACT, true ),
		);
	}

	/**
	 * Save a link.
	 *
	 * @param int    $user_id    User id.
	 * @param string $client_id  Client id.
	 * @param string $contact_id Contact id.
	 */
	public function set( int $user_id, string $client_id, string $contact_id ): void {
		update_user_meta( $user_id, self::CLIENT, sanitize_text_field( $client_id ) );
		update_user_meta( $user_id, self::CONTACT, sanitize_text_field( $contact_id ) );
	}

	/**
	 * Remove a link.
	 *
	 * @param int $user_id User id.
	 */
	public function clear( int $user_id ): void {
		delete_user_meta( $user_id, self::CLIENT );
		delete_user_meta( $user_id, self::CONTACT );
	}

	/**
	 * The link for a verified user, looking the client up by email the first time (never
	 * creating one).
	 *
	 * @param int $user_id User id.
	 * @return array{client_id: string, contact_id: string}|null
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	public function discover( int $user_id ): ?array {
		$link = $this->get( $user_id );
		if ( null !== $link && '' !== $link['contact_id'] ) {
			return $link;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}
		$found = $this->plugin->clients->find( $this->plugin->api(), (string) $user->user_email );
		if ( null === $found ) {
			return null;
		}
		$this->set( $user_id, $found['client_id'], $found['contact_id'] );
		return $this->get( $user_id );
	}

	/**
	 * A logged-in buyer's order links their account (when the order used their own email).
	 *
	 * @param array<string, mixed> $order Order.
	 */
	public function fromOrder( array $order ): void {
		$user_id = (int) ( $order['user_id'] ?? 0 );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user || strtolower( (string) $user->user_email ) !== strtolower( (string) $order['email'] ) || '' === (string) $order['client_id'] ) {
			return;
		}
		if ( null === $this->get( $user_id ) ) {
			$this->set( $user_id, (string) $order['client_id'], (string) ( $order['customer']['contact_id'] ?? '' ) );
		}
	}

	/**
	 * When customers sync on registration, queue creating the client.
	 *
	 * @param int $user_id User id.
	 */
	public function registered( int $user_id ): void {
		if ( 'register' === $this->plugin->options->setting( 'customers.sync' ) && $this->roleIncluded( $user_id ) ) {
			$this->plugin->queue->push( 'invoiceninja_link_user', array( 'user_id' => $user_id ) );
		}
	}

	/**
	 * Background job: find or create the user's client and link it.
	 *
	 * @param array<string, mixed> $payload {user_id}.
	 * @throws ApiException To let the queue retry.
	 */
	public function linkJob( array $payload ): void {
		$user_id = (int) ( $payload['user_id'] ?? 0 );
		$user    = get_userdata( $user_id );
		if ( ! $user || null !== $this->get( $user_id ) || ! $this->plugin->environment->writesAllowed() ) {
			return;
		}
		$found = $this->plugin->clients->findOrCreate(
			$this->plugin->api(),
			(string) $user->user_email,
			array(
				'first_name' => (string) $user->first_name,
				'last_name'  => (string) $user->last_name,
				'company'    => (string) get_user_meta( $user_id, 'billing_company', true ),
				'phone'      => (string) get_user_meta( $user_id, 'billing_phone', true ),
			)
		);
		$this->set( $user_id, $found['client_id'], $found['contact_id'] );
	}

	/**
	 * Queue exporting users in the included roles, 50 at a time.
	 *
	 * @return int Users found.
	 */
	public function export(): int {
		$total = count( $this->exportable( array(), 10000 ) );
		if ( $total > 0 ) {
			$this->plugin->queue->push( 'invoiceninja_export_users', array( 'skip' => array() ) );
		}
		return $total;
	}

	/**
	 * Background job: one batch of the export.
	 *
	 * @param array<string, mixed> $payload {skip}: users that failed, left out of later batches.
	 */
	public function exportJob( array $payload ): void {
		// Linked users drop out of the query, so each batch starts from the top, minus failures.
		$skip  = array_map( 'intval', (array) ( $payload['skip'] ?? array() ) );
		$users = $this->exportable( $skip, 50 );
		foreach ( $users as $user_id ) {
			try {
				$this->linkJob( array( 'user_id' => $user_id ) );
			} catch ( ApiException $e ) {
				$this->plugin->logger->log( \InvoiceNinja\Core\LogLevel::Warning, 'customers', sprintf( "Couldn't export user %d: %s", $user_id, $e->kind->title() ) );
				if ( $e->kind->breaksConnection() ) {
					return;
				}
			}
			if ( null === $this->get( $user_id ) ) {
				$skip[] = $user_id;
			}
		}
		if ( 50 === count( $users ) && count( $skip ) < 5000 ) {
			$this->plugin->queue->push( 'invoiceninja_export_users', array( 'skip' => $skip ), 30 );
		} else {
			$this->plugin->logger->info( 'customers', 'Customer export finished' );
		}
	}

	/**
	 * Users in the included roles without a link.
	 *
	 * @param list<int> $skip  Users to leave out (they failed).
	 * @param int       $limit Limit.
	 * @return list<int>
	 */
	private function exportable( array $skip, int $limit ): array {
		$roles = $this->roles();
		$query = new \WP_User_Query(
			array(
				'role__in'    => $roles ? $roles : array( 'customer', 'subscriber' ),
				'fields'      => 'ID',
				'number'      => $limit,
				'exclude'     => $skip,
				'orderby'     => 'ID',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- background export only.
					array(
						'key'     => self::CLIENT,
						'compare' => 'NOT EXISTS',
					),
				),
				'count_total' => false,
			)
		);
		return array_map( 'intval', (array) $query->get_results() );
	}

	/**
	 * Roles whose users become clients (Settings > Customers).
	 *
	 * @return list<string>
	 */
	private function roles(): array {
		return array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $this->plugin->options->setting( 'customers.roles' ) ) ) ) );
	}

	/**
	 * Whether the user has an included role.
	 *
	 * @param int $user_id User id.
	 */
	private function roleIncluded( int $user_id ): bool {
		$user  = get_userdata( $user_id );
		$roles = $this->roles();
		return $user && ( ! $roles || array_intersect( $roles, (array) $user->roles ) );
	}
}
