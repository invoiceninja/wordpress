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
	 * The merchant's per-plan text from the planDetails attribute: {id: {name, description,
	 * perks}}, perks one per line. Everything is optional and nothing has a default.
	 *
	 * @param mixed $value Attribute.
	 * @return array<string, array{name: string, description: string, perks: list<string>}>
	 */
	public static function details( mixed $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $id => $row ) {
			if ( '' === Events::cleanId( (string) $id ) || Events::cleanId( (string) $id ) !== (string) $id || ! is_array( $row ) ) {
				continue;
			}
			$text  = static fn( string $key ): string => is_scalar( $row[ $key ] ?? null ) ? (string) $row[ $key ] : '';
			$perks = array();
			foreach ( preg_split( '/\R/u', $text( 'perks' ) ) ?: array() as $line ) {
				$line = mb_substr( trim( sanitize_text_field( (string) $line ) ), 0, 200 );
				if ( '' !== $line ) {
					$perks[] = $line;
				}
			}
			$out[ (string) $id ] = array(
				'name'        => mb_substr( trim( sanitize_text_field( $text( 'name' ) ) ), 0, 100 ),
				'description' => mb_substr( trim( sanitize_textarea_field( $text( 'description' ) ) ), 0, 500 ),
				'perks'       => array_slice( $perks, 0, 12 ),
			);
		}
		return $out;
	}

	/**
	 * What a monthly / yearly pair saves: 12 × the monthly price − the yearly price, from the
	 * synced prices. Null unless one is monthly and the other yearly, both in the same currency,
	 * and the yearly one is cheaper.
	 *
	 * @param array<string, mixed> $a One plan of the pair.
	 * @param array<string, mixed> $b The other.
	 * @return array{yearly_id: string, amount: float, months: int, percent: float}|null Months is 0 unless the saving is a whole number of monthly payments.
	 */
	public static function savings( array $a, array $b ): ?array {
		$by = array(
			(int) ( $a['frequency_id'] ?? 0 ) => $a,
			(int) ( $b['frequency_id'] ?? 0 ) => $b,
		);
		if ( ! isset( $by[5], $by[10] ) || (string) ( $a['currency_id'] ?? '' ) !== (string) ( $b['currency_id'] ?? '' ) ) {
			return null;
		}
		$monthly = (float) $by[5]['price'];
		$amount  = round( 12 * $monthly - (float) $by[10]['price'], 2 );
		if ( $monthly <= 0 || $amount < 0.01 ) {
			return null;
		}
		$months = $amount / $monthly;
		return array(
			'yearly_id' => (string) $by[10]['id'],
			'amount'    => $amount,
			'months'    => abs( $months - round( $months ) ) < 0.0001 ? (int) round( $months ) : 0,
			'percent'   => $amount / ( 12 * $monthly ) * 100,
		);
	}

	/**
	 * The hint on the Yearly toggle: "N months free" when every pair saves the same whole number
	 * of monthly payments, otherwise "save up to N%" (rounded down, so never more than the
	 * best saving), and '' when nothing is saved.
	 *
	 * @param list<array{yearly_id: string, amount: float, months: int, percent: float}|null> $savings One per paired plan.
	 */
	public static function toggleHint( array $savings ): string {
		$found = array_filter( $savings );
		if ( ! $found ) {
			return '';
		}
		$months = array_unique( array_column( $found, 'months' ) );
		$first  = (int) reset( $months );
		if ( count( $found ) === count( $savings ) && 1 === count( $months ) && $first > 0 ) {
			/* translators: %d: number of months */
			return sprintf( _n( '%d month free', '%d months free', $first, 'invoiceninja' ), $first );
		}
		$percent = (int) floor( max( array_column( $found, 'percent' ) ) );
		/* translators: %d: percentage */
		return $percent >= 1 ? sprintf( __( 'save up to %d%%', 'invoiceninja' ), $percent ) : '';
	}

	/**
	 * Pricing Table.
	 *
	 * @param array<string, mixed> $a ids ("a,b"), yearly ("a:x" pairs), highlight, buttonLabel, showTrial, planDetails.
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
		$toggle  = array_filter( array_column( $plans, 1 ) );
		$label   = sanitize_text_field( (string) ( $a['buttonLabel'] ?? '' ) );
		$trial   = (bool) ( $a['showTrial'] ?? true );
		$hl      = Events::cleanId( $a['highlight'] ?? '' );
		$details = self::details( $a['planDetails'] ?? array() );
		// Savings are shown in the company's currency, so only for plans priced in it.
		$company = (string) ( $this->plugin->state->get()['company']['currency_id'] ?? '' );
		$saved   = array();
		foreach ( $plans as $i => [ $base, $alt ] ) {
			if ( null !== $alt ) {
				$cur         = (string) ( $base['currency_id'] ?? '' );
				$saved[ $i ] = '' === $cur || '' === $company || $cur === $company ? self::savings( $base, $alt ) : null;
			}
		}
		$html = '';
		if ( $toggle ) {
			$first = $plans[0][0];
			$alt   = reset( $toggle );
			$hint  = self::toggleHint( array_values( $saved ) );
			$html .= '<div class="invoiceninja-pricing__toggle" role="group" aria-label="' . esc_attr__( 'Billing period', 'invoiceninja' ) . '" hidden data-invoiceninja-toggle>';
			foreach ( array(
				'base' => (int) $first['frequency_id'],
				'alt'  => (int) $alt['frequency_id'],
			) as $period => $frequency ) {
				$text  = Frequency::period( $frequency );
				$html .= '<button type="button" class="invoiceninja-pricing__period" aria-pressed="' . ( 'base' === $period ? 'true' : 'false' ) . '" data-period="' . $period . '">' . esc_html( $text )
					. ( 10 === $frequency && '' !== $hint ? '<span class="invoiceninja-pricing__hint"> · ' . esc_html( $hint ) . '</span>' : '' ) . '</button>';
			}
			$html .= '</div>';
		}
		$items = '';
		foreach ( $plans as $i => [ $base, $alt ] ) {
			$featured = '' !== $hl && ( $hl === $base['id'] || ( null !== $alt && $hl === $alt['id'] ) );
			$own      = $details[ (string) $base['id'] ] ?? null;
			$saving   = $saved[ $i ] ?? null;
			$items   .= '<li class="invoiceninja-plan' . ( $featured ? ' is-featured' : '' ) . '">'
				. '<div class="invoiceninja-plan__body" data-period="base">' . $this->plan( $base, $label, $trial, $featured, $own, null !== $saving && $saving['yearly_id'] === (string) $base['id'] ? $saving['amount'] : 0.0 ) . '</div>'
				// A paired plan's other period shares the card's text unless it has its own.
				. ( null !== $alt ? '<div class="invoiceninja-plan__body" data-period="alt" hidden>' . $this->plan( $alt, $label, $trial, $featured, self::merge( $details[ (string) $alt['id'] ] ?? null, $own ), null !== $saving && $saving['yearly_id'] === (string) $alt['id'] ? $saving['amount'] : 0.0 ) . '</div>' : '' )
				. '</li>';
		}
		$count = count( $plans );
		return '<div class="invoiceninja-pricing' . ( 1 === $count ? ' is-single' : '' ) . '" data-invoiceninja-pricing>' . $html
			. '<ul class="invoiceninja-pricing__plans" role="list" aria-label="' . esc_attr__( 'Plans', 'invoiceninja' ) . '" style="--invoiceninja-columns:' . (int) min( 4, $count ) . '">' . $items . '</ul></div>';
	}

	/**
	 * A plan's own text, falling back field by field to its pair's.
	 *
	 * @param array{name: string, description: string, perks: list<string>}|null $own      Its own.
	 * @param array{name: string, description: string, perks: list<string>}|null $fallback The pair's.
	 * @return array{name: string, description: string, perks: list<string>}|null
	 */
	private static function merge( ?array $own, ?array $fallback ): ?array {
		if ( null === $own || null === $fallback ) {
			return $own ?? $fallback;
		}
		return array(
			'name'        => '' !== $own['name'] ? $own['name'] : $fallback['name'],
			'description' => '' !== $own['description'] ? $own['description'] : $fallback['description'],
			'perks'       => $own['perks'] ? $own['perks'] : $fallback['perks'],
		);
	}

	/**
	 * One plan's card content: five parts in the same order on every card (name, description,
	 * price, perks, button), empty ones included, so the cards line up row by row.
	 *
	 * @param array<string, mixed>                                              $sub      Subscription.
	 * @param string                                                            $label    Button label.
	 * @param bool                                                              $trial    Show the trial.
	 * @param bool                                                              $featured Highlighted.
	 * @param array{name: string, description: string, perks: list<string>}|null $details The merchant's text.
	 * @param float                                                             $saving   What this (yearly) plan saves a year, 0 for none.
	 */
	private function plan( array $sub, string $label, bool $trial, bool $featured, ?array $details = null, float $saving = 0.0 ): string {
		$per  = $sub['recurring'] ? Frequency::per( (int) $sub['frequency_id'] ) : '';
		$name = null !== $details && '' !== $details['name'] ? $details['name'] : (string) $sub['name'];
		$html = '<div class="invoiceninja-plan__head"><h3 class="invoiceninja-plan__name">' . esc_html( $name ) . '</h3>'
			// Said in words, after the name, so it isn't only the heavier border.
			. ( $featured ? '<p class="invoiceninja-plan__badge">' . esc_html__( 'Most popular', 'invoiceninja' ) . '</p>' : '' ) . '</div>';
		$html .= null !== $details && '' !== $details['description']
			? '<p class="invoiceninja-plan__description">' . nl2br( esc_html( $details['description'] ) ) . '</p>'
			: '<div class="invoiceninja-plan__description"></div>';
		$html .= '<div class="invoiceninja-plan__pricing"><p class="invoiceninja-plan__price"><span class="invoiceninja-price">' . esc_html( $this->plugin->statics->money( (float) $sub['price'] ) ) . '</span>'
			. ( '' !== $per ? ' <span class="invoiceninja-plan__per">' . esc_html( $per ) . '</span>' : '' ) . '</p>';
		if ( $saving > 0 ) {
			/* translators: %s: amount of money */
			$html .= '<p class="invoiceninja-plan__saving">' . esc_html( sprintf( __( 'Save %s a year', 'invoiceninja' ), $this->plugin->statics->money( $saving ) ) ) . '</p>';
		}
		if ( $trial && $sub['trial_enabled'] && (int) $sub['trial_days'] > 0 ) {
			/* translators: %d: number of days */
			$html .= '<p class="invoiceninja-plan__trial">' . esc_html( sprintf( _n( '%d-day free trial', '%d-day free trial', (int) $sub['trial_days'], 'invoiceninja' ), (int) $sub['trial_days'] ) ) . '</p>';
		}
		$html .= '</div>';
		if ( null !== $details && $details['perks'] ) {
			$check = '<svg class="invoiceninja-plan__check" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
			$html .= '<ul class="invoiceninja-plan__perks" role="list">';
			foreach ( $details['perks'] as $perk ) {
				$html .= '<li>' . $check . '<span>' . esc_html( $perk ) . '</span></li>';
			}
			$html .= '</ul>';
		} else {
			$html .= '<div class="invoiceninja-plan__perks"></div>';
		}
		$text = '' !== $label ? $label : ( $sub['trial_enabled'] && (int) $sub['trial_days'] > 0 ? __( 'Start free trial', 'invoiceninja' ) : __( 'Subscribe', 'invoiceninja' ) );
		return $html . '<p class="invoiceninja-plan__action"><a class="wp-element-button invoiceninja-subscribe" rel="nofollow" href="' . esc_url( self::subscribeUrl( (string) $sub['id'] ) ) . '">' . esc_html( $text )
			/* translators: %s: plan name */
			. '<span class="invoiceninja-sr"> ' . esc_html( sprintf( __( '(%s)', 'invoiceninja' ), $name ) ) . '</span></a></p>';
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
		// Like the Pricing Table: a plan with a free trial says so on its button.
		$trial = $sub['trial_enabled'] && (int) $sub['trial_days'] > 0;
		return '<div class="invoiceninja-subscribe-block">' . $price . '<a class="wp-element-button invoiceninja-subscribe" rel="nofollow" href="' . esc_url( self::subscribeUrl( $id ) ) . '">'
			. esc_html( '' !== $label ? $label : ( $trial ? __( 'Start free trial', 'invoiceninja' ) : __( 'Subscribe', 'invoiceninja' ) ) ) . '</a></div>';
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
