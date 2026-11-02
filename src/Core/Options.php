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
			'abandoned_enabled' => false,
			'abandoned_days'    => 14,
			'abandoned_action'  => 'cancel',
		),
		'email'                    => array(
			'sender_name' => '',
			'from'        => '',
			'reply_to'    => '',
		),
	);

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
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return $created;
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s", $name ) );
		$value = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $name, 'options' );
		return max( 1, $value );
	}

	/**
	 * Delete counters from earlier time buckets.
	 *
	 * @param int $keep_hours Keep this many recent hours.
	 */
	public function pruneCounters( int $keep_hours = 2 ): void {
		global $wpdb;
		$cutoff = (int) floor( time() / HOUR_IN_SECONDS ) - $keep_hours;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 5000", $wpdb->esc_like( self::PREFIX . 'ctr_' ) . '%' ) );
		foreach ( (array) $names as $name ) {
			if ( preg_match( '/_(\d+)$/', (string) $name, $m ) && (int) $m[1] < $cutoff ) {
				delete_option( (string) $name );
			}
		}
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
		return self::merge( self::SETTING_DEFAULTS, is_array( $stored ) ? $stored : array() );
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
		$clean = self::sanitize( self::SETTING_DEFAULTS, self::merge( $this->settings(), $changes ) );
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
