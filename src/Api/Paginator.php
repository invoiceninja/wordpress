<?php
/**
 * Walks paginated list endpoints.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Api;

/**
 * Always sends an explicit sort, because lists default to newest-first by id.
 */
final class Paginator {

	/**
	 * Yield every item from a list endpoint.
	 *
	 * @param HttpClient           $client   Client.
	 * @param string               $path     Path, e.g. products.
	 * @param array<string, mixed> $query    Filters.
	 * @param int                  $per_page Page size (Invoice Ninja allows up to 5000).
	 * @param int                  $max      Safety cap on pages.
	 * @return \Generator<int, array<string, mixed>>
	 */
	public static function each( HttpClient $client, string $path, array $query = array(), int $per_page = 250, int $max = 400 ): \Generator {
		$query += array( 'sort' => 'id|asc' );
		for ( $page = 1; $page <= $max; $page++ ) {
			$response = $client->get(
				$path,
				$query + array(
					'per_page' => $per_page,
					'page'     => $page,
				)
			);
			foreach ( $response->data() as $item ) {
				if ( is_array( $item ) ) {
					yield $item;
				}
			}
			if ( $page >= $response->totalPages() ) {
				return;
			}
		}
	}
}
