<?php
/**
 * Quote requests saved on this site (the invoiceninja_lead post type).
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Forms;

/**
 * Every submission is saved here first, so nothing is lost while Invoice Ninja is down.
 * The post type is private: not public, not queryable, not in REST and without admin UI
 * of its own (the Quote requests screen reads it through our REST routes).
 *
 * Statuses: queued (waiting to be sent), in_ninja (a quote exists; quote_status says
 * draft / sent / approved ...), failed (with a reason; can be retried) and spam (held
 * by Akismet; can be sent anyway).
 */
final class Leads {

	public const POST_TYPE = 'invoiceninja_lead';

	public const STATUSES = array( 'queued', 'in_ninja', 'failed', 'spam' );

	/**
	 * Meta keys (all prefixed with _invoiceninja_lead_).
	 */
	private const META = array( 'status', 'email', 'fields', 'consents', 'client_id', 'contact_id', 'matched', 'quote_id', 'quote_number', 'quote_status', 'reason', 'attempts', 'marker', 'posted', 'notified', 'source', 'checked_at' );

	/**
	 * Register the post type.
	 */
	public function registerPostType(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Quote requests', 'invoiceninja' ),
					'singular_name' => __( 'Quote request', 'invoiceninja' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'query_var'           => false,
				'rewrite'             => false,
				'can_export'          => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Save a new lead.
	 *
	 * @param array<string, string> $fields   Submitted fields.
	 * @param array<string, mixed>  $consents Consents.
	 * @param string                $status   queued or spam.
	 * @param string                $source   The page it was sent from.
	 * @return int Lead id, 0 on failure.
	 */
	public function create( array $fields, array $consents, string $status, string $source ): int {
		$name = trim( ( $fields['first_name'] ?? '' ) . ' ' . ( $fields['last_name'] ?? '' ) );
		$id   = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => ( '' !== $name ? $name . ' · ' : '' ) . ( $fields['email'] ?? '' ),
				'post_author' => 0,
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		$id = (int) $id;
		$this->update(
			$id,
			array(
				'status'   => $status,
				'email'    => strtolower( (string) ( $fields['email'] ?? '' ) ),
				'fields'   => $fields,
				'consents' => $consents,
				'attempts' => 0,
				'marker'   => bin2hex( random_bytes( 4 ) ),
				'source'   => $source,
			)
		);
		return $id;
	}

	/**
	 * One lead.
	 *
	 * @param int $id Lead id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$lead = array(
			'id'         => (int) $post->ID,
			'created_at' => (string) $post->post_date_gmt,
		);
		foreach ( self::META as $key ) {
			$lead[ $key ] = get_post_meta( $post->ID, '_invoiceninja_lead_' . $key, true );
		}
		$lead['fields']   = is_array( $lead['fields'] ) ? $lead['fields'] : array();
		$lead['consents'] = is_array( $lead['consents'] ) ? $lead['consents'] : array();
		$lead['attempts'] = (int) $lead['attempts'];
		$lead['matched']  = (bool) $lead['matched'];
		$lead['posted']   = (bool) $lead['posted'];
		$lead['notified'] = (bool) $lead['notified'];
		foreach ( array( 'status', 'email', 'client_id', 'contact_id', 'quote_id', 'quote_number', 'quote_status', 'reason', 'marker', 'source' ) as $key ) {
			$lead[ $key ] = (string) $lead[ $key ];
		}
		$lead['checked_at'] = (int) $lead['checked_at'];
		return $lead;
	}

	/**
	 * Set fields.
	 *
	 * @param int                  $id     Lead id.
	 * @param array<string, mixed> $fields Fields (keys of META).
	 */
	public function update( int $id, array $fields ): void {
		foreach ( $fields as $key => $value ) {
			if ( in_array( $key, self::META, true ) ) {
				update_post_meta( $id, '_invoiceninja_lead_' . $key, is_bool( $value ) ? (int) $value : $value );
			}
		}
	}

	/**
	 * Change the status only if it is one of $from (so a late job never undoes a newer state).
	 *
	 * @param int                  $id     Lead id.
	 * @param list<string>         $from   Allowed current statuses.
	 * @param string               $to     New status.
	 * @param array<string, mixed> $fields Other fields to set when it changes.
	 */
	public function transition( int $id, array $from, string $to, array $fields = array() ): bool {
		$current = (string) get_post_meta( $id, '_invoiceninja_lead_status', true );
		if ( ! in_array( $current, $from, true ) ) {
			return false;
		}
		if ( $current !== $to && ! update_post_meta( $id, '_invoiceninja_lead_status', $to, $current ) ) {
			return false; // Changed by someone else between the read and the write.
		}
		$this->update( $id, $fields );
		return true;
	}

	/**
	 * The lead whose quote this is.
	 *
	 * @param string $quote_id Quote id.
	 * @return array<string, mixed>|null
	 */
	public function findByQuote( string $quote_id ): ?array {
		if ( '' === $quote_id ) {
			return null;
		}
		$ids = $this->ids(
			array(
				'meta_key'   => '_invoiceninja_lead_quote_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed meta lookup on our own post type.
				'meta_value' => $quote_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			),
			1
		);
		return $ids ? $this->find( $ids[0] ) : null;
	}

	/**
	 * Leads sent from this email address.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (1-based).
	 * @param int    $per   Per page.
	 * @return list<int>
	 */
	public function idsByEmail( string $email, int $page = 1, int $per = 20 ): array {
		return $this->ids(
			array(
				'meta_key'   => '_invoiceninja_lead_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- privacy lookup by email.
				'meta_value' => strtolower( trim( $email ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
				'paged'      => max( 1, $page ),
			),
			$per
		);
	}

	/**
	 * Leads with a status.
	 *
	 * @param string               $status Status.
	 * @param int                  $limit  Limit.
	 * @param array<string, mixed> $extra  More WP_Query args.
	 * @return list<int>
	 */
	public function idsWithStatus( string $status, int $limit = 100, array $extra = array() ): array {
		return $this->ids(
			$extra + array(
				'meta_key'   => '_invoiceninja_lead_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small status lookups on our own post type.
				'meta_value' => $status, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
				'order'      => 'ASC',
			),
			$limit
		);
	}

	/**
	 * Leads created before a time.
	 *
	 * @param int $before Unix time.
	 * @param int $limit  Limit.
	 * @return list<int>
	 */
	public function idsOlderThan( int $before, int $limit = 200 ): array {
		return $this->ids(
			array(
				'date_query' => array(
					array(
						'column' => 'post_date_gmt',
						'before' => gmdate( 'Y-m-d H:i:s', $before ),
					),
				),
				'order'      => 'ASC',
			),
			$limit
		);
	}

	/**
	 * A page of leads for the admin list, newest first.
	 *
	 * @param array{status?: string, search?: string, page?: int, per_page?: int} $filters Filters.
	 * @return array{items: list<array<string, mixed>>, total: int, counts: array<string, int>}
	 */
	public function list( array $filters ): array {
		$per    = max( 1, min( 100, (int) ( $filters['per_page'] ?? 20 ) ) );
		$args   = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => $per,
			'paged'          => max( 1, (int) ( $filters['page'] ?? 1 ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);
		$status = (string) ( $filters['status'] ?? '' );
		if ( in_array( $status, self::STATUSES, true ) ) {
			$args['meta_key']   = '_invoiceninja_lead_status'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin list filter.
			$args['meta_value'] = $status; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- admin list filter.
		}
		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			$args['s']              = $search; // The title holds the name and email.
			$args['search_columns'] = array( 'post_title' );
		}
		$query = new \WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $id ) {
			$lead = $this->find( is_object( $id ) ? (int) $id->ID : (int) $id );
			if ( null !== $lead ) {
				$items[] = $lead;
			}
		}
		return array(
			'items'  => $items,
			'total'  => (int) $query->found_posts,
			'counts' => $this->counts(),
		);
	}

	/**
	 * How many leads have each status.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_value AS status, COUNT(*) AS n FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key = '_invoiceninja_lead_status' GROUP BY pm.meta_value", self::POST_TYPE ), ARRAY_A );
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ (string) $row['status'] ] ) ) {
				$counts[ (string) $row['status'] ] = (int) $row['n'];
			}
		}
		return $counts;
	}

	/**
	 * Delete a lead and its meta for good.
	 *
	 * @param int $id Lead id.
	 */
	public function delete( int $id ): bool {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}
		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * Lead ids for a query.
	 *
	 * @param array<string, mixed> $args  WP_Query args.
	 * @param int                  $limit Limit.
	 * @return list<int>
	 */
	private function ids( array $args, int $limit ): array {
		$query = new \WP_Query(
			$args + array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'private',
				'posts_per_page'         => $limit,
				'fields'                 => 'ids',
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		return array_values( array_map( static fn( $p ): int => is_object( $p ) ? (int) $p->ID : (int) $p, $query->posts ) );
	}
}
