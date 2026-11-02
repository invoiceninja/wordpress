<?php
/**
 * Emails about quote requests: the admin notification, the daily digest and the auto-reply.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Forms;

use InvoiceNinja\Plugin;

/**
 * Settings > Advanced > Notifications decides how admins hear about leads
 * (notifications.leads: instant, daily or off; to notifications.recipients). The
 * auto-reply is opt-in in Settings > Forms and never repeats what the visitor typed,
 * except their first name, so the form can't be used to send arbitrary text to others.
 */
final class LeadEmails {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param Leads  $leads  Leads.
	 */
	public function __construct( private Plugin $plugin, private Leads $leads ) {}

	/**
	 * A lead was saved: email the admins now if they asked for instant emails.
	 *
	 * @param array<string, mixed> $lead Lead.
	 */
	public function received( array $lead ): void {
		if ( 'instant' !== $this->plugin->options->setting( 'notifications.leads' ) ) {
			return;
		}
		$fields = (array) $lead['fields'];
		$name   = trim( (string) ( $fields['first_name'] ?? '' ) . ' ' . (string) ( $fields['last_name'] ?? '' ) );
		$this->inSiteLocale(
			function () use ( $lead, $name ): void {
				$this->plugin->mailer->send(
					$this->plugin->notifications->recipients(),
					/* translators: %s: name or email of the person asking */
					sprintf( __( 'New quote request from %s', 'invoiceninja' ), '' !== $name ? $name : (string) $lead['email'] ),
					$this->details( $lead ) . '<p>' . $this->button( __( 'Open Quote requests', 'invoiceninja' ) ) . '</p>'
					. ( 'spam' === $lead['status'] ? '<p>' . esc_html__( 'Akismet thinks this may be spam, so it was not sent to Invoice Ninja. Send it from Quote requests if it is real.', 'invoiceninja' ) . '</p>' : '' )
				);
			}
		);
		$this->leads->update( (int) $lead['id'], array( 'notified' => true ) );
	}

	/**
	 * Daily: one email with every lead not yet emailed (setting "daily digest").
	 */
	public function digest(): void {
		$mode = (string) $this->plugin->options->setting( 'notifications.leads' );
		$ids  = array();
		foreach ( Leads::STATUSES as $status ) {
			$ids = array_merge(
				$ids,
				$this->leads->idsWithStatus(
					$status,
					200,
					array(
						'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- daily digest on our own post type.
							array(
								'key'     => '_invoiceninja_lead_notified',
								'compare' => 'NOT EXISTS',
							),
						),
					)
				)
			);
		}
		if ( ! $ids ) {
			return;
		}
		$leads = array_filter( array_map( array( $this->leads, 'find' ), $ids ) );
		foreach ( $leads as $lead ) {
			$this->leads->update( (int) $lead['id'], array( 'notified' => true ) );
		}
		if ( 'daily' !== $mode ) {
			return; // Off (or instant, for leads saved before the switch): nothing to send.
		}
		$count = count( $leads );
		$this->inSiteLocale(
			function () use ( $leads, $count ): void {
				$html = '';
				foreach ( $leads as $lead ) {
					$html .= $this->details( $lead ) . '<hr style="border:0;border-top:1px solid #dcdcde">';
				}
				$this->plugin->mailer->send(
					$this->plugin->notifications->recipients(),
					/* translators: %d: number of quote requests */
					sprintf( _n( '%d new quote request', '%d new quote requests', $count, 'invoiceninja' ), $count ),
					$html . '<p>' . $this->button( __( 'Open Quote requests', 'invoiceninja' ) ) . '</p>'
				);
			}
		);
	}

	/**
	 * The optional auto-reply to the person who asked.
	 *
	 * @param array<string, mixed> $lead Lead.
	 */
	public function autoReply( array $lead ): void {
		if ( ! $this->plugin->options->setting( 'forms.autoreply' ) || 'spam' === $lead['status'] ) {
			return;
		}
		$fields = (array) $lead['fields'];
		$this->inSiteLocale(
			function () use ( $lead, $fields ): void {
				$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
				$days    = (int) $this->plugin->options->setting( 'forms.reply_days' );
				$subject = trim( (string) $this->plugin->options->setting( 'forms.autoreply_subject' ) );
				$text    = trim( (string) $this->plugin->options->get( FormsModule::AUTOREPLY_OPTION, '' ) );
				if ( '' === $subject ) {
					/* translators: %s: site name */
					$subject = sprintf( __( 'We received your quote request · %s', 'invoiceninja' ), $site );
				}
				if ( '' === $text ) {
					$text = $days > 0
						/* translators: %d: number of days */
						? sprintf( _n( "Thanks for your request. We'll reply within %d day.", "Thanks for your request. We'll reply within %d days.", $days, 'invoiceninja' ), $days )
						: __( "Thanks for your request. We'll reply soon.", 'invoiceninja' );
				}
				$vars    = array(
					'{first_name}' => (string) ( $fields['first_name'] ?? '' ),
					'{site}'       => $site,
					'{days}'       => (string) $days,
				);
				$subject = strtr( $subject, $vars );
				$text    = strtr( $text, $vars );
				$this->plugin->mailer->send( (string) $lead['email'], $subject, wpautop( esc_html( $text ) ) );
			}
		);
	}

	/**
	 * A lead as an HTML table (everything escaped).
	 *
	 * @param array<string, mixed> $lead Lead.
	 */
	private function details( array $lead ): string {
		$fields = (array) $lead['fields'];
		$labels = FormConfig::labels();
		$rows   = '';
		foreach ( $labels as $key => $label ) {
			$value = (string) ( $fields[ $key ] ?? '' );
			if ( 'product' === $key ) {
				$value = (string) ( $fields['product_title'] ?? '' );
			}
			if ( '' === $value ) {
				continue;
			}
			$rows .= '<tr><th style="text-align:left;vertical-align:top;padding:4px 12px 4px 0">' . esc_html( $label ) . '</th><td style="padding:4px 0">' . nl2br( esc_html( $value ) ) . '</td></tr>';
		}
		foreach ( (array) $lead['consents'] as $consent ) {
			if ( is_array( $consent ) && ! empty( $consent['agreed'] ) ) {
				$rows .= '<tr><th style="text-align:left;padding:4px 12px 4px 0">' . esc_html__( 'Agreed to', 'invoiceninja' ) . '</th><td>' . esc_html( (string) ( $consent['title'] ?? '' ) ) . '</td></tr>';
			}
		}
		return '<table style="border-collapse:collapse">' . $rows . '</table>';
	}

	/**
	 * A button to the Quote requests screen.
	 *
	 * @param string $label Label.
	 */
	private function button( string $label ): string {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=invoiceninja-quotes' ) ) . '" style="display:inline-block;background:#2271b1;color:#ffffff;padding:10px 18px;border-radius:4px;text-decoration:none;font-weight:600">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Run in the site's language (the submit request may be in the visitor's).
	 *
	 * @param callable $work Work.
	 */
	private function inSiteLocale( callable $work ): void {
		$switched = switch_to_locale( get_locale() );
		try {
			$work();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}
}
