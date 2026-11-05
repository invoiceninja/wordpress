<?php
/**
 * The Quote Request Form block and shortcode, and what happens when it's sent.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Forms;

use InvoiceNinja\Commerce\Checkout;
use InvoiceNinja\Core\LogLevel;
use InvoiceNinja\Plugin;
use InvoiceNinja\Rest\Routes;

/**
 * The form is plain server-rendered HTML (cache friendly) with its config signed into it;
 * assets/forms/forms.js sends it to POST /quote-requests and shows errors and the
 * confirmation in place. Spam protection: a honeypot, a minimum time to fill it in (from the
 * time signed into the token, which forms.js refreshes when someone starts typing, so cached
 * pages work), a maximum token age, rate limits per address and per email, a site-wide limit
 * past which requests are held, Akismet when it's active, and the invoiceninja_captcha filter.
 * Every accepted request is saved as a lead before anything is sent to Invoice Ninja.
 */
final class QuoteForm {

	public const HANDLE = 'invoiceninja-quote-form';

	public const PER_IP_HOUR = 10;

	public const PER_EMAIL_HOUR = 3;

	public const PER_SITE_HOUR = 200;

	/** Past PER_SITE_HOUR requests are held for review; past this many times it, refused. */
	public const HOLD_FACTOR = 5;

	/** Seconds a form token is good for (forms.js asks for a fresh one when someone starts typing). */
	public const MAX_AGE = 43200;

	/**
	 * Forms rendered on this page, for unique ids.
	 *
	 * @var int
	 */
	private static int $count = 0;

	/**
	 * Constructor.
	 *
	 * @param Plugin       $plugin  Plugin.
	 * @param Leads        $leads   Leads.
	 * @param QuoteCreator $creator Quote creator.
	 * @param LeadEmails   $emails  Emails.
	 */
	public function __construct( private Plugin $plugin, private Leads $leads, private QuoteCreator $creator, private LeadEmails $emails ) {}

	/**
	 * Register the front-end script.
	 */
	public function registerAssets(): void {
		$url = plugins_url( '', $this->plugin->file );
		wp_register_style( self::HANDLE, $url . '/assets/forms/forms.css', array( \InvoiceNinja\Frontend\Store::HANDLE ), INVOICENINJA_VERSION );
		wp_register_script(
			self::HANDLE,
			$url . '/assets/forms/forms.js',
			array( \InvoiceNinja\Frontend\Store::HANDLE ),
			INVOICENINJA_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Products offered when a block chooses none (Settings > Forms).
	 *
	 * @return list<string>
	 */
	public function defaultProducts(): array {
		return FormConfig::ids( (string) $this->plugin->options->setting( 'forms.products' ) );
	}

	/**
	 * A signed form token (also used by tests to post without rendering a page).
	 *
	 * @param array<string, mixed> $config Config.
	 * @param int                  $time   Render time.
	 */
	public function token( array $config, int $time ): string {
		return FormConfig::sign( $config, $time, $this->plugin->secrets->get( 'secret' ) );
	}

	/**
	 * Render the block or shortcode.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 */
	public function render( array $attributes ): string {
		if ( 'none' === $this->plugin->credentials->source() ) {
			if ( ! current_user_can( \InvoiceNinja\Core\Capabilities::MANAGE ) ) {
				return '';
			}
			return '<div class="invoiceninja-notice invoiceninja-notice--admin"><strong>' . esc_html__( 'Only you can see this.', 'invoiceninja' ) . '</strong> ' . esc_html__( 'Connect Invoice Ninja to start taking quote requests.', 'invoiceninja' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=invoiceninja-setup' ) ) . '">' . esc_html__( 'Connect Invoice Ninja', 'invoiceninja' ) . '</a></div>';
		}
		$this->plugin->store->enqueue();
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
		static $data = false;
		if ( ! $data ) {
			$data = true;
			wp_add_inline_script( self::HANDLE, 'window.invoiceNinjaQuoteForm = ' . wp_json_encode( array( 'i18n' => $this->strings() ) ) . ';', 'before' );
		}

		$config  = FormConfig::fromAttributes( $attributes, $this->defaultProducts() );
		$labels  = FormConfig::labels();
		$prefix  = 'invoiceninja-qf' . ( ++self::$count ) . '-';
		$heading = trim( (string) ( $attributes['heading'] ?? '' ) );
		$submit  = trim( (string) ( $attributes['submitLabel'] ?? '' ) );
		$html    = '';
		$auto    = array(
			'first_name' => 'given-name',
			'last_name'  => 'family-name',
			'email'      => 'email',
			'phone'      => 'tel',
		);
		foreach ( FormConfig::FIELDS as $name => $unused ) {
			unset( $unused );
			$field = $config['fields'][ $name ];
			if ( ! $field['show'] ) {
				continue;
			}
			$id    = $prefix . $name;
			$attrs = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" aria-describedby="' . esc_attr( $id . '-error' ) . '"' . ( $field['required'] ? ' required aria-required="true"' : '' );
			$label = '<label for="' . esc_attr( $id ) . '">' . esc_html( $labels[ $name ] ) . ( $field['required'] ? '' : ' <span class="invoiceninja-muted">' . esc_html__( '(optional)', 'invoiceninja' ) . '</span>' ) . '</label>';
			if ( 'message' === $name ) {
				$control = '<textarea' . $attrs . ' rows="5" maxlength="' . (int) FormConfig::MAX_LENGTH[ $name ] . '"></textarea>';
			} elseif ( 'product' === $name ) {
				$control = '<select' . $attrs . '><option value="">' . esc_html__( 'Choose…', 'invoiceninja' ) . '</option>';
				foreach ( $config['products'] as $product_id ) {
					$info = $this->plugin->cart->product( $product_id );
					if ( null !== $info ) {
						$control .= '<option value="' . esc_attr( $product_id ) . '">' . esc_html( $info['title'] ) . '</option>';
					}
				}
				$control .= '<option value="' . esc_attr( FormConfig::NOT_SURE ) . '">' . esc_html__( 'Not sure yet', 'invoiceninja' ) . '</option></select>';
			} else {
				$type    = 'email' === $name ? 'email' : ( 'phone' === $name ? 'tel' : 'text' );
				$control = '<input type="' . $type . '"' . $attrs . ' autocomplete="' . esc_attr( $auto[ $name ] ) . '" maxlength="' . (int) FormConfig::MAX_LENGTH[ $name ] . '">';
			}
			$html .= '<p class="invoiceninja-field invoiceninja-field--' . esc_attr( str_replace( '_', '-', $name ) ) . '" data-field="' . esc_attr( $name ) . '">' . $label . $control . '<span class="invoiceninja-field__error" id="' . esc_attr( $id . '-error' ) . '" hidden></span></p>';
		}
		foreach ( array( 'privacy', 'terms' ) as $which ) {
			$page = $this->consentPage( (int) $config[ $which ]['page'] );
			if ( null === $page ) {
				continue;
			}
			$id   = $prefix . $which;
			$link = '<a href="' . esc_url( $page['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $page['title'] ) . '<span class="invoiceninja-sr"> ' . esc_html__( '(opens in a new tab)', 'invoiceninja' ) . '</span></a>';
			$text = 'privacy' === $which
				/* translators: %s: link to the privacy policy page */
				? sprintf( esc_html__( 'I have read the %s.', 'invoiceninja' ), $link )
				/* translators: %s: link to the terms page */
				: sprintf( esc_html__( 'I agree to the %s.', 'invoiceninja' ), $link );
			$req   = $config[ $which ]['required'];
			$html .= '<p class="invoiceninja-check" data-field="' . esc_attr( $which ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $which ) . '" value="1" aria-describedby="' . esc_attr( $id . '-error' ) . '"' . ( $req ? ' required aria-required="true"' : '' ) . '> <label for="' . esc_attr( $id ) . '">' . $text . ( $req ? '' : ' <span class="invoiceninja-muted">' . esc_html__( '(optional)', 'invoiceninja' ) . '</span>' ) . '</label><span class="invoiceninja-field__error" id="' . esc_attr( $id . '-error' ) . '" hidden></span></p>';
		}
		$days  = (int) $this->plugin->options->setting( 'forms.reply_days' );
		$reply = $days > 0
			/* translators: %d: number of days */
			? sprintf( _n( "We'll reply within %d day.", "We'll reply within %d days.", $days, 'invoiceninja' ), $days )
			: '';
		return '<div class="invoiceninja-quote-form" data-invoiceninja-quote-form>'
			. ( '' !== $heading ? '<h2 class="invoiceninja-heading">' . esc_html( $heading ) . '</h2>' : '' )
			. '<form class="invoiceninja-quote-form__form" method="post" action="' . esc_url( rest_url( Routes::NS . '/quote-requests' ) ) . '" novalidate>'
			. '<div class="invoiceninja-quote-form__summary" tabindex="-1" hidden></div>'
			. $html
			// Spam trap: people never see or fill it, and its name is one autofill and password managers leave alone.
			. '<p class="invoiceninja-sr" aria-hidden="true"><label for="' . esc_attr( $prefix . 'trap' ) . '">' . esc_html__( 'Leave this empty', 'invoiceninja' ) . '</label><input type="text" id="' . esc_attr( $prefix . 'trap' ) . '" name="' . esc_attr( Routes::TRAP ) . '" tabindex="-1" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore></p>'
			. '<input type="hidden" name="form" value="' . esc_attr( $this->token( $config, time() ) ) . '">'
			. '<p class="invoiceninja-actions"><button type="submit" class="wp-element-button">' . esc_html( '' !== $submit ? $submit : __( 'Send request', 'invoiceninja' ) ) . '</button></p>'
			. ( '' !== $reply ? '<p class="invoiceninja-muted invoiceninja-quote-form__reply">' . esc_html( $reply ) . '</p>' : '' )
			. '<noscript><p>' . esc_html__( 'This form needs JavaScript. Please turn it on, or email us instead.', 'invoiceninja' ) . '</p></noscript>'
			. '</form>'
			. '<div class="invoiceninja-quote-form__done" tabindex="-1" hidden></div>'
			. '</div>';
	}

	/**
	 * Whether a token's time allows a submission now (pure, for tests): "ok", "too_fast" or
	 * "expired". The time is signed into the token, so the minimum is enforced here, not by
	 * the browser.
	 *
	 * @param int $time Time in the token.
	 * @param int $min  Minimum seconds.
	 * @param int $now  Now.
	 */
	public static function age( int $time, int $min, int $now ): string {
		$age = $now - $time;
		if ( $age < $min ) {
			return 'too_fast';
		}
		return $age > self::MAX_AGE ? 'expired' : 'ok';
	}

	/**
	 * Whether the spam trap was filled in. Only the trap's own name counts: an old cached
	 * page's "website" field is ignored (autofill and password managers fill it for people),
	 * and a request without the trap counts as empty.
	 *
	 * @param array<string, mixed> $input Submitted values.
	 */
	public static function trapped( array $input ): bool {
		return Checkout::trapped( array( 'trap' => $input[ Routes::TRAP ] ?? '' ) );
	}

	/**
	 * POST /quote-requests/start {form}: a fresh token for a form someone started filling in.
	 * The rendered token may come from a cached page hours old; this one starts the clock
	 * now. Signing only: nothing is stored.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function start( \WP_REST_Request $request ): \WP_REST_Response {
		$form = FormConfig::verify( (string) ( is_scalar( $request->get_param( 'form' ) ) ? $request->get_param( 'form' ) : '' ), $this->plugin->secrets->get( 'secret' ) );
		if ( null === $form ) {
			return Routes::fail( 'form_changed', __( 'This form has changed since the page loaded. Reload the page and try again.', 'invoiceninja' ), 400 );
		}
		$response = new \WP_REST_Response( array( 'form' => $this->token( $form['config'], time() ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * POST /quote-requests.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function submit( \WP_REST_Request $request ): \WP_REST_Response {
		$input  = $request->get_json_params();
		$input  = is_array( $input ) && $input ? $input : $request->get_body_params();
		$input  = is_array( $input ) ? $input : array();
		$ip     = Routes::clientIp();
		$thanks = $this->thanks();
		// Bots that fill every field get the same reply as people, and nothing is saved.
		if ( self::trapped( $input ) ) {
			$this->plugin->logger->log( LogLevel::Info, 'forms', 'Quote request dropped: spam trap filled' );
			return new \WP_REST_Response( $thanks );
		}
		$form = FormConfig::verify( (string) ( is_scalar( $input['form'] ?? '' ) ? $input['form'] : '' ), $this->plugin->secrets->get( 'secret' ) );
		if ( null === $form ) {
			return Routes::fail( 'form_changed', __( 'This form has changed since the page loaded. Reload the page and try again.', 'invoiceninja' ), 400 );
		}
		/**
		 * Seconds a person needs at least to fill in the Quote Request Form.
		 *
		 * @since 2.0.0
		 * @param int $seconds Seconds.
		 */
		$min = max( 0, (int) apply_filters( 'invoiceninja_quote_form_min_seconds', 3 ) );
		$age = self::age( $form['time'], $min, time() );
		if ( 'too_fast' === $age ) {
			return Routes::fail( 'too_fast', __( 'That was quick! Please check your details, then send again.', 'invoiceninja' ), 422 );
		}
		if ( 'expired' === $age ) {
			// Open for hours, or served from an old page cache: a fresh token, and the person sends again.
			$response = Routes::fail( 'form_expired', __( 'This form was open for a long time. Please check your details, then send again.', 'invoiceninja' ), 422 );
			$response->set_data( (array) $response->get_data() + array( 'form' => $this->token( $form['config'], time() ) ) );
			return $response;
		}
		/**
		 * Whether the visitor passed a CAPTCHA on the Quote Request Form. There is none by
		 * default; a CAPTCHA plugin returns false to refuse the request. Checkout and Pay
		 * Invoice have their own filters (invoiceninja_checkout_captcha and
		 * invoiceninja_pay_invoice_captcha).
		 *
		 * @since 2.0.0
		 * @param bool                 $passed  Passed.
		 * @param array<string, mixed> $input   Submitted values.
		 * @param string               $context quote_form.
		 */
		if ( ! apply_filters( 'invoiceninja_captcha', true, $input, 'quote_form' ) ) {
			return Routes::fail( 'captcha', __( 'Please complete the check to show you are not a robot.', 'invoiceninja' ), 422 );
		}
		$result = FormConfig::validate( $form['config'], $input );
		if ( $result['errors'] ) {
			$response = Routes::fail( 'invalid', __( 'Please fix the highlighted fields.', 'invoiceninja' ), 422 );
			$response->set_data( (array) $response->get_data() + array( 'errors' => $result['errors'] ) );
			return $response;
		}
		$values  = $result['values'];
		$hour    = (int) floor( time() / HOUR_IN_SECONDS );
		$limited = $this->plugin->options->increment( 'ctr_quote_' . substr( md5( Checkout::ipBucket( $ip ) ), 0, 16 ) . '_' . $hour ) > self::PER_IP_HOUR
			|| $this->plugin->options->increment( 'ctr_quotemail_' . substr( md5( $values['email'] ), 0, 16 ) . '_' . $hour ) > self::PER_EMAIL_HOUR;
		$site    = $limited ? 0 : $this->plugin->options->increment( 'ctr_quotesite_' . $hour );
		if ( $limited || $site > self::PER_SITE_HOUR * self::HOLD_FACTOR ) {
			$response = Routes::fail( 'rate_limited', __( "We've had several requests from you in the last hour. Please try again later.", 'invoiceninja' ), 429 );
			$response->header( 'Retry-After', (string) HOUR_IN_SECONDS );
			return $response;
		}
		// Past the site-wide limit (a flood from many addresses), requests are still saved, so a
		// real person isn't turned away, but held: nothing goes to Invoice Ninja and no email is sent.
		$busy = $site > self::PER_SITE_HOUR;
		if ( '' !== $values['product'] ) {
			$info                    = FormConfig::NOT_SURE === $values['product'] ? null : $this->plugin->cart->product( $values['product'] );
			$values['product_title'] = FormConfig::NOT_SURE === $values['product'] ? __( 'Not sure yet', 'invoiceninja' ) : ( null !== $info ? $info['title'] : $values['product'] );
		}
		$consents = array();
		foreach ( $result['consents'] as $which => $agreed ) {
			$page               = $this->consentPage( (int) $form['config'][ $which ]['page'] );
			$consents[ $which ] = array(
				'agreed' => $agreed,
				'at'     => time(),
				'page'   => (int) $form['config'][ $which ]['page'],
				'title'  => null !== $page ? $page['title'] : '',
				'url'    => null !== $page ? $page['url'] : '',
			);
		}
		$source = $this->source( $request );
		$spam   = $busy || $this->isSpam( $values, $ip, $request, $source );
		$id     = $this->leads->create( $values, $consents, $spam ? 'spam' : 'queued', $source );
		if ( 0 === $id ) {
			return Routes::fail( 'not_saved', __( "We couldn't save your request. Please try again, or email us.", 'invoiceninja' ), 500 );
		}
		if ( $busy ) {
			$this->leads->update(
				$id,
				array(
					'reason'   => 'busy', // Shown by FormsModule in the admin's language.
					'notified' => true,
				)
			);
			if ( self::PER_SITE_HOUR + 1 === $site ) {
				$this->plugin->logger->warning( 'forms', sprintf( 'More than %d quote requests in an hour: holding the rest of this hour for review', self::PER_SITE_HOUR ) );
			}
		}
		$lead = (array) $this->leads->find( $id );
		$this->plugin->logger->log( $spam ? LogLevel::Warning : LogLevel::Info, 'forms', $spam ? sprintf( 'Quote request %d held: %s', $id, $busy ? 'too many this hour' : 'Akismet says spam' ) : sprintf( 'Quote request %d received', $id ), array(), 'lead', (string) $id );
		if ( ! $spam ) {
			$this->creator->queue( $id );
		}
		if ( ! $busy ) {
			$this->emails->received( $lead );
			$this->emails->autoReply( $lead );
		}
		/**
		 * Fires when a quote request was saved.
		 *
		 * @since 2.0.0
		 * @param array $lead Lead.
		 */
		do_action( 'invoiceninja_lead_received', $lead );
		return new \WP_REST_Response( $thanks );
	}

	/**
	 * The success reply.
	 *
	 * @return array{ok: bool, title: string, message: string, redirect: string}
	 */
	private function thanks(): array {
		$days     = (int) $this->plugin->options->setting( 'forms.reply_days' );
		$redirect = FormConfig::redirectUrl( (string) $this->plugin->options->setting( 'forms.redirect_url' ) );
		return array(
			'ok'       => true,
			'title'    => __( 'Thanks! We received your request.', 'invoiceninja' ),
			'message'  => $days > 0
				/* translators: %d: number of days */
				? sprintf( _n( "We'll reply within %d day.", "We'll reply within %d days.", $days, 'invoiceninja' ), $days )
				: __( "We'll reply soon.", 'invoiceninja' ),
			'redirect' => $redirect,
		);
	}

	/**
	 * A published consent page.
	 *
	 * @param int $page_id Page id.
	 * @return array{title: string, url: string}|null
	 */
	private function consentPage( int $page_id ): ?array {
		if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
			return null;
		}
		return array(
			'title' => wp_strip_all_tags( get_the_title( $page_id ) ),
			'url'   => (string) get_permalink( $page_id ),
		);
	}

	/**
	 * The page the form was sent from (this site's pages only).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function source( \WP_REST_Request $request ): string {
		$referer = (string) ( $request->get_header( 'referer' ) ?? '' );
		$host    = (string) wp_parse_url( (string) home_url(), PHP_URL_HOST );
		if ( '' === $referer || strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) ) !== strtolower( $host ) ) {
			return '';
		}
		return esc_url_raw( strtok( $referer, '#' ) ?: '' );
	}

	/**
	 * Ask Akismet, when it's active and set up.
	 *
	 * @param array<string, string> $values  Values.
	 * @param string                $ip      Visitor IP.
	 * @param \WP_REST_Request      $request Request.
	 * @param string                $source  Page.
	 */
	private function isSpam( array $values, string $ip, \WP_REST_Request $request, string $source ): bool {
		$spam = false;
		if ( class_exists( 'Akismet' ) && is_callable( array( 'Akismet', 'get_api_key' ) ) && call_user_func( array( 'Akismet', 'get_api_key' ) ) ) {
			$query    = call_user_func(
				array( 'Akismet', 'build_query' ),
				array(
					'blog'                 => home_url(),
					'user_ip'              => $ip,
					'user_agent'           => (string) ( $request->get_header( 'user_agent' ) ?? '' ),
					'referrer'             => $source,
					'permalink'            => $source,
					'comment_type'         => 'contact-form',
					'comment_author'       => trim( $values['first_name'] . ' ' . $values['last_name'] ),
					'comment_author_email' => $values['email'],
					'comment_content'      => $values['message'],
					'blog_lang'            => get_locale(),
					'blog_charset'         => get_option( 'blog_charset' ),
				)
			);
			$response = call_user_func( array( 'Akismet', 'http_post' ), $query, 'comment-check' );
			$spam     = is_array( $response ) && 'true' === trim( (string) ( $response[1] ?? '' ) );
		}
		/**
		 * Whether a quote request is spam. Spam is kept (held) but not sent to Invoice Ninja.
		 *
		 * @since 2.0.0
		 * @param bool                  $spam   From Akismet, when active.
		 * @param array<string, string> $values Submitted fields.
		 */
		return (bool) apply_filters( 'invoiceninja_quote_request_spam', $spam, $values );
	}

	/**
	 * Strings for forms.js.
	 *
	 * @return array<string, string>
	 */
	private function strings(): array {
		return array(
			'sending'     => __( 'Sending…', 'invoiceninja' ),
			/* translators: %d: number of problems */
			'problems'    => __( 'There are %d problems with your request', 'invoiceninja' ),
			'problem'     => __( 'There is a problem with your request', 'invoiceninja' ),
			'error'       => __( "Something went wrong and your request wasn't sent. Please try again.", 'invoiceninja' ),
			'offline'     => __( "We couldn't reach the site. Check your connection and try again.", 'invoiceninja' ),
			'redirecting' => __( 'Taking you to the next page…', 'invoiceninja' ),
		);
	}
}
