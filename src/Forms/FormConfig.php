<?php
/**
 * The Quote Request Form's fields, its signed configuration and validation.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Forms;

/**
 * Pure helpers (no database, no HTTP). The block's attributes become a small config that is
 * signed into the form's HTML, so a submission can only use the fields, products and
 * consent pages the admin chose, and cached pages keep working.
 */
final class FormConfig {

	/**
	 * Fields in display order, with whether each is shown and required by default.
	 */
	public const FIELDS = array(
		'first_name' => array( true, true ),
		'last_name'  => array( true, false ),
		'email'      => array( true, true ),
		'phone'      => array( true, false ),
		'product'    => array( true, false ),
		'message'    => array( true, true ),
	);

	/**
	 * The product choice for "Not sure yet".
	 */
	public const NOT_SURE = 'not_sure';

	/**
	 * Longest accepted value per field.
	 */
	public const MAX_LENGTH = array(
		'first_name' => 100,
		'last_name'  => 100,
		'email'      => 191,
		'phone'      => 40,
		'product'    => 64,
		'message'    => 5000,
	);

	/**
	 * The form's config from block attributes (camelCase: showPhone, requirePhone, products,
	 * privacyPage, requirePrivacy, termsPage, requireTerms).
	 *
	 * @param array<string, mixed> $attributes       Block or shortcode attributes.
	 * @param list<string>         $default_products Products offered when the block chooses none.
	 * @return array{fields: array<string, array{show: bool, required: bool}>, products: list<string>, privacy: array{page: int, required: bool}, terms: array{page: int, required: bool}}
	 */
	public static function fromAttributes( array $attributes, array $default_products = array() ): array {
		$fields = array();
		foreach ( self::FIELDS as $name => [ $show, $required ] ) {
			$key   = str_replace( ' ', '', ucwords( str_replace( '_', ' ', $name ) ) );
			$shown = 'email' === $name || self::bool( $attributes[ 'show' . $key ] ?? $show );
			// Email is how we reply, so it's always there and always required.
			$fields[ $name ] = array(
				'show'     => $shown,
				'required' => 'email' === $name || ( $shown && self::bool( $attributes[ 'require' . $key ] ?? $required ) ),
			);
		}
		$products = self::ids( $attributes['products'] ?? array() );
		if ( ! $products ) {
			$products = self::ids( $default_products );
		}
		if ( ! $products ) {
			// Nothing to choose from: no product field.
			$fields['product'] = array(
				'show'     => false,
				'required' => false,
			);
		}
		// The same defaults as block.json: privacy required, terms optional.
		$consent = static function ( string $which, bool $required ) use ( $attributes ): array {
			$page = max( 0, (int) ( $attributes[ $which . 'Page' ] ?? 0 ) );
			return array(
				'page'     => $page,
				'required' => $page > 0 && self::bool( $attributes[ 'require' . ucfirst( $which ) ] ?? $required ),
			);
		};
		return array(
			'fields'   => $fields,
			'products' => $products,
			'privacy'  => $consent( 'privacy', true ),
			'terms'    => $consent( 'terms', false ),
		);
	}

	/**
	 * A list of product ids from an array or a comma-separated string.
	 *
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	public static function ids( mixed $value ): array {
		$list = is_array( $value ) ? $value : explode( ',', (string) $value );
		$out  = array();
		foreach ( $list as $id ) {
			$id = is_scalar( $id ) ? trim( (string) $id ) : '';
			if ( '' !== $id && preg_match( '/^[A-Za-z0-9]{1,64}$/', $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return array_slice( $out, 0, 100 );
	}

	/**
	 * A redirect address: a full http(s) URL, or '' when it isn't one.
	 *
	 * @param string $url URL.
	 */
	public static function redirectUrl( string $url ): string {
		$url    = trim( $url );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( '' === $url || ! in_array( $scheme, array( 'http', 'https' ), true ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Sign a config together with the time the form was rendered.
	 *
	 * @param array<string, mixed> $config Config.
	 * @param int                  $time   Render time.
	 * @param string               $secret Signing secret.
	 */
	public static function sign( array $config, int $time, #[\SensitiveParameter] string $secret ): string {
		$data = self::base64( (string) wp_json_encode( array( $config, $time ) ) );
		return $data . '.' . hash_hmac( 'sha256', 'quote_form|' . $data, $secret );
	}

	/**
	 * Read a signed form token.
	 *
	 * @param string $token  Token.
	 * @param string $secret Signing secret.
	 * @return array{config: array<string, mixed>, time: int}|null Null when forged or damaged.
	 */
	public static function verify( string $token, #[\SensitiveParameter] string $secret ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || strlen( $token ) > 8000 ) {
			return null;
		}
		[ $data, $mac ] = $parts;
		if ( ! hash_equals( hash_hmac( 'sha256', 'quote_form|' . $data, $secret ), $mac ) ) {
			return null;
		}
		$decoded = json_decode( (string) base64_decode( strtr( $data, '-_', '+/' ), true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- signed form token.
		if ( ! is_array( $decoded ) || ! isset( $decoded[0], $decoded[1] ) || ! is_array( $decoded[0] ) ) {
			return null;
		}
		return array(
			'config' => $decoded[0],
			'time'   => (int) $decoded[1],
		);
	}

	/**
	 * Check a submission against the config.
	 *
	 * @param array<string, mixed> $config Config from fromAttributes().
	 * @param array<string, mixed> $input  Submitted values.
	 * @return array{values: array<string, string>, consents: array<string, bool>, errors: array<string, string>}
	 */
	public static function validate( array $config, array $input ): array {
		$values = array();
		$errors = array();
		foreach ( self::FIELDS as $name => $unused ) {
			unset( $unused );
			$field = (array) ( $config['fields'][ $name ] ?? array() );
			$raw   = $input[ $name ] ?? '';
			$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';
			$value = 'message' === $name ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			if ( empty( $field['show'] ) ) {
				$values[ $name ] = '';
				continue;
			}
			if ( mb_strlen( $value ) > self::MAX_LENGTH[ $name ] ) {
				/* translators: %d: maximum number of characters */
				$errors[ $name ] = sprintf( __( 'Use %d characters or fewer.', 'invoiceninja' ), self::MAX_LENGTH[ $name ] );
			} elseif ( '' === $value && ! empty( $field['required'] ) ) {
				$errors[ $name ] = self::requiredMessage( $name );
			} elseif ( 'email' === $name && ! is_email( $value ) ) {
				$errors[ $name ] = __( 'Enter an email address like name@example.com.', 'invoiceninja' );
			} elseif ( 'product' === $name && '' !== $value && self::NOT_SURE !== $value && ! in_array( $value, (array) ( $config['products'] ?? array() ), true ) ) {
				$errors[ $name ] = __( 'Choose one of the listed options.', 'invoiceninja' );
			} elseif ( 'phone' === $name && '' !== $value && ! preg_match( '/^[0-9+().\/\s-]{3,40}$/', $value ) ) {
				$errors[ $name ] = __( 'Enter a phone number using digits, spaces and + ( ) - only.', 'invoiceninja' );
			}
			$values[ $name ] = 'email' === $name ? strtolower( $value ) : $value;
		}
		$consents = array();
		foreach ( array( 'privacy', 'terms' ) as $which ) {
			$page = (int) ( $config[ $which ]['page'] ?? 0 );
			if ( $page <= 0 ) {
				continue;
			}
			$consents[ $which ] = self::bool( $input[ $which ] ?? false );
			if ( ! $consents[ $which ] && ! empty( $config[ $which ]['required'] ) ) {
				$errors[ $which ] = 'privacy' === $which
					? __( 'Please agree to the privacy policy to send your request.', 'invoiceninja' )
					: __( 'Please agree to the terms to send your request.', 'invoiceninja' );
			}
		}
		return array(
			'values'   => $values,
			'consents' => $consents,
			'errors'   => $errors,
		);
	}

	/**
	 * The label of each field.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'first_name' => __( 'First name', 'invoiceninja' ),
			'last_name'  => __( 'Last name', 'invoiceninja' ),
			'email'      => __( 'Email', 'invoiceninja' ),
			'phone'      => __( 'Phone', 'invoiceninja' ),
			'product'    => __( 'What are you interested in?', 'invoiceninja' ),
			'message'    => __( 'Message', 'invoiceninja' ),
		);
	}

	/**
	 * "Enter your first name." and so on.
	 *
	 * @param string $name Field.
	 */
	private static function requiredMessage( string $name ): string {
		return match ( $name ) {
			'first_name' => __( 'Enter your first name.', 'invoiceninja' ),
			'last_name'  => __( 'Enter your last name.', 'invoiceninja' ),
			'email'      => __( 'Enter your email address.', 'invoiceninja' ),
			'phone'      => __( 'Enter your phone number.', 'invoiceninja' ),
			'product'    => __( 'Choose what you are interested in.', 'invoiceninja' ),
			default      => __( 'Tell us what you need.', 'invoiceninja' ),
		};
	}

	/**
	 * A loose boolean (true, "true", "1", "on").
	 *
	 * @param mixed $value Value.
	 */
	private static function bool( mixed $value ): bool {
		return is_bool( $value ) ? $value : (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $raw Raw bytes.
	 */
	private static function base64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- signed form token.
	}
}
