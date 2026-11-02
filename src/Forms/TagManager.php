<?php
/**
 * Creates the quote tags in Invoice Ninja and remembers their ids.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Forms;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Core\Options;

/**
 * Quotes take the ids of existing tags, so each tag is created once with
 * POST /tags {name, entity_type: quote} (admin tokens only) and its id cached in the
 * invoiceninja_quote_tags option. Tags arrived in Invoice Ninja 5.13.25; older servers
 * get no tags. A name that already exists (422) is looked up instead.
 */
final class TagManager {

	public const OPTION = 'quote_tags';

	public const MIN_VERSION = '5.13.25';

	/**
	 * Constructor.
	 *
	 * @param Options $options Options.
	 */
	public function __construct( private Options $options ) {}

	/**
	 * Tag ids for these names, creating any that are missing. Never throws for tag
	 * problems that retrying can't fix: the quote is created without those tags.
	 *
	 * @param HttpClient   $client  API client.
	 * @param list<string> $names   Clean tag names.
	 * @param string       $version Invoice Ninja version.
	 * @return list<string>
	 * @throws ApiException When Invoice Ninja can't be reached (the job retries).
	 */
	public function ids( HttpClient $client, array $names, string $version ): array {
		if ( ! $names || ! HttpClient::versionAtLeast( $version, self::MIN_VERSION ) ) {
			return array();
		}
		$cache = $this->cache();
		$ids   = array();
		foreach ( $names as $name ) {
			$key = mb_strtolower( $name );
			if ( ! isset( $cache[ $key ] ) ) {
				$id = $this->create( $client, $name );
				if ( null === $id ) {
					continue;
				}
				$cache[ $key ] = $id;
				$this->options->set( self::OPTION, $cache );
			}
			$ids[] = $cache[ $key ];
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Forget the cached ids (a tag was deleted in Invoice Ninja, or the company changed).
	 */
	public function forget(): void {
		$this->options->delete( self::OPTION );
	}

	/**
	 * Cached ids by lower-case name.
	 *
	 * @return array<string, string>
	 */
	private function cache(): array {
		$cache = $this->options->get( self::OPTION, array() );
		return is_array( $cache ) ? array_map( 'strval', $cache ) : array();
	}

	/**
	 * Create one tag, or find it when it already exists.
	 *
	 * @param HttpClient $client API client.
	 * @param string     $name   Name.
	 * @return string|null Null when Invoice Ninja won't let us (e.g. not an admin token).
	 * @throws ApiException When Invoice Ninja can't be reached.
	 */
	private function create( HttpClient $client, string $name ): ?string {
		try {
			$tag = $client->post(
				'tags',
				array(
					'name'        => $name,
					'entity_type' => 'quote',
				),
				array(),
				8.0
			)->data();
			if ( '' !== (string) ( $tag['id'] ?? '' ) ) {
				return (string) $tag['id'];
			}
		} catch ( ApiException $e ) {
			if ( $e->kind->retryable() && ErrorKind::Duplicate !== $e->kind ) {
				throw $e;
			}
			if ( ErrorKind::Validation !== $e->kind && ErrorKind::Duplicate !== $e->kind ) {
				return null; // Forbidden, non-admin token, ...: no tags rather than no quote.
			}
		}
		// Already there (names are unique per type), or a lost reply: look it up.
		$found = $client->get(
			'tags',
			array(
				'filter'      => $name,
				'entity_type' => 'quote',
				'per_page'    => 100,
			),
			8.0
		)->data();
		return self::match( $found, $name );
	}

	/**
	 * The id of the quote (or global) tag with exactly this name, ignoring case.
	 *
	 * @param array<mixed> $tags Tags from GET /tags.
	 * @param string       $name Name.
	 */
	public static function match( array $tags, string $name ): ?string {
		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) || ! empty( $tag['is_deleted'] ) || ! empty( $tag['archived_at'] ) ) {
				continue;
			}
			$type = (string) ( $tag['entity_type'] ?? '' );
			$ok   = in_array( $type, array( 'quote', 'global', 'App\\Models\\Quote', 'App\\Models\\Company' ), true );
			if ( $ok && mb_strtolower( (string) ( $tag['name'] ?? '' ) ) === mb_strtolower( $name ) && '' !== (string) ( $tag['id'] ?? '' ) ) {
				return (string) $tag['id'];
			}
		}
		return null;
	}
}
