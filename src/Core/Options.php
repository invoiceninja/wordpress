<?php
/**
 * Typed access to the plugin's options.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Core;

/**
 * Every option is stored as `invoiceninja_<key>`. Settings that admins edit live in one
 * array option with defaults, so a missing key never means "off" by accident.
 */
final class Options {

	public const PREFIX = 'invoiceninja_';

	/**
	 * Defaults for the `invoiceninja_settings` option.
	 *
	 * @var array<string, mixed>
	 */
	public const SETTING_DEFAULTS = array(
		'log_level'                => 'info',
		'log_retention_days'       => 30,
		'delete_data_on_uninstall' => false,
		'modules'                  => array(
			'store'      => true,
			'account'    => true,
			'quote_form' => false,
			'subs'       => false,
			'woo'        => false,
		),
		'notifications'            => array(
			'recipients'  => '',
			'incidents'   => true,
			'plan'        => true,
			'attention'   => true,
			'failed_jobs' => true,
			'leads'       => 'instant',
			'new_orders'  => false,
			'weekly'      => false,
		),
		'store'                    => array(
			'mode'              => 'cart',
			'require_login'     => false,
			'display'           => 'blocks',
			'abandoned_enabled' => false,
			'abandoned_days'    => 14,
			'abandoned_action'  => 'cancel',
		),
		'pages'                    => array(
			'shop'     => 0,
			'checkout' => 0,
			'status'   => 0,
			'account'  => 0,
		),
		'email'                    => array(
			'sender_name' => '',
			'from'        => '',
			'reply_to'    => '',
		),
	);

	/**
	 * Setting defaults, including those modules add.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		/**
		 * Setting defaults. Modules add their own group; the type of each default is what
		 * a saved value is cast to.
		 *
		 * @since 2.0.0
		 * @param array<string, mixed> $defaults Defaults.
		 */
		$defaults = apply_filters( 'invoiceninja_setting_defaults', self::SETTING_DEFAULTS );
		return is_array( $defaults ) ? $defaults + self::SETTING_DEFAULTS : self::SETTING_DEFAULTS;
	}

	/**
	 * Read a raw option.
	 *
	 * @param string $key      Key without the prefix.
	 * @param mixed  $fallback Value when the option doesn't exist.
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return get_option( self::PREFIX . $key, $fallback );
	}

	/**
	 * Write a raw option. Plugin options are not autoloaded unless asked.
	 *
	 * @param string $key      Key without the prefix.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Whether WordPress loads it on every request.
	 */
	public function set( string $key, mixed $value, bool $autoload = false ): void {
		update_option( self::PREFIX . $key, $value, $autoload );
	}

	/**
	 * Add an option only if it doesn't exist, atomically (add_option() checks then inserts,
	 * so two concurrent requests can both "win"). Returns whether this call created it.
	 *
	 * @param string $key   Key without the prefix.
	 * @param string $value Value.
	 */
	public function addAtomic( string $key, string $value ): bool {
		global $wpdb;
		$name = self::PREFIX . $key;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! isset( $wpdb->options ) ) {
			return add_option( $name, $value, '', false );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value ) );
		$created = 1 === (int) $wpdb->rows_affected;
		self::forgetCached( $name );
		return $created;
	}

	/**
	 * Drop one option from the object cache after writing it with SQL. Rows written that
	 * way are never autoloaded, so the cached autoloaded options (alloptions) stay valid;
	 * only this option and its entry in the "doesn't exist" list are dropped.
	 *
	 * @param string $name Full option name.
	 */
	public static function forgetCached( string $name ): void {
		wp_cache_delete( $name, 'options' );
		$missing = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $missing ) && isset( $missing[ $name ] ) ) {
			unset( $missing[ $name ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}
	}

	/**
	 * Replace an option's value only if it still holds $expected, atomically (for locks).
	 * Returns whether this call replaced it.
	 *
	 * @param string $key      Key without the prefix.
	 * @param string $expected Value it must hold now.
	 * @param string $value    New value.
	 */
	public function replaceAtomic( string $key, string $expected, string $value ): bool {
		global $wpdb;
		$name = self::PREFIX . $key;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! isset( $wpdb->options ) ) {
			if ( (string) get_option( $name, '' ) !== $expected ) {
				return false;
			}
			update_option( $name, $value, false );
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $expected ) );
		$replaced = 1 === (int) $wpdb->rows_affected;
		self::forgetCached( $name );
		return $replaced;
	}

	/**
	 * Delete an option only if it still holds $expected, atomically (releasing a lock).
	 *
	 * @param string $key      Key without the prefix.
	 * @param string $expected Value it must hold.
	 */
	public function deleteIf( string $key, string $expected ): bool {
		global $wpdb;
		$name = self::PREFIX . $key;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! isset( $wpdb->options ) ) {
			if ( (string) get_option( $name, '' ) !== $expected ) {
				return false;
			}
			return delete_option( $name );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $expected ) );
		$deleted = 1 === (int) $wpdb->rows_affected;
		wp_cache_delete( $name, 'options' );
		return $deleted;
	}

	/**
	 * Add one to a numeric option in a single UPDATE and return the new value.
	 *
	 * LAST_INSERT_ID(expr) makes the new value readable on the connection that ran the
	 * UPDATE. It's read from that UPDATE's own reply (mysqli_insert_id) rather than with a
	 * second query: with a read/write split (HyperDB, LudicrousDB, ProxySQL) a separate
	 * SELECT LAST_INSERT_ID() can go to a replica and return 0. Without mysqli it falls
	 * back to that SELECT, then to reading the row.
	 *
	 * @param string $name Full option name.
	 * @return int The new value, or 0 when the option doesn't exist.
	 */
	public static function bump( string $name ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s", $name ) );
		$updated = (int) $wpdb->rows_affected;
		$value   = $updated > 0 ? self::insertId( $wpdb ) : 0;
		if ( $updated > 0 && $value <= 0 ) {
			$value = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( $updated > 0 && $value <= 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$value = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		}
		self::forgetCached( $name );
		return $value;
	}

	/**
	 * The LAST_INSERT_ID the last statement on wpdb's connection reported, or 0.
	 *
	 * @param object $db wpdb (or a drop-in such as HyperDB; its dbh is the connection just used).
	 */
	public static function insertId( object $db ): int {
		$dbh = isset( $db->dbh ) ? $db->dbh : null;
		if ( ! $dbh instanceof \mysqli ) {
			return 0;
		}
		try {
			return (int) mysqli_insert_id( $dbh ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_insert_id -- the reply of the UPDATE wpdb just ran, on wpdb's own connection.
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Atomically add one to a counter and return the new value (one SQL statement, so
	 * parallel requests can't read the same value). Counters are named with a time bucket
	 * and pruned daily.
	 *
	 * @param string $key Key without the prefix (e.g. ctr_code_ab12_483920).
	 */
	public function increment( string $key ): int {
		global $wpdb;
		$name = self::PREFIX . $key;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! isset( $wpdb->options ) ) {
			$value = (int) get_option( $name, 0 ) + 1;
			update_option( $name, $value, false );
			return $value;
		}
		// The first caller creates the row with 1; everyone else increments it in one UPDATE.
		// (LAST_INSERT_ID(expr) on an INSERT would return the new row's auto-increment id instead.)
		if ( $this->addAtomic( $key, '1' ) ) {
			return 1;
		}
		return max( 1, self::bump( $name ) );
	}

	/**
	 * Delete counters from earlier time buckets.
	 *
	 * @param int $keep_hours Keep this many recent hours.
	 */
	public function pruneCounters( int $keep_hours = 2 ): void {
		global $wpdb;
		$cutoff   = (int) floor( time() / HOUR_IN_SECONDS ) - $keep_hours;
		$after    = '';
		$deadline = microtime( true ) + 20;
		// Walk every counter in name order, a batch at a time, until done (or out of time:
		// tomorrow carries on). Counters are never autoloaded, so one DELETE per batch is enough.
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name ASC LIMIT 1000", $wpdb->esc_like( self::PREFIX . 'ctr_' ) . '%', $after ) );
			$old   = array();
			foreach ( $names as $name ) {
				if ( preg_match( '/_(\d+)$/', (string) $name, $m ) && (int) $m[1] < $cutoff ) {
					$old[] = (string) $name;
				}
			}
			if ( $old ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per name.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (" . implode( ',', array_fill( 0, count( $old ), '%s' ) ) . ')', $old ) );
				foreach ( $old as $name ) {
					wp_cache_delete( $name, 'options' );
				}
			}
			$after = $names ? (string) end( $names ) : '';
			$full  = 1000 === count( $names );
		} while ( $full && microtime( true ) < $deadline );
	}

	/**
	 * Delete a raw option.
	 *
	 * @param string $key Key without the prefix.
	 */
	public function delete( string $key ): void {
		delete_option( self::PREFIX . $key );
	}

	/**
	 * All settings merged over their defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		$stored = $this->get( 'settings', array() );
		return self::merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting, using dot notation for nested keys (e.g. `notifications.leads`).
	 *
	 * @param string $path Dotted path.
	 * @return mixed
	 */
	public function setting( string $path ): mixed {
		$value = $this->settings();
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return null;
			}
			$value = $value[ $part ];
		}
		return $value;
	}

	/**
	 * Save settings after keeping only known keys and casting each to its default's type.
	 *
	 * @param array<string, mixed> $changes Partial settings.
	 * @return array<string, mixed> The saved settings.
	 */
	public function saveSettings( array $changes ): array {
		$clean = self::sanitize( self::defaults(), self::merge( $this->settings(), $changes ) );
		$this->set( 'settings', $clean );
		return $clean;
	}

	/**
	 * Recursively merge, keeping only keys present in $base.
	 *
	 * @param array<string, mixed> $base   Defaults.
	 * @param array<string, mixed> $values Overrides.
	 * @return array<string, mixed>
	 */
	private static function merge( array $base, array $values ): array {
		foreach ( $base as $key => $default ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$base[ $key ] = is_array( $default ) && is_array( $values[ $key ] )
				? self::merge( $default, $values[ $key ] )
				: $values[ $key ];
		}
		return $base;
	}

	/**
	 * Cast every value to the type of its default.
	 *
	 * @param array<string, mixed> $defaults Defaults.
	 * @param array<string, mixed> $values   Values.
	 * @return array<string, mixed>
	 */
	private static function sanitize( array $defaults, array $values ): array {
		$out = array();
		foreach ( $defaults as $key => $default ) {
			$value = $values[ $key ] ?? $default;
			if ( is_array( $default ) ) {
				$out[ $key ] = self::sanitize( $default, is_array( $value ) ? $value : array() );
			} elseif ( is_bool( $default ) ) {
				$out[ $key ] = (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			} elseif ( is_int( $default ) ) {
				$out[ $key ] = max( 0, (int) $value );
			} else {
				$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $out;
	}
}
