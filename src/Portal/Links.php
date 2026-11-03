<?php
/**
 * Links between WordPress users and Invoice Ninja clients.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Portal;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Plugin;

/**
 * A link is the client and the user's own contact in it (user meta). By default users are
 * linked when they buy or ask for a quote; Settings > Customers can also create clients
 * when users register, for chosen roles, and export existing users in the background.
 *
 * A link is only trusted for the email it was made for: a keyed hash of that email is
 * stored with it, a link whose email no longer matches the account is ignored, and it is
 * cleared when the email changes. Before customer data is shown or a portal link opened,
 * confirmed() also checks with Invoice Ninja that the linked contact still uses the
 * account's email. Everything that links a user goes through link().
 */
final class Links {

	public const CLIENT  = '_invoiceninja_client_id';
	public const CONTACT = '_invoiceninja_contact_id';
	public const EMAIL   = '_invoiceninja_link_email_hash';

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
	 * The user's client and contact ids, when the link was made for their current email.
	 *
	 * @param int $user_id User id.
	 * @return array{client_id: string, contact_id: string}|null
	 */
	public function get( int $user_id ): ?array {
		$client = (string) get_user_meta( $user_id, self::CLIENT, true );
		if ( '' === $client || ! $this->boundToEmail( $user_id ) ) {
			return null;
		}
		return array(
			'client_id'  => $client,
			'contact_id' => (string) get_user_meta( $user_id, self::CONTACT, true ),
		);
	}

	/**
	 * Link a user to the client found or made for $email, when that is the account's own
	 * email and the user has no trusted link yet.
	 *
	 * @param int    $user_id    User id.
	 * @param string $email      The email the client was found or made for.
	 * @param string $client_id  Client id.
	 * @param string $contact_id Contact id.
	 * @return bool Whether the link was saved.
	 */
	public function link( int $user_id, string $email, string $client_id, string $contact_id ): bool {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user || '' === trim( $client_id ) || '' === self::normal( $email ) || self::normal( (string) $user->user_email ) !== self::normal( $email ) ) {
			return false;
		}
		if ( null !== $this->get( $user_id ) ) {
			return false;
		}
		$this->clear( $user_id );
		$this->set( $user_id, $client_id, $contact_id );
		return true;
	}

	/**
	 * Remove a link.
	 *
	 * @param int $user_id User id.
	 */
	public function clear( int $user_id ): void {
		delete_user_meta( $user_id, self::CLIENT );
		delete_user_meta( $user_id, self::CONTACT );
		delete_user_meta( $user_id, self::EMAIL );
		foreach ( Account::TABS as $tab ) {
			delete_transient( 'invoiceninja_acct_' . $user_id . '_' . $tab );
		}
	}

	/**
	 * Remove a link that wasn't made for the user's current email.
	 *
	 * @param int $user_id User id.
	 * @return bool Whether a link was removed.
	 */
	public function clearStale( int $user_id ): bool {
		if ( '' === (string) get_user_meta( $user_id, self::CLIENT, true ) || $this->boundToEmail( $user_id ) ) {
			return false;
		}
		$this->clear( $user_id );
		return true;
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
			$this->clearStale( $user_id );
			return $this->get( $user_id );
		}
		$this->clear( $user_id );
		$this->set( $user_id, $found['client_id'], $found['contact_id'] );
		return $this->get( $user_id );
	}

	/**
	 * The link, checked with Invoice Ninja: the linked contact must still exist and use the
	 * account's current email. Otherwise the link is dropped and the client looked up again
	 * by email. Use this before showing customer data or opening a portal link.
	 *
	 * @param int $user_id User id.
	 * @return array{client_id: string, contact_id: string, contact: array<string, mixed>}|null
	 * @throws ApiException When Invoice Ninja can't be asked.
	 */
	public function confirmed( int $user_id ): ?array {
		$link = $this->discover( $user_id );
		$user = get_userdata( $user_id );
		if ( null === $link || '' === $link['contact_id'] || ! $user ) {
			return null;
		}
		$email = (string) $user->user_email;
		$api   = $this->plugin->api();
		try {
			$client = $api->get( 'clients/' . rawurlencode( $link['client_id'] ), array(), 5.0 )->data();
		} catch ( ApiException $e ) {
			if ( ErrorKind::NotFound !== $e->kind ) {
				throw $e;
			}
			$client = array();
		}
		$contact = self::matchingContact( $client, $link['contact_id'], $email );
		if ( null !== $contact ) {
			return $link + array( 'contact' => $contact );
		}
		// The client was deleted or the contact's email changed: look again by email.
		$this->clear( $user_id );
		$found = $this->plugin->clients->find( $api, $email );
		if ( null === $found ) {
			return null;
		}
		$contact = self::matchingContact( $found['client'], $found['contact_id'], $email );
		if ( null === $contact ) {
			return null;
		}
		$this->set( $user_id, $found['client_id'], $found['contact_id'] );
		return array(
			'client_id'  => $found['client_id'],
			'contact_id' => $found['contact_id'],
			'contact'    => $contact,
		);
	}

	/**
	 * The contact with this id in a client record, when it uses this email (and neither
	 * the client nor the contact is deleted).
	 *
	 * @param array<string, mixed> $client     Client record from Invoice Ninja.
	 * @param string               $contact_id Contact id.
	 * @param string               $email      Email.
	 * @return array<string, mixed>|null
	 */
	public static function matchingContact( array $client, string $contact_id, string $email ): ?array {
		if ( '' === $contact_id || '' === self::normal( $email ) || ! empty( $client['is_deleted'] ) ) {
			return null;
		}
		foreach ( (array) ( $client['contacts'] ?? array() ) as $contact ) {
			if ( is_array( $contact ) && (string) ( $contact['id'] ?? '' ) === $contact_id && empty( $contact['is_deleted'] )
				&& self::normal( (string) ( $contact['email'] ?? '' ) ) === self::normal( $email ) ) {
				return $contact;
			}
		}
		return null;
	}

	/**
	 * A logged-in buyer's order links their account (when the order used their own email).
	 *
	 * @param array<string, mixed> $order Order.
	 */
	public function fromOrder( array $order ): void {
		$this->link( (int) ( $order['user_id'] ?? 0 ), (string) ( $order['email'] ?? '' ), (string) ( $order['client_id'] ?? '' ), (string) ( $order['customer']['contact_id'] ?? '' ) );
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
		$email = (string) $user->user_email;
		$found = $this->plugin->clients->findOrCreate(
			$this->plugin->api(),
			$email,
			array(
				'first_name' => (string) $user->first_name,
				'last_name'  => (string) $user->last_name,
				'company'    => (string) get_user_meta( $user_id, 'billing_company', true ),
				'phone'      => (string) get_user_meta( $user_id, 'billing_phone', true ),
			)
		);
		$this->link( $user_id, $email, $found['client_id'], $found['contact_id'] );
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
	 * Save a link for the user's current email. Callers have checked the client was found
	 * or made for that email.
	 *
	 * @param int    $user_id    User id.
	 * @param string $client_id  Client id.
	 * @param string $contact_id Contact id.
	 */
	private function set( int $user_id, string $client_id, string $contact_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		update_user_meta( $user_id, self::CLIENT, sanitize_text_field( $client_id ) );
		update_user_meta( $user_id, self::CONTACT, sanitize_text_field( $contact_id ) );
		update_user_meta( $user_id, self::EMAIL, $this->hash( (string) $user->user_email ) );
	}

	/**
	 * Whether the stored link was made for the user's current email.
	 *
	 * @param int $user_id User id.
	 */
	private function boundToEmail( int $user_id ): bool {
		$user   = $user_id > 0 ? get_userdata( $user_id ) : false;
		$stored = (string) get_user_meta( $user_id, self::EMAIL, true );
		return $user && '' !== $stored && hash_equals( $this->hash( (string) $user->user_email ), $stored );
	}

	/**
	 * Keyed hash of an email (a different context from the verification hash).
	 *
	 * @param string $email Email.
	 */
	private function hash( string $email ): string {
		return hash_hmac( 'sha256', 'link|' . self::normal( $email ), $this->plugin->secrets->get( 'secret' ) );
	}

	/**
	 * An email compared case-insensitively, without surrounding spaces.
	 *
	 * @param string $email Email.
	 */
	private static function normal( string $email ): string {
		return strtolower( trim( $email ) );
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
