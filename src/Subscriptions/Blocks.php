<?php
/**
 * Pricing Table and Subscribe Button.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Subscriptions;

use InvoiceNinja\Frontend\Store;
use InvoiceNinja\Plugin;
use InvoiceNinja\Webhooks\Events;

/**
 * Rendered from the local copy of the subscriptions, so pages are cacheable. Buttons link to
 * this site's own ?invoiceninja=subscribe&s=<id> endpoint, never to Invoice Ninja, so no
 * login or purchase link ever ends up in cached HTML.
 */
final class Blocks {

	public const HANDLE = 'invoiceninja-subscriptions';

	/**
	 * Constructor.
	 *
	 * @param Plugin           $plugin Plugin.
	 * @param SubscriptionSync $sync   Subscriptions.
	 */
	public function __construct( private Plugin $plugin, private SubscriptionSync $sync ) {}

	/**
	 * Register the front-end assets.
	 */
	public function registerAssets(): void {
		$url = plugins_url( '', $this->plugin->file );
		wp_register_style( self::HANDLE, $url . '/assets/subscriptions/subscriptions.css', array( Store::HANDLE ), INVOICENINJA_VERSION );
		wp_register_script(
			self::HANDLE,
			$url . '/assets/subscriptions/subscriptions.js',
			array(),
			INVOICENINJA_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Load the assets.
	 */
	private function enqueue(): void {
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Our subscribe endpoint for a subscription.
	 *
	 * @param string $id Subscription id.
	 */
	public static function subscribeUrl( string $id ): string {
		return add_query_arg(
			array(
				'invoiceninja' => 'subscribe',
				's'            => rawurlencode( $id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * "a,b" to a list of ids.
	 *
	 * @param mixed $value Attribute.
	 * @return list<string>
	 */
	public static function ids( mixed $value ): array {
		$list = is_array( $value ) ? $value : explode( ',', is_scalar( $value ) ? (string) $value : '' );
		return array_values( array_unique( array_filter( array_map( array( Events::class, 'cleanId' ), $list ) ) ) );
	}

	/**
	 * "a:x,b:y" (monthly:yearly) to a map.
	 *
	 * @param mixed $value Attribute.
	 * @return array<string, string>
	 */
	public static function pairs( mixed $value ): array {
		$out = array();
		foreach ( explode( ',', is_scalar( $value ) ? (string) $value : '' ) as $pair ) {
			$parts = explode( ':', $pair, 2 );
			if ( 2 === count( $parts ) ) {
				$from = Events::cleanId( $parts[0] );
				$to   = Events::cleanId( $parts[1] );
				if ( '' !== $from && '' !== $to && $from !== $to ) {
					$out[ $from ] = $to;
				}
			}
		}
		return $out;
	}

	/**
	 * Pricing Table.
	 *
	 * @param array<string, mixed> $a ids ("a,b"), yearly ("a:x" pairs), highlight, buttonLabel, showTrial.
	 */
	public function pricingTable( array $a ): string {
		$ids   = self::ids( $a['ids'] ?? '' );
		$pairs = self::pairs( $a['yearly'] ?? '' );
		$state = $this->placeholder();
		if ( null !== $state ) {
			return $state;
		}
		$admin = current_user_can( 'edit_posts' );
		if ( ! $ids ) {
			return $admin ? $this->adminNote( __( 'Choose the plans to show in the block settings.', 'invoiceninja' ) ) : '';
		}
		$known = $this->sync->shown();
		$plans = array();
		foreach ( $ids as $id ) {
			if ( isset( $known[ $id ] ) ) {
				$alt     = $pairs[ $id ] ?? '';
				$plans[] = array( $known[ $id ], '' !== $alt && isset( $known[ $alt ] ) ? $known[ $alt ] : null );
			}
		}
		if ( ! $plans ) {
			if ( ! $this->sync->cache()['at'] ) {
				$this->plugin->queue->push( SubscriptionSync::HOOK, array(), 0, true );
			}
			return $admin
				? $this->adminNote( __( "These plans are hidden from this site, aren't in Invoice Ninja, or haven't synced yet. Check Settings → Subscriptions.", 'invoiceninja' ) )
				: '<div class="invoiceninja-pricing invoiceninja-empty"><p>' . esc_html__( 'Plans are not available right now. Please check back soon.', 'invoiceninja' ) . '</p></div>';
		}
		$this->enqueue();
		$toggle = array_filter( array_column( $plans, 1 ) );
		$label  = sanitize_text_field( (string) ( $a['buttonLabel'] ?? '' ) );
		$trial  = (bool) ( $a['showTrial'] ?? true );
		$hl     = Events::cleanId( $a['highlight'] ?? '' );
		$html   = '';
		if ( $toggle ) {
			$first = $plans[0][0];
			$alt   = reset( $toggle );
			$html .= '<div class="invoiceninja-pricing__toggle" role="group" aria-label="' . esc_attr__( 'Billing period', 'invoiceninja' ) . '" hidden data-invoiceninja-toggle>'
				. '<button type="button" class="invoiceninja-pricing__period" aria-pressed="true" data-period="base">' . esc_html( Frequency::period( (int) $first['frequency_id'] ) ) . '</button>'
				. '<button type="button" class="invoiceninja-pricing__period" aria-pressed="false" data-period="alt">' . esc_html( Frequency::period( (int) $alt['frequency_id'] ) ) . '</button></div>';
		}
		$items = '';
		foreach ( $plans as [ $base, $alt ] ) {
			$featured = '' !== $hl && ( $hl === $base['id'] || ( null !== $alt && $hl === $alt['id'] ) );
			$items   .= '<li class="invoiceninja-plan' . ( $featured ? ' is-featured' : '' ) . '">'
				. '<div class="invoiceninja-plan__body" data-period="base">' . $this->plan( $base, $label, $trial, $featured ) . '</div>'
				. ( null !== $alt ? '<div class="invoiceninja-plan__body" data-period="alt" hidden>' . $this->plan( $alt, $label, $trial, $featured ) . '</div>' : '' )
				. '</li>';
		}
		$count = count( $plans );
		return '<div class="invoiceninja-pricing' . ( 1 === $count ? ' is-single' : '' ) . '" data-invoiceninja-pricing>' . $html
			. '<ul class="invoiceninja-pricing__plans" style="--invoiceninja-columns:' . (int) min( 4, $count ) . '">' . $items . '</ul></div>';
	}

	/**
	 * One plan's card content.
	 *
	 * @param array<string, mixed> $sub      Subscription.
	 * @param string               $label    Button label.
	 * @param bool                 $trial    Show the trial.
	 * @param bool                 $featured Highlighted.
	 */
	private function plan( array $sub, string $label, bool $trial, bool $featured ): string {
		$per  = $sub['recurring'] ? Frequency::per( (int) $sub['frequency_id'] ) : '';
		$html = ( $featured ? '<p class="invoiceninja-plan__badge">' . esc_html__( 'Most popular', 'invoiceninja' ) . '</p>' : '' )
			. '<h3 class="invoiceninja-plan__name">' . esc_html( (string) $sub['name'] ) . '</h3>'
			. '<p class="invoiceninja-plan__price"><span class="invoiceninja-price">' . esc_html( $this->plugin->statics->money( (float) $sub['price'] ) ) . '</span>'
			. ( '' !== $per ? ' <span class="invoiceninja-plan__per">' . esc_html( $per ) . '</span>' : '' ) . '</p>';
		if ( $trial && $sub['trial_enabled'] && (int) $sub['trial_days'] > 0 ) {
			/* translators: %d: number of days */
			$html .= '<p class="invoiceninja-plan__trial">' . esc_html( sprintf( _n( '%d-day free trial', '%d-day free trial', (int) $sub['trial_days'], 'invoiceninja' ), (int) $sub['trial_days'] ) ) . '</p>';
		}
		$text = '' !== $label ? $label : ( $sub['trial_enabled'] && (int) $sub['trial_days'] > 0 ? __( 'Start free trial', 'invoiceninja' ) : __( 'Subscribe', 'invoiceninja' ) );
		return $html . '<p class="invoiceninja-plan__action"><a class="wp-element-button invoiceninja-subscribe" rel="nofollow" href="' . esc_url( self::subscribeUrl( (string) $sub['id'] ) ) . '">' . esc_html( $text )
			/* translators: %s: plan name */
			. '<span class="invoiceninja-sr"> ' . esc_html( sprintf( __( '(%s)', 'invoiceninja' ), (string) $sub['name'] ) ) . '</span></a></p>';
	}

	/**
	 * Subscribe Button.
	 *
	 * @param array<string, mixed> $a subscriptionId, label, showPrice.
	 */
	public function subscribeButton( array $a ): string {
		$id    = Events::cleanId( $a['subscriptionId'] ?? ( $a['id'] ?? '' ) );
		$state = $this->placeholder();
		if ( null !== $state ) {
			return $state;
		}
		$sub = '' !== $id ? $this->sync->findShown( $id ) : null;
		if ( null === $sub ) {
			if ( ! current_user_can( 'edit_posts' ) ) {
				return '';
			}
			return $this->adminNote( '' !== $id && null !== $this->sync->find( $id ) ? __( 'This subscription is hidden from this site. Show it in Settings → Subscriptions.', 'invoiceninja' ) : __( 'Choose a subscription in the block settings.', 'invoiceninja' ) );
		}
		$this->enqueue();
		$label = sanitize_text_field( (string) ( $a['label'] ?? '' ) );
		$price = '';
		if ( ! empty( $a['showPrice'] ) ) {
			$per   = $sub['recurring'] ? Frequency::per( (int) $sub['frequency_id'] ) : '';
			$price = '<span class="invoiceninja-subscribe-block__price"><span class="invoiceninja-price">' . esc_html( $this->plugin->statics->money( (float) $sub['price'] ) ) . '</span>' . ( '' !== $per ? ' ' . esc_html( $per ) : '' ) . '</span> ';
		}
		return '<div class="invoiceninja-subscribe-block">' . $price . '<a class="wp-element-button invoiceninja-subscribe" rel="nofollow" href="' . esc_url( self::subscribeUrl( $id ) ) . '">'
			. esc_html( '' !== $label ? $label : __( 'Subscribe', 'invoiceninja' ) ) . '</a></div>';
	}

	/**
	 * Admin-only placeholder while not connected.
	 */
	private function placeholder(): ?string {
		if ( 'none' !== $this->plugin->credentials->source() ) {
			return null;
		}
		if ( ! current_user_can( \InvoiceNinja\Core\Capabilities::MANAGE ) ) {
			return '';
		}
		return $this->adminNote( __( 'Connect Invoice Ninja to show your subscriptions here.', 'invoiceninja' ) );
	}

	/**
	 * A note only editors see.
	 *
	 * @param string $text Text.
	 */
	private function adminNote( string $text ): string {
		wp_enqueue_style( Store::HANDLE );
		return '<div class="invoiceninja-notice invoiceninja-notice--admin"><strong>' . esc_html__( 'Only you can see this.', 'invoiceninja' ) . '</strong> ' . esc_html( $text ) . '</div>';
	}
}
