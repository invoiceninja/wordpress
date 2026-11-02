<?php
/**
 * Orders placed through the plugin's own checkout.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Commerce;

/**
 * Stored in `{prefix}invoiceninja_orders` (not posts): a unique order number shown
 * everywhere, a unique token so a double submit returns the same order, the buyer's
 * details, line items, and the Invoice Ninja client and invoice once created.
 *
 * Statuses: queued (saved, not yet in Invoice Ninja), awaiting_payment, paid,
 * cancelled, attention (needs an admin).
 */
final class Orders {

	public const TABLE = 'invoiceninja_orders';

	public const STATUSES = array( 'queued', 'placing', 'awaiting_payment', 'paid', 'cancelled', 'attention' );

	/**
	 * Full table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Table definition for dbDelta.
	 */
	public static function schema(): string {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			number varchar(32) NOT NULL,
			token char(32) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			email varchar(190) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			customer text NULL,
			items longtext NOT NULL,
			currency varchar(3) NOT NULL DEFAULT '',
			total decimal(15,4) NOT NULL DEFAULT 0,
			client_id varchar(32) NOT NULL DEFAULT '',
			invoice_id varchar(32) NOT NULL DEFAULT '',
			invoice_number varchar(64) NOT NULL DEFAULT '',
			pay_link text NULL,
			status_key char(64) NOT NULL DEFAULT '',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			paid_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY number (number),
			UNIQUE KEY token (token),
			KEY status_created (status, created_at),
			KEY email (email),
			KEY invoice (invoice_id)
		) {$charset};";
	}

	/**
	 * Create an order. Returns the existing order when the token was already used.
	 *
	 * @param array<string, mixed> $data number, token, email, user_id, customer, items, currency, total, status_key.
	 * @return array<string, mixed> The stored order.
	 * @throws \RuntimeException When the order can't be saved.
	 */
	public function create( array $data ): array {
		$existing = $this->findBy( 'token', (string) $data['token'] );
		if ( $existing ) {
			return $existing;
		}
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			self::table(),
			array(
				'number'     => (string) $data['number'],
				'token'      => (string) $data['token'],
				'status'     => 'queued',
				'email'      => strtolower( (string) $data['email'] ),
				'user_id'    => (int) ( $data['user_id'] ?? 0 ),
				'customer'   => (string) wp_json_encode( $data['customer'] ?? array() ),
				'items'      => (string) wp_json_encode( $data['items'] ?? array() ),
				'currency'   => (string) ( $data['currency'] ?? '' ),
				'total'      => (float) ( $data['total'] ?? 0 ),
				'status_key' => (string) ( $data['status_key'] ?? '' ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		if ( ! $ok ) {
			// A concurrent request with the same token won the insert.
			$existing = $this->findBy( 'token', (string) $data['token'] );
			if ( $existing ) {
				return $existing;
			}
			throw new \RuntimeException( 'The order could not be saved' );
		}
		return (array) $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Update fields of an order.
	 *
	 * @param int                  $id     Order id.
	 * @param array<string, mixed> $fields Fields.
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		$allowed = array( 'status', 'client_id', 'invoice_id', 'invoice_number', 'pay_link', 'attempts', 'note', 'paid_at', 'customer' );
		$fields  = array_intersect_key( $fields, array_flip( $allowed ) );
		if ( isset( $fields['status'] ) && ! in_array( $fields['status'], self::STATUSES, true ) ) {
			unset( $fields['status'] );
		}
		if ( isset( $fields['customer'] ) && is_array( $fields['customer'] ) ) {
			$fields['customer'] = (string) wp_json_encode( $fields['customer'] );
		}
		if ( ! $fields ) {
			return;
		}
		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Move an order to a new status only if it's still in one of the expected ones, so two
	 * requests (an admin and a background job) can't both act on the same order.
	 *
	 * @param int                  $id     Order id.
	 * @param list<string>         $from   Statuses it may be in now.
	 * @param string               $to     New status.
	 * @param array<string, mixed> $fields Other fields to save when it moved.
	 * @return bool Whether it moved.
	 */
	public function transition( int $id, array $from, string $to, array $fields = array() ): bool {
		global $wpdb;
		$from = array_values( array_intersect( $from, self::STATUSES ) );
		if ( ! $from || ! in_array( $to, self::STATUSES, true ) ) {
			return false;
		}
		$table = self::table();
		$in    = implode( ', ', array_fill( 0, count( $from ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND status IN ({$in})", array_merge( array( $to, gmdate( 'Y-m-d H:i:s' ), $id ), $from ) ) );
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return false;
		}
		unset( $fields['status'] );
		if ( $fields ) {
			$this->update( $id, $fields );
		}
		return true;
	}

	/**
	 * Claim a queued order for placing, so only one request or job creates its invoice.
	 * A claim older than five minutes is considered abandoned.
	 *
	 * @param int $id Order id.
	 */
	public function claim( int $id ): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'placing', updated_at = %s WHERE id = %d AND ( status = 'queued' OR ( status = 'placing' AND updated_at < %s ) )", gmdate( 'Y-m-d H:i:s' ), $id, gmdate( 'Y-m-d H:i:s', time() - 300 ) ) );
		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Find by id.
	 *
	 * @param int $id Id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		return $this->findBy( 'id', $id );
	}

	/**
	 * Find by a unique column.
	 *
	 * @param string     $column id, number, token or invoice_id.
	 * @param int|string $value  Value.
	 * @return array<string, mixed>|null
	 */
	public function findBy( string $column, int|string $value ): ?array {
		if ( ! in_array( $column, array( 'id', 'number', 'token', 'invoice_id' ), true ) ) {
			return null;
		}
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s LIMIT 1", (string) $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Orders in a status placed before a time, oldest first.
	 *
	 * @param string $status Status.
	 * @param int    $before Unix time.
	 * @param int    $limit  Maximum.
	 * @return list<array<string, mixed>>
	 */
	public function olderThan( string $status, int $before, int $limit ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND created_at < %s AND note <> 'kept_unpaid' ORDER BY id ASC LIMIT %d", $status, gmdate( 'Y-m-d H:i:s', $before ), $limit ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * List orders for the admin screen, newest first.
	 *
	 * @param array{status?: string, search?: string, page?: int, per_page?: int} $filters Filters.
	 * @return array{items: list<array<string, mixed>>, total: int, counts: array<string, int>}
	 */
	public function list( array $filters = array() ): array {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();
		if ( 'queued' === ( $filters['status'] ?? '' ) ) {
			$where[] = "status IN ('queued', 'placing')"; // Placing is a short-lived claim on a queued order.
		} elseif ( ! empty( $filters['status'] ) && in_array( $filters['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[]  = '(number LIKE %s OR email LIKE %s OR invoice_number LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$per_page = max( 1, min( 100, (int) ( $filters['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$sql      = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB -- table name is ours; values are prepared.
		$total  = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql}", $params ) : "SELECT COUNT(*) FROM {$table}" );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		// phpcs:enable
		return array(
			'items'  => array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() ),
			'total'  => $total,
			'counts' => array_map( 'intval', array_column( is_array( $counts ) ? $counts : array(), 'n', 'status' ) ),
		);
	}

	/**
	 * An order's note as a sentence for admins. Notes are stored as codes so they show in
	 * the admin's language.
	 *
	 * @param string $note Stored note.
	 */
	public static function noteText( string $note ): string {
		if ( str_starts_with( $note, 'error:' ) ) {
			$kind = \InvoiceNinja\Api\ErrorKind::tryFrom( substr( $note, 6 ) );
			return $kind ? $kind->title() . '. ' . $kind->fix() : '';
		}
		$notes = array(
			'paused'        => __( 'Orders are paused on this site, so it waits until you allow them.', 'invoiceninja' ),
			'gave_up'       => __( "Invoice Ninja couldn't be reached for several hours. Retry when it's back.", 'invoiceninja' ),
			'no_invitation' => __( "Invoice Ninja didn't return a payment link for this buyer. Send the invoice from Invoice Ninja.", 'invoiceninja' ),
			'kept_unpaid'   => __( 'Left open by the unpaid-orders cleanup: its invoice has a payment or credit.', 'invoiceninja' ),
			'check_invoice' => __( "Cancelled, but Invoice Ninja couldn't be checked for an invoice. Look there for this order number as the PO number.", 'invoiceninja' ),
		);
		return $notes[ $note ] ?? $note;
	}

	/**
	 * Decode JSON columns and cast numbers.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['id']       = (int) $row['id'];
		$row['user_id']  = (int) $row['user_id'];
		$row['total']    = (float) $row['total'];
		$row['attempts'] = (int) $row['attempts'];
		$row['items']    = json_decode( (string) $row['items'], true ) ?: array();
		$row['customer'] = json_decode( (string) $row['customer'], true ) ?: array();
		return $row;
	}
}
