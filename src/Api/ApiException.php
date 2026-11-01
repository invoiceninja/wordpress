<?php
/**
 * A failed call to Invoice Ninja.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Api;

/**
 * Carries the kind of failure, the HTTP status and a technical detail line.
 * Never contains the token or any request headers.
 */
final class ApiException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param ErrorKind            $kind       Kind.
	 * @param string               $technical  Technical detail, safe to show to admins.
	 * @param int                  $status     HTTP status, 0 for transport errors.
	 * @param array<string, mixed> $errors     Validation errors from a 422.
	 * @param int|null             $retryAfter Seconds from a Retry-After header.
	 * @param string               $location   Redirect target, for Redirect errors.
	 */
	public function __construct(
		public readonly ErrorKind $kind,
		public readonly string $technical = '',
		public readonly int $status = 0,
		public readonly array $errors = array(),
		public readonly ?int $retryAfter = null,
		public readonly string $location = '',
	) {
		parent::__construct( $kind->value . ( '' !== $technical ? ': ' . $technical : '' ), $status );
	}
}
