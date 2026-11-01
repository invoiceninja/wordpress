<?php
/**
 * A successful response from Invoice Ninja.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Api;

/**
 * Decoded JSON body plus the headers we care about.
 */
final class Response {

	/**
	 * Constructor.
	 *
	 * @param int                   $status  HTTP status.
	 * @param array<string, string> $headers Lower-cased headers.
	 * @param array<mixed>          $json    Decoded body.
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $headers,
		public readonly array $json,
	) {}

	/**
	 * The `data` member, or the whole body.
	 *
	 * @return array<mixed>
	 */
	public function data(): array {
		return isset( $this->json['data'] ) && is_array( $this->json['data'] ) ? $this->json['data'] : $this->json;
	}

	/**
	 * A header value.
	 *
	 * @param string $name Header name.
	 */
	public function header( string $name ): string {
		return $this->headers[ strtolower( $name ) ] ?? '';
	}

	/**
	 * Total pages from Laravel/Fractal pagination meta.
	 */
	public function totalPages(): int {
		return (int) ( $this->json['meta']['pagination']['total_pages'] ?? 1 );
	}
}
