<?php
/**
 * Downloads product images into the media library.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Catalog;

use InvoiceNinja\Api\BaseUrl;
use InvoiceNinja\Plugin;

/**
 * Runs as a background job per changed image. Public images are fetched with
 * wp_safe_remote_get(); only the connected Invoice Ninja host may be private, and only
 * when the admin allowed it. Images over 5 MB or that aren't images are skipped.
 */
final class ImageSideloader {

	public const MAX_BYTES = 5242880;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Job handler.
	 *
	 * @param array<string, mixed> $payload {post_id, url}.
	 */
	public function handle( array $payload ): void {
		$post_id = (int) ( $payload['post_id'] ?? 0 );
		$url     = esc_url_raw( (string) ( $payload['url'] ?? '' ) );
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return;
		}
		if ( '' === $url ) {
			$this->removeOwnImage( $post_id );
			update_post_meta( $post_id, '_invoiceninja_image_url', '' );
			return;
		}

		$base = $this->plugin->credentials->url();
		$own  = '' !== $base && self::sameOrigin( $url, $base );
		if ( $own && $this->plugin->state->allowsPrivate( $base ) ) {
			// The allowed private host: no redirects (they could point anywhere on the network).
			$result = wp_remote_get(
				$url,
				array(
					'timeout'             => 15,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_BYTES,
				)
			);
		} else {
			$result = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 15,
					'redirection'         => 2,
					'limit_response_size' => self::MAX_BYTES,
				)
			);
		}
		if ( is_wp_error( $result ) || 200 !== (int) wp_remote_retrieve_response_code( $result ) ) {
			$this->plugin->logger->warning( 'sync', sprintf( "A product image couldn't be downloaded (post %d)", $post_id ), array( 'url' => $url ) );
			return;
		}
		$body = (string) wp_remote_retrieve_body( $result );
		$type = (string) wp_remote_retrieve_header( $result, 'content-type' );
		if ( '' === $body || strlen( $body ) >= self::MAX_BYTES || ! str_starts_with( strtolower( $type ), 'image/' ) ) {
			$this->plugin->logger->warning( 'sync', sprintf( 'Skipped a product image that is too large or not an image (post %d)', $post_id ) );
			update_post_meta( $post_id, '_invoiceninja_image_url', $url ); // Don't retry until it changes.
			return;
		}

		$ext = match ( strtolower( trim( explode( ';', $type )[0] ) ) ) {
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/svg+xml' => '',
			default      => 'jpg',
		};
		if ( '' === $ext ) {
			update_post_meta( $post_id, '_invoiceninja_image_url', $url ); // SVG can carry scripts; not imported.
			return;
		}
		$filename = sanitize_file_name( 'invoiceninja-' . get_post_meta( $post_id, 'product_id', true ) . '.' . $ext );
		$upload   = wp_upload_bits( $filename, null, $body );
		if ( ! empty( $upload['error'] ) ) {
			$this->plugin->logger->warning( 'sync', 'Saving a product image failed: ' . $upload['error'] );
			return;
		}
		$check = wp_check_filetype_and_ext( $upload['file'], $filename );
		if ( empty( $check['type'] ) || ! str_starts_with( (string) $check['type'], 'image/' ) ) {
			wp_delete_file( $upload['file'] );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $check['type'],
				'post_title'     => get_the_title( $post_id ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$post_id
		);
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			return;
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		$current = (int) get_post_thumbnail_id( $post_id );
		$ours    = (int) get_post_meta( $post_id, '_invoiceninja_attachment', true );
		if ( $current > 0 && $current !== $ours ) {
			// An admin chose this image; keep it and don't keep a copy nobody uses.
			wp_delete_attachment( $attachment_id, true );
			update_post_meta( $post_id, '_invoiceninja_image_url', $url );
			return;
		}
		$this->removeOwnImage( $post_id );
		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, '_invoiceninja_attachment', $attachment_id );
		update_post_meta( $post_id, '_invoiceninja_image_url', $url );
	}

	/**
	 * Whether a URL has the same scheme, host and port as the Invoice Ninja address.
	 *
	 * @param string $url  Image URL.
	 * @param string $base Base URL.
	 */
	private static function sameOrigin( string $url, string $base ): bool {
		$a = wp_parse_url( $url );
		$b = wp_parse_url( $base );
		if ( ! is_array( $a ) || ! is_array( $b ) ) {
			return false;
		}
		$port = static fn( array $u ): int => (int) ( $u['port'] ?? ( 'https' === ( $u['scheme'] ?? '' ) ? 443 : 80 ) );
		return strtolower( (string) ( $a['scheme'] ?? '' ) ) === strtolower( (string) ( $b['scheme'] ?? '' ) )
			&& strtolower( (string) ( $a['host'] ?? '' ) ) === strtolower( (string) ( $b['host'] ?? '' ) )
			&& $port( $a ) === $port( $b );
	}

	/**
	 * Delete the image we added earlier (never one an admin chose).
	 *
	 * @param int $post_id Post.
	 */
	private function removeOwnImage( int $post_id ): void {
		$old = (int) get_post_meta( $post_id, '_invoiceninja_attachment', true );
		if ( $old > 0 && (int) get_post_thumbnail_id( $post_id ) === $old ) {
			delete_post_thumbnail( $post_id );
			wp_delete_attachment( $old, true );
		}
		delete_post_meta( $post_id, '_invoiceninja_attachment' );
	}
}
