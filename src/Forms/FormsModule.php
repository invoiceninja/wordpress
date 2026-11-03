<?php
/**
 * The Quote Request Form module.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Forms;

use InvoiceNinja\Core\Capabilities;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Module;
use InvoiceNinja\Plugin;
use InvoiceNinja\Rest\Routes;

/**
 * Turned on with Settings > modules.quote_form. Leads, their privacy export/erase and the
 * retention clean-up work even while the form is off, since saved leads still exist.
 */
final class FormsModule implements Module {

	public const AUTOREPLY_OPTION = 'forms_autoreply_text';

	/** Held (spam) quote requests are deleted after this many days, whatever the retention setting. */
	public const SPAM_DAYS = 30;

	public readonly Leads $leads;

	public readonly TagManager $tags;

	public readonly QuoteCreator $creator;

	public readonly LeadEmails $emails;

	public readonly QuoteForm $form;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {
		$this->leads   = new Leads();
		$this->tags    = new TagManager( $plugin->options );
		$this->creator = new QuoteCreator( $plugin, $this->leads, $this->tags );
		$this->emails  = new LeadEmails( $plugin, $this->leads );
		$this->form    = new QuoteForm( $plugin, $this->leads, $this->creator, $this->emails );
	}

	/**
	 * Default settings (Settings > Forms).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'reply_days'        => 2,
			'redirect_url'      => '',
			'autoreply'         => false,
			'autoreply_subject' => '',
			'tags'              => 'Quote request',
			'retention_days'    => 0,
			'products'          => '',
		);
	}

	/**
	 * Whether the form is turned on.
	 */
	public function enabled(): bool {
		return (bool) $this->plugin->options->setting( 'modules.quote_form' );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'invoiceninja_setting_defaults', static fn( array $d ): array => $d + array( 'forms' => self::defaults() ) );
		add_filter( 'pre_update_option_' . Options::PREFIX . 'settings', array( $this, 'cleanSettings' ) );
		add_action( 'init', array( $this->leads, 'registerPostType' ) );
		( new Privacy( $this->leads ) )->register();
		add_action( 'invoiceninja_daily_tasks', array( $this, 'daily' ) );
		add_action( 'invoiceninja_company_switched', array( $this->tags, 'forget' ) );
		// Jobs already queued still run (or stop) after the form is turned off.
		$this->plugin->queue->handle( QuoteCreator::HOOK, array( $this->creator, 'handle' ) );
		add_action(
			'invoiceninja_webhook_quote_approved',
			function ( $quote ): void {
				if ( is_array( $quote ) ) {
					$this->creator->quoteEvent( $quote, true );
				}
			}
		);
		add_action(
			'invoiceninja_webhook',
			function ( $entity, $event, $data ): void {
				if ( 'quote' === $entity && 'approved' !== $event && is_array( $data ) ) {
					$this->creator->quoteEvent( $data );
				}
			},
			10,
			3
		);
		add_action( 'invoiceninja_rest_routes', array( $this, 'routes' ) );
		add_filter( 'invoiceninja_admin_screens', static fn( array $s ): array => $s + array( 'invoiceninja-quotes' => 'quotes' ) );
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'invoiceninja_admin_assets', array( $this, 'adminAssets' ) );
		add_filter( 'invoiceninja_diagnostics_checks', array( $this, 'diagnostics' ) );
		if ( ! $this->enabled() ) {
			return;
		}
		$this->plugin->store->add( 'quote-form', 'invoiceninja_quote_form', array( $this->form, 'render' ) );
		add_action( 'init', array( $this->form, 'registerAssets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editorAssets' ) );
	}

	/**
	 * REST routes.
	 *
	 * @param Routes $routes Routes.
	 */
	public function routes( Routes $routes ): void {
		if ( $this->enabled() ) {
			$routes->add( '/quote-requests', 'POST', array( $this->form, 'submit' ), 'same_site' );
			$routes->add( '/quote-requests/start', 'POST', array( $this->form, 'start' ), 'same_site' );
		}
		$routes->add( '/admin/quote-requests', 'GET', array( $this, 'adminList' ), 'view' );
		$routes->add( '/admin/quote-requests/(?P<id>\\d+)/retry', 'POST', array( $this, 'adminRetry' ), 'manage' );
		$routes->add( '/admin/quote-requests/(?P<id>\\d+)', 'DELETE', array( $this, 'adminDelete' ), 'manage' );
		$routes->add( '/admin/forms', 'GET', array( $this, 'getSettings' ), 'manage' );
		$routes->add( '/admin/forms', 'POST', array( $this, 'saveSettings' ), 'manage' );
	}

	/**
	 * The Quote requests menu item.
	 */
	public function menu(): void {
		if ( ! $this->enabled() && 0 === array_sum( $this->leads->counts() ) ) {
			return; // Never used: keep the menu short.
		}
		add_submenu_page( 'invoiceninja', __( 'Quote requests', 'invoiceninja' ), __( 'Quote requests', 'invoiceninja' ), Capabilities::VIEW, 'invoiceninja-quotes', fn() => ( new \InvoiceNinja\Admin\Admin( $this->plugin ) )->render() );
	}

	/**
	 * The Forms tab and the Quote requests screen.
	 *
	 * @param string $screen Screen.
	 */
	public function adminAssets( string $screen ): void {
		if ( ! in_array( $screen, array( 'settings', 'quotes' ), true ) ) {
			return;
		}
		wp_enqueue_script( 'invoiceninja-forms-admin', $this->plugin->url() . 'assets/admin/forms.js', array( 'invoiceninja-admin' ), INVOICENINJA_VERSION, true );
		wp_set_script_translations( 'invoiceninja-forms-admin', 'invoiceninja' );
		wp_add_inline_script(
			'invoiceninja-forms-admin',
			'window.invoiceNinjaFormsAdmin = ' . wp_json_encode(
				array(
					'enabled'     => $this->enabled(),
					'leadsMode'   => (string) $this->plugin->options->setting( 'notifications.leads' ),
					'advanced'    => admin_url( 'admin.php?page=invoiceninja-settings&tab=advanced' ),
					'autoreply'   => (string) $this->plugin->options->get( self::AUTOREPLY_OPTION, '' ),
					'connected'   => 'none' !== $this->plugin->credentials->source(),
					'settingsUrl' => admin_url( 'admin.php?page=invoiceninja-settings&tab=forms' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The block's editor controls.
	 */
	public function editorAssets(): void {
		wp_enqueue_script( 'invoiceninja-forms-editor', $this->plugin->url() . 'assets/forms/editor.js', array( 'invoiceninja-blocks', 'wp-data', 'wp-core-data', 'wp-components', 'wp-element', 'wp-i18n', 'wp-api-fetch' ), INVOICENINJA_VERSION, true );
		wp_set_script_translations( 'invoiceninja-forms-editor', 'invoiceninja' );
		wp_add_inline_script( 'invoiceninja-forms-editor', 'window.invoiceNinjaFormsEditor = ' . wp_json_encode( array( 'defaultProducts' => $this->form->defaultProducts() ) ) . ';', 'before' );
	}

	/**
	 * Keep the forms settings valid however they are saved.
	 *
	 * @param mixed $value New settings.
	 * @return mixed
	 */
	public function cleanSettings( mixed $value ): mixed {
		if ( ! is_array( $value ) || ! isset( $value['forms'] ) || ! is_array( $value['forms'] ) ) {
			return $value;
		}
		$f                   = $value['forms'];
		$f['reply_days']     = min( 60, max( 0, (int) ( $f['reply_days'] ?? 2 ) ) );
		$f['retention_days'] = min( 3650, max( 0, (int) ( $f['retention_days'] ?? 0 ) ) );
		$f['redirect_url']   = FormConfig::redirectUrl( (string) ( $f['redirect_url'] ?? '' ) );
		$f['tags']           = implode( ', ', QuotePayload::tagNames( (string) ( $f['tags'] ?? '' ) ) );
		$f['products']       = implode( ',', FormConfig::ids( (string) ( $f['products'] ?? '' ) ) );
		$value['forms']      = $f;
		return $value;
	}

	/**
	 * GET /admin/forms.
	 */
	public function getSettings(): \WP_REST_Response {
		return new \WP_REST_Response( $this->settingsPayload() );
	}

	/**
	 * POST /admin/forms {forms: {...}, autoreply_text, enabled}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function saveSettings( \WP_REST_Request $request ): \WP_REST_Response {
		$forms = $request->get_param( 'forms' );
		$forms = is_array( $forms ) ? array_intersect_key( $forms, self::defaults() ) : array();
		if ( isset( $forms['redirect_url'] ) && '' !== trim( (string) $forms['redirect_url'] ) && '' === FormConfig::redirectUrl( (string) $forms['redirect_url'] ) ) {
			return Routes::fail( 'invalid_url', __( 'Enter a full web address for the redirect, starting with https://, or leave it empty.', 'invoiceninja' ), 400 );
		}
		foreach ( array(
			'reply_days'     => 60,
			'retention_days' => 3650,
		) as $key => $max ) {
			if ( isset( $forms[ $key ] ) && ( ! is_numeric( $forms[ $key ] ) || (int) $forms[ $key ] < 0 || (int) $forms[ $key ] > $max ) ) {
				/* translators: %d: maximum number of days */
				return Routes::fail( 'invalid_days', sprintf( __( 'Choose a number of days from 0 to %d.', 'invoiceninja' ), $max ), 400 );
			}
		}
		$changes = array( 'forms' => $forms );
		if ( null !== $request->get_param( 'enabled' ) ) {
			$changes['modules'] = array( 'quote_form' => (bool) $request->get_param( 'enabled' ) );
		}
		$this->plugin->options->saveSettings( $changes );
		if ( null !== $request->get_param( 'autoreply_text' ) ) {
			$this->plugin->options->set( self::AUTOREPLY_OPTION, mb_substr( sanitize_textarea_field( (string) $request->get_param( 'autoreply_text' ) ), 0, 5000 ) );
		}
		return new \WP_REST_Response( $this->settingsPayload() );
	}

	/**
	 * Settings for the Forms tab.
	 *
	 * @return array<string, mixed>
	 */
	private function settingsPayload(): array {
		return array(
			'forms'          => (array) $this->plugin->options->setting( 'forms' ),
			'autoreply_text' => (string) $this->plugin->options->get( self::AUTOREPLY_OPTION, '' ),
			'enabled'        => $this->enabled(),
			'settings'       => $this->plugin->options->settings(),
		);
	}

	/**
	 * GET /admin/quote-requests?status=&search=&page=.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function adminList( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->leads->list(
			array(
				'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
				'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => 20,
			)
		);
		$app    = $this->inAppUrl();
		return new \WP_REST_Response(
			array(
				'items'  => array_map( fn( array $lead ): array => $this->adminLead( $lead, $app ), $result['items'] ),
				'total'  => $result['total'],
				'counts' => $result['counts'],
			)
		);
	}

	/**
	 * POST /admin/quote-requests/{id}/retry.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function adminRetry( \WP_REST_Request $request ): \WP_REST_Response {
		$lead = $this->leads->find( (int) $request->get_param( 'id' ) );
		if ( null === $lead ) {
			return Routes::fail( 'not_found', __( 'Quote request not found.', 'invoiceninja' ), 404 );
		}
		if ( 'in_ninja' === $lead['status'] ) {
			return Routes::fail( 'already_sent', __( 'This request is already in Invoice Ninja.', 'invoiceninja' ), 409 );
		}
		if ( 'none' === $this->plugin->credentials->source() ) {
			return Routes::fail( 'not_connected', __( 'Connect Invoice Ninja first.', 'invoiceninja' ), 409 );
		}
		$lead = $this->creator->retryNow( (int) $lead['id'] );
		return new \WP_REST_Response( $this->adminLead( (array) $lead, $this->inAppUrl() ) );
	}

	/**
	 * DELETE /admin/quote-requests/{id} (deletes the copy on this site only).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function adminDelete( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->leads->delete( (int) $request->get_param( 'id' ) ) ) {
			return Routes::fail( 'not_found', __( 'Quote request not found.', 'invoiceninja' ), 404 );
		}
		return new \WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * A lead for the admin list.
	 *
	 * @param array<string, mixed>           $lead Lead.
	 * @param (callable(string): string)|null $app  In-app URL builder.
	 * @return array<string, mixed>
	 */
	public function adminLead( array $lead, ?callable $app ): array {
		$fields = (array) $lead['fields'];
		$reason = (string) $lead['reason'];
		if ( str_starts_with( $reason, 'retrying:' ) ) {
			$kind = \InvoiceNinja\Api\ErrorKind::tryFrom( substr( $reason, 9 ) );
			/* translators: %s: what went wrong */
			$retrying = __( '%s. Trying again automatically.', 'invoiceninja' );
			$reason   = null !== $kind ? sprintf( $retrying, $kind->title() ) : '';
		} elseif ( 'paused' === $reason ) {
			$reason = __( 'Waiting: Invoice Ninja is not connected, or this site is paused as a copy.', 'invoiceninja' );
		} elseif ( 'busy' === $reason ) {
			$reason = __( 'Held: far more requests than usual arrived that hour. Send it if it is real.', 'invoiceninja' );
		}
		$consents = array();
		foreach ( (array) $lead['consents'] as $which => $consent ) {
			if ( is_array( $consent ) ) {
				$consents[] = array(
					'which'  => (string) $which,
					'title'  => (string) ( $consent['title'] ?? '' ),
					'agreed' => ! empty( $consent['agreed'] ),
					'at'     => (int) ( $consent['at'] ?? 0 ),
				);
			}
		}
		return array(
			'id'           => (int) $lead['id'],
			'created_at'   => (string) $lead['created_at'],
			'status'       => (string) $lead['status'],
			'name'         => trim( (string) ( $fields['first_name'] ?? '' ) . ' ' . (string) ( $fields['last_name'] ?? '' ) ),
			'email'        => (string) $lead['email'],
			'phone'        => (string) ( $fields['phone'] ?? '' ),
			'product'      => (string) ( $fields['product_title'] ?? '' ),
			'message'      => (string) ( $fields['message'] ?? '' ),
			'consents'     => $consents,
			'matched'      => (bool) $lead['matched'],
			'quote_number' => (string) $lead['quote_number'],
			'quote_status' => (string) $lead['quote_status'],
			'quote_url'    => '' !== (string) $lead['quote_id'] && null !== $app ? $app( 'quotes/' . rawurlencode( (string) $lead['quote_id'] ) ) : '',
			'client_url'   => '' !== (string) $lead['client_id'] && null !== $app ? $app( 'clients/' . rawurlencode( (string) $lead['client_id'] ) ) : '',
			'reason'       => $reason,
		);
	}

	/**
	 * Links into Invoice Ninja's own app, as Routes::inAppUrl() builds them: /app/<path> on
	 * 5.13.44+, the React app's hash routes before that.
	 *
	 * @return (callable(string): string)|null Null when not connected.
	 */
	public function inAppUrl(): ?callable {
		$base = $this->plugin->credentials->url();
		if ( '' === $base ) {
			return null;
		}
		$state   = $this->plugin->state->get();
		$company = (string) ( $state['company']['id'] ?? '' );
		if ( \InvoiceNinja\Api\HttpClient::versionAtLeast( (string) ( $state['version'] ?? '' ), '5.13.44' ) ) {
			return static fn( string $path ): string => $base . '/app/' . $path . ( '' !== $company ? '?company=' . rawurlencode( $company ) : '' );
		}
		$web = \InvoiceNinja\Api\BaseUrl::isHosted( $base ) ? 'https://app.invoicing.co' : $base;
		return static fn( string $path ): string => $web . '/#/' . $path . ( str_starts_with( $path, 'quotes/' ) ? '/edit' : '' );
	}

	/**
	 * Daily: digest, quote statuses, lost jobs and retention.
	 */
	public function daily(): void {
		$this->emails->digest();
		$this->creator->daily();
		// Held requests (spam, or a flood) aren't kept forever, even when everything else is.
		$held = 0;
		foreach ( $this->leads->idsWithStatus( 'spam', 500, Leads::olderThan( time() - self::SPAM_DAYS * DAY_IN_SECONDS ) ) as $id ) {
			$held += $this->leads->delete( $id ) ? 1 : 0;
		}
		if ( $held > 0 ) {
			$this->plugin->logger->info( 'forms', sprintf( 'Deleted %d held quote requests older than %d days', $held, self::SPAM_DAYS ) );
		}
		$days = (int) $this->plugin->options->setting( 'forms.retention_days' );
		if ( $days > 0 ) {
			$deleted = 0;
			foreach ( $this->leads->idsOlderThan( time() - $days * DAY_IN_SECONDS, 500 ) as $id ) {
				$deleted += $this->leads->delete( $id ) ? 1 : 0;
			}
			if ( $deleted > 0 ) {
				$this->plugin->logger->info( 'forms', sprintf( 'Deleted %d quote requests older than %d days', $deleted, $days ) );
			}
		}
	}

	/**
	 * A Status check: failed quote requests.
	 *
	 * @param array<int, array<string, string>> $checks Checks.
	 * @return array<int, array<string, string>>
	 */
	public function diagnostics( array $checks ): array {
		if ( ! $this->enabled() ) {
			return $checks;
		}
		$counts   = $this->leads->counts();
		$checks[] = array(
			'id'     => 'quote_requests',
			'label'  => __( 'Quote requests', 'invoiceninja' ),
			'status' => $counts['failed'] > 0 ? 'warn' : 'pass',
			'detail' => $counts['failed'] > 0
				/* translators: %d: number of quote requests */
				? sprintf( _n( "%d quote request couldn't be sent to Invoice Ninja.", "%d quote requests couldn't be sent to Invoice Ninja.", $counts['failed'], 'invoiceninja' ), $counts['failed'] )
				/* translators: %d: number of quote requests waiting */
				: sprintf( _n( 'Working. %d request is waiting to be sent.', 'Working. %d requests are waiting to be sent.', $counts['queued'], 'invoiceninja' ), $counts['queued'] ),
			'action' => $counts['failed'] > 0 ? __( 'Open Quote requests and choose Retry.', 'invoiceninja' ) : '',
		);
		return $checks;
	}
}
