<?php
/**
 * Log levels.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Core;

enum LogLevel: string {

	case Debug   = 'debug';
	case Info    = 'info';
	case Warning = 'warning';
	case Error   = 'error';

	/**
	 * Severity used for filtering.
	 */
	public function weight(): int {
		return match ( $this ) {
			self::Debug   => 0,
			self::Info    => 1,
			self::Warning => 2,
			self::Error   => 3,
		};
	}

	/**
	 * Parse a stored value, falling back to Warning.
	 *
	 * @param string $value Stored value.
	 */
	public static function parse( string $value ): self {
		return self::tryFrom( $value ) ?? self::Warning;
	}
}
