<?php
/**
 * WordPress personal-data export and erase for quote requests.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Forms;

/**
 * Tools > Export / Erase Personal Data find leads by the email they were sent with.
 * Erasing deletes the lead here only; the client and quote in Invoice Ninja are the
 * business's records and are handled there.
 */
final class Privacy {

	public const PER_PAGE = 20;

	/**
	 * Constructor.
	 *
	 * @param Leads $leads Leads.
	 */
	public function __construct( private Leads $leads ) {}

	/**
	 * Register the exporter, eraser and privacy policy text.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
		add_action( 'admin_init', array( $this, 'policy' ) );
	}

	/**
	 * Add our exporter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public function exporters( array $exporters ): array {
		$exporters['invoiceninja-quote-requests'] = array(
			'exporter_friendly_name' => __( 'Invoice Ninja quote requests', 'invoiceninja' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Add our eraser.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public function erasers( array $erasers ): array {
		$erasers['invoiceninja-quote-requests'] = array(
			'eraser_friendly_name' => __( 'Invoice Ninja quote requests', 'invoiceninja' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export one page of leads.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (1-based).
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$ids    = $this->leads->idsByEmail( $email, $page, self::PER_PAGE );
		$labels = FormConfig::labels();
		$data   = array();
		foreach ( $ids as $id ) {
			$lead = $this->leads->find( $id );
			if ( null === $lead ) {
				continue;
			}
			$items = array(
				array(
					'name'  => __( 'Sent', 'invoiceninja' ),
					'value' => $lead['created_at'] . ' UTC',
				),
			);
			foreach ( $labels as $key => $label ) {
				$value = (string) ( 'product' === $key ? ( $lead['fields']['product_title'] ?? '' ) : ( $lead['fields'][ $key ] ?? '' ) );
				if ( '' !== $value ) {
					$items[] = array(
						'name'  => $label,
						'value' => $value,
					);
				}
			}
			foreach ( $lead['consents'] as $consent ) {
				if ( is_array( $consent ) ) {
					$items[] = array(
						/* translators: %s: page title, e.g. Privacy Policy */
						'name'  => sprintf( __( 'Agreed to %s', 'invoiceninja' ), (string) ( $consent['title'] ?? '' ) ),
						'value' => ! empty( $consent['agreed'] ) ? gmdate( 'Y-m-d H:i:s', (int) ( $consent['at'] ?? 0 ) ) . ' UTC' : __( 'No', 'invoiceninja' ),
					);
				}
			}
			if ( '' !== $lead['source'] ) {
				$items[] = array(
					'name'  => __( 'Page', 'invoiceninja' ),
					'value' => $lead['source'],
				);
			}
			$data[] = array(
				'group_id'    => 'invoiceninja-quote-requests',
				'group_label' => __( 'Quote requests', 'invoiceninja' ),
				'item_id'     => 'invoiceninja-lead-' . $id,
				'data'        => $items,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $ids ) < self::PER_PAGE,
		);
	}

	/**
	 * Erase one batch of leads. Deleted leads leave the list, so every call reads page 1.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (unused: see above).
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		unset( $page );
		$ids     = $this->leads->idsByEmail( $email, 1, self::PER_PAGE );
		$removed = false;
		foreach ( $ids as $id ) {
			$removed = $this->leads->delete( $id ) || $removed;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $removed ? array( __( 'Quote requests on this site were deleted. The client and quotes in Invoice Ninja are kept; remove them there if needed.', 'invoiceninja' ) ) : array(),
			'done'           => count( $ids ) < self::PER_PAGE,
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Invoice Ninja', 'invoiceninja' ),
			'<p>' . esc_html__( 'When you send a quote request, we keep your name, email, phone, message, the product you chose and your consent to our policies, with the time you gave it. We send them to our billing system, Invoice Ninja, to prepare your quote.', 'invoiceninja' ) . '</p>'
		);
	}
}
