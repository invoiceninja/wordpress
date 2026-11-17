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

	/** galleryImage(): this site couldn't save the photo just now; leave it to a later run. */
	private const LATER = -1;

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
	 * Gallery job: bring a product's extra photos in line with its public image documents in
	 * Invoice Ninja (ProductSync::GALLERY_WANT). Photos already downloaded are kept; ones no
	 * longer wanted are deleted. Documents are fetched through the API with the token (their
	 * download link needs it), never from another host.
	 *
	 * @param array<string, mixed> $payload {post_id}.
	 * @throws \InvoiceNinja\Api\ApiException A download failed in a way worth retrying (after saving what's done).
	 */
	public function gallery( array $payload ): void {
		$post_id = (int) ( $payload['post_id'] ?? 0 );
		if ( $post_id <= 0 || ProductSync::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		$want = get_post_meta( $post_id, ProductSync::GALLERY_WANT, true );
		if ( ! is_array( $want ) ) {
			return; // Nothing changed since the last run.
		}
		$done  = get_post_meta( $post_id, ProductSync::GALLERY_META, true );
		$have  = array();
		$by_id = array();
		foreach ( is_array( $done ) ? $done : array() as $row ) {
			if ( is_array( $row ) ) {
				$have[ (string) ( $row['id'] ?? '' ) . ':' . (string) ( $row['hash'] ?? '' ) ] = (int) ( $row['attachment'] ?? 0 );
				$by_id[ (string) ( $row['id'] ?? '' ) ]                                        = $row;
			}
		}
		$kept  = array();
		$rows  = array();
		$retry = null;
		$later = false;
		foreach ( $want as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}
			$key = (string) ( $doc['id'] ?? '' ) . ':' . (string) ( $doc['hash'] ?? '' );
			if ( isset( $have[ $key ] ) && ( 0 === $have[ $key ] || 'attachment' === get_post_type( $have[ $key ] ) ) ) {
				$attachment = $have[ $key ];
			} else {
				try {
					$attachment = $this->galleryImage( $post_id, $doc );
					if ( self::LATER === $attachment ) {
						$later = true;
						continue; // Not recorded, so the next product sync queues it again.
					}
				} catch ( \InvoiceNinja\Api\ApiException $e ) {
					if ( $e->kind->retryable() ) {
						$retry = $retry ?? $e;
						// A new version of a photo we have: keep showing the old one until the
						// retry gets the new one (its old hash makes the retry try again).
						$old = $by_id[ (string) ( $doc['id'] ?? '' ) ] ?? null;
						if ( is_array( $old ) && (int) ( $old['attachment'] ?? 0 ) > 0 ) {
							$kept[] = (int) $old['attachment'];
							$rows[] = $old;
						}
						continue; // Not recorded as done: tried again with the retry.
					}
					$this->plugin->logger->warning( 'sync', sprintf( "A product photo couldn't be downloaded (post %d): %s", $post_id, $e->kind->title() ) );
					$attachment = 0; // Recorded as done, so it isn't tried again until it changes.
				}
			}
			$kept[] = $attachment;
			$rows[] = array(
				'id'         => (string) ( $doc['id'] ?? '' ),
				'hash'       => (string) ( $doc['hash'] ?? '' ),
				'attachment' => $attachment,
			);
		}
		// Photos removed in Invoice Ninja (or replaced by a new version).
		foreach ( $have as $attachment ) {
			if ( $attachment > 0 && ! in_array( $attachment, $kept, true ) && (int) wp_get_post_parent_id( $attachment ) === $post_id ) {
				wp_delete_attachment( $attachment, true );
			}
		}
		update_post_meta( $post_id, ProductSync::GALLERY_META, $rows );
		if ( null !== $retry ) {
			throw $retry;
		}
		if ( $later ) {
			// The wanted list stays, and the job comes back in an hour (a product that
			// doesn't change in Invoice Ninja isn't written again, so sync won't queue it).
			$this->plugin->queue->push( ProductSync::GALLERY_HOOK, array( 'post_id' => $post_id ), HOUR_IN_SECONDS );
			return;
		}
		// Only the list this run worked from: a sync may have asked for a newer one meanwhile,
		// and queued a job that needs it.
		delete_post_meta( $post_id, ProductSync::GALLERY_WANT, $want );
	}

	/**
	 * Download one image document into the media library, attached to the product.
	 *
	 * @param int                  $post_id Post.
	 * @param array<string, mixed> $doc     {id, type, name}.
	 * @return int Attachment id, 0 when it isn't a usable image (recorded, not tried again
	 *             until it changes), or self::LATER when this site couldn't save it just now.
	 * @throws \InvoiceNinja\Api\ApiException The download failed.
	 */
	private function galleryImage( int $post_id, array $doc ): int {
		$id   = ProductSync::cleanId( (string) ( $doc['id'] ?? '' ) );
		$type = strtolower( (string) ( $doc['type'] ?? '' ) );
		if ( '' === $id ) {
			return 0;
		}
		if ( 'none' === $this->plugin->credentials->source() ) {
			return self::LATER; // Disconnected for now: try again after reconnecting.
		}
		$file = $this->plugin->api()->download( 'documents/' . rawurlencode( $id ) . '/download' );
		$body = $file['body'];
		$size = '' !== $body && strlen( $body ) < self::MAX_BYTES ? getimagesizefromstring( $body ) : false;
		$ext  = array(
			IMAGETYPE_PNG  => 'png',
			IMAGETYPE_JPEG => 'jpg',
			IMAGETYPE_GIF  => 'gif',
			IMAGETYPE_WEBP => 'webp',
		)[ is_array( $size ) ? (int) $size[2] : 0 ] ?? '';
		if ( '' === $ext ) {
			$this->plugin->logger->warning( 'sync', sprintf( 'Skipped a product photo that is too large or not an image (post %d, %s)', $post_id, $type ) );
			return 0;
		}
		$filename = sanitize_file_name( 'invoiceninja-' . get_post_meta( $post_id, 'product_id', true ) . '-' . strtolower( $id ) . '.' . $ext );
		$upload   = wp_upload_bits( $filename, null, $body );
		if ( ! empty( $upload['error'] ) ) {
			$this->plugin->logger->warning( 'sync', 'Saving a product photo failed: ' . $upload['error'] );
			return self::LATER; // A full disk or unwritable uploads folder: worth another try.
		}
		$check = wp_check_filetype_and_ext( $upload['file'], $filename );
		if ( empty( $check['type'] ) || ! str_starts_with( (string) $check['type'], 'image/' ) ) {
			wp_delete_file( $upload['file'] );
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$name          = trim( (string) ( $doc['name'] ?? '' ) );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $check['type'],
				'post_title'     => '' !== $name ? (string) preg_replace( '/\.[A-Za-z0-9]+$/', '', $name ) : get_the_title( $post_id ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$post_id
		);
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			wp_delete_file( $upload['file'] );
			return self::LATER;
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		return (int) $attachment_id;
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
