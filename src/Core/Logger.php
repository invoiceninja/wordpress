<?php
/**
 * Activity log stored in its own table.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Core;

/**
 * Writes redacted entries to `{prefix}invoiceninja_log`, read by Status → Activity.
 * Entries below the configured level are skipped; "Everything" (debug) turns itself
 * off after 24 hours because it records more personal data.
 */
final class Logger {

	public const TABLE = 'invoiceninja_log';

	public const MAX_ROWS = 100000;

	/**
	 * Per-request id that ties entries from one request together.
	 *
	 * @var string|null
	 */
	private static ?string $request_id = null;

	/**
	 * Constructor.
	 *
	 * @param Options $options Options.
	 */
	public function __construct( private Options $options ) {}

	/**
	 * The full table name.
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
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			level varchar(10) NOT NULL,
			channel varchar(32) NOT NULL,
			message text NOT NULL,
			context text NULL,
			request_id char(16) NOT NULL DEFAULT '',
			object_type varchar(32) NOT NULL DEFAULT '',
			object_id varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY level_created (level, created_at),
			KEY channel_created (channel, created_at),
			KEY object (object_type, object_id)
		) {$charset};";
	}

	/**
	 * The minimum level currently recorded.
	 */
	public function threshold(): LogLevel {
		$level = LogLevel::parse( (string) $this->options->setting( 'log_level' ) );
		if ( LogLevel::Debug === $level && (int) $this->options->get( 'log_debug_until', 0 ) < time() ) {
			return LogLevel::Info;
		}
		return $level;
	}

	/**
	 * Record an entry.
	 *
	 * @param LogLevel     $level       Level.
	 * @param string       $channel     Area: connection, sync, checkout, webhooks, email, upgrade, queue.
	 * @param string       $message     Plain-language message.
	 * @param array<mixed> $context     Extra data; secrets and personal data are redacted.
	 * @param string       $object_type Related object type, e.g. order.
	 * @param string       $object_id   Related object id.
	 */
	public function log( LogLevel $level, string $channel, string $message, array $context = array(), string $object_type = '', string $object_id = '' ): void {
		if ( $level->weight() < $this->threshold()->weight() ) {
			return;
		}
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
			self::table(),
			array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'level'       => $level->value,
				'channel'     => substr( sanitize_key( $channel ), 0, 32 ),
				'message'     => LogRedactor::text( $message ),
				'context'     => $context ? LogRedactor::context( $context ) : null,
				'request_id'  => self::requestId(),
				'object_type' => substr( sanitize_key( $object_type ), 0, 32 ),
				'object_id'   => substr( $object_id, 0, 64 ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Shortcut for errors.
	 *
	 * @param string       $channel Channel.
	 * @param string       $message Message.
	 * @param array<mixed> $context Context.
	 */
	public function error( string $channel, string $message, array $context = array() ): void {
		$this->log( LogLevel::Error, $channel, $message, $context );
	}

	/**
	 * Shortcut for warnings.
	 *
	 * @param string       $channel Channel.
	 * @param string       $message Message.
	 * @param array<mixed> $context Context.
	 */
	public function warning( string $channel, string $message, array $context = array() ): void {
		$this->log( LogLevel::Warning, $channel, $message, $context );
	}

	/**
	 * Shortcut for info.
	 *
	 * @param string       $channel Channel.
	 * @param string       $message Message.
	 * @param array<mixed> $context Context.
	 */
	public function info( string $channel, string $message, array $context = array() ): void {
		$this->log( LogLevel::Info, $channel, $message, $context );
	}

	/**
	 * Read entries, newest first.
	 *
	 * @param array{level?: string, channel?: string, search?: string, page?: int, per_page?: int} $filters Filters.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function query( array $filters = array() ): array {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['level'] ) ) {
			$min     = LogLevel::parse( (string) $filters['level'] )->weight();
			$levels  = array_values(
				array_map(
					static fn( LogLevel $l ): string => $l->value,
					array_filter( LogLevel::cases(), static fn( LogLevel $l ): bool => $l->weight() >= $min )
				)
			);
			$where[] = 'level IN (' . implode( ',', array_fill( 0, count( $levels ), '%s' ) ) . ')';
			$params  = array_merge( $params, $levels );
		}
		if ( ! empty( $filters['channel'] ) ) {
			$where[]  = 'channel = %s';
			$params[] = sanitize_key( (string) $filters['channel'] );
		}
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[]  = '(message LIKE %s OR object_id LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$per_page  = max( 1, min( 200, (int) ( $filters['per_page'] ?? 50 ) ) );
		$page      = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$sql_where = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB -- table name is ours; values are prepared.
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}", $params ) : "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, level, channel, message, context, request_id, object_type, object_id FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items' => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Delete entries past the retention period, and the oldest beyond MAX_ROWS.
	 * Works in batches so a large table never locks for long.
	 */
	public function prune(): int {
		global $wpdb;
		$table   = self::table();
		$days    = max( 1, (int) $this->options->setting( 'log_retention_days' ) );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$deleted = 0;
		// phpcs:disable WordPress.DB -- table name is ours; values are prepared.
		for ( $i = 0; $i < 50; $i++ ) {
			$n        = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT 1000", $cutoff ) );
			$deleted += $n;
			if ( $n < 1000 ) {
				break;
			}
		}
		$max_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
		for ( $i = 0; $max_id > self::MAX_ROWS && $i < 50; $i++ ) {
			$n        = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d LIMIT 10000", $max_id - self::MAX_ROWS ) );
			$deleted += $n;
			if ( $n < 10000 ) {
				break;
			}
		}
		// phpcs:enable
		return $deleted;
	}

	/**
	 * Delete everything.
	 */
	public function clear(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- table name is ours.
		if ( false === $wpdb->query( 'TRUNCATE TABLE ' . self::table() ) ) {
			$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- hosts without DROP privilege.
		}
	}

	/**
	 * Id shared by all entries in this request.
	 */
	public static function requestId(): string {
		if ( null === self::$request_id ) {
			self::$request_id = bin2hex( random_bytes( 8 ) );
		}
		return self::$request_id;
	}
}
