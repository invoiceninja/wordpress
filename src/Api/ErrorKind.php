<?php
/**
 * What went wrong talking to Invoice Ninja.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Api;

enum ErrorKind: string {

	case NotConfigured   = 'not_configured';
	case Dns             = 'dns';
	case Tls             = 'tls';
	case Timeout         = 'timeout';
	case Transport       = 'transport';
	case PrivateHost     = 'private_host';
	case Redirect        = 'redirect';
	case Auth            = 'auth';
	case PlanRequired    = 'plan_required';
	case NonAdmin        = 'non_admin';
	case Forbidden       = 'forbidden';
	case NotFound        = 'not_found';
	case Validation      = 'validation';
	case RateLimited     = 'rate_limited';
	case Duplicate       = 'duplicate';
	case Server          = 'server';
	case InvalidResponse = 'invalid_response';
	case VersionTooOld   = 'version_too_old';
	case TokenUnreadable = 'token_unreadable';

	/**
	 * Whether trying again later can succeed without anyone changing anything.
	 */
	public function retryable(): bool {
		return in_array( $this, array( self::Timeout, self::Transport, self::Dns, self::RateLimited, self::Server, self::Duplicate ), true );
	}

	/**
	 * Whether the connection itself is broken (as opposed to one request failing).
	 */
	public function breaksConnection(): bool {
		return in_array( $this, array( self::Auth, self::PlanRequired, self::NonAdmin, self::TokenUnreadable, self::VersionTooOld, self::NotConfigured ), true );
	}

	/**
	 * One plain sentence about what happened.
	 *
	 * @param string $host Invoice Ninja host for the message.
	 */
	public function title( string $host = '' ): string {
		$host = '' !== $host ? $host : __( 'Invoice Ninja', 'invoiceninja' );
		return match ( $this ) {
			/* translators: %s: host name */
			self::Dns             => sprintf( __( "We couldn't find %s", 'invoiceninja' ), $host ),
			/* translators: %s: host name */
			self::Tls             => sprintf( __( 'The secure connection to %s failed', 'invoiceninja' ), $host ),
			/* translators: %s: host name */
			self::Timeout         => sprintf( __( "%s didn't answer", 'invoiceninja' ), $host ),
			/* translators: %s: host name */
			self::Transport       => sprintf( __( "We couldn't connect to %s", 'invoiceninja' ), $host ),
			/* translators: %s: host name */
			self::PrivateHost     => sprintf( __( '%s is on your private network', 'invoiceninja' ), $host ),
			self::Redirect        => __( 'The address redirects somewhere else', 'invoiceninja' ),
			self::Auth            => __( "Invoice Ninja didn't accept this token", 'invoiceninja' ),
			self::PlanRequired    => __( 'Connecting needs a Pro or Enterprise plan', 'invoiceninja' ),
			self::NonAdmin        => __( "This token belongs to someone who isn't an admin", 'invoiceninja' ),
			self::Forbidden       => __( "Invoice Ninja didn't allow this", 'invoiceninja' ),
			self::NotFound        => __( "That address isn't an Invoice Ninja server", 'invoiceninja' ),
			self::Validation      => __( 'Invoice Ninja rejected the data', 'invoiceninja' ),
			self::RateLimited     => __( 'Too many attempts in a short time', 'invoiceninja' ),
			self::Duplicate       => __( 'Invoice Ninja is still processing the same request', 'invoiceninja' ),
			self::Server          => __( 'Invoice Ninja is having trouble right now', 'invoiceninja' ),
			self::InvalidResponse => __( "That address isn't an Invoice Ninja server", 'invoiceninja' ),
			self::VersionTooOld   => __( 'Invoice Ninja is too old', 'invoiceninja' ),
			self::TokenUnreadable => __( "Your saved token can't be read any more", 'invoiceninja' ),
			self::NotConfigured   => __( 'Invoice Ninja is not connected yet', 'invoiceninja' ),
		};
	}

	/**
	 * One plain sentence about how to fix it.
	 */
	public function fix(): string {
		return match ( $this ) {
			self::Dns             => __( 'Check the address for typos. It should be the address you use to log in to Invoice Ninja.', 'invoiceninja' ),
			self::Tls             => __( "Its security certificate isn't valid. Whoever runs that server needs to renew or fix it. We never turn certificate checks off.", 'invoiceninja' ),
			self::Timeout         => __( 'Invoice Ninja may be down, or a firewall may be blocking this site. Try again in a few minutes.', 'invoiceninja' ),
			self::Transport       => __( 'Check that Invoice Ninja is running and that its firewall lets this site in.', 'invoiceninja' ),
			self::PrivateHost     => __( 'WordPress blocks private addresses by default. Allow this one address only if Invoice Ninja really runs on your network.', 'invoiceninja' ),
			self::Redirect        => __( "Use the address it redirects to. We don't follow redirects, so your token is never sent to another server.", 'invoiceninja' ),
			self::Auth            => __( 'It may have been deleted, or its user switched off. Copy a token again from Settings → Account Management → API Tokens.', 'invoiceninja' ),
			self::PlanRequired    => __( 'Upgrade your hosted Invoice Ninja account, or self-host Invoice Ninja, which connects on any plan.', 'invoiceninja' ),
			self::NonAdmin        => __( 'Invoice Ninja only shows non-admins their own records, so products and clients would be missing. Log in as an admin and create a new token.', 'invoiceninja' ),
			self::Forbidden       => __( 'Check the permissions of the user who owns this token.', 'invoiceninja' ),
			self::NotFound        => __( 'Use the address of Invoice Ninja itself, not your website or the client portal.', 'invoiceninja' ),
			self::Validation      => __( 'See the technical details for which field was rejected.', 'invoiceninja' ),
			self::RateLimited     => __( 'Invoice Ninja asked us to slow down. Wait a minute, then try again.', 'invoiceninja' ),
			self::Duplicate       => __( 'Wait a moment, then try again.', 'invoiceninja' ),
			self::Server          => __( 'It answered with an error, often during an update or maintenance. Try again in a few minutes.', 'invoiceninja' ),
			self::InvalidResponse => __( 'Use the address of Invoice Ninja itself, not your website or the client portal.', 'invoiceninja' ),
			/* translators: %s: minimum Invoice Ninja version */
			self::VersionTooOld   => sprintf( __( 'This plugin needs Invoice Ninja %s or newer. Update Invoice Ninja, then test again.', 'invoiceninja' ), \InvoiceNinja\Api\HttpClient::MIN_VERSION ),
			self::TokenUnreadable => __( "WordPress's security keys changed, or the site moved, so the encrypted token can't be unlocked. Paste it again; nothing else is lost.", 'invoiceninja' ),
			self::NotConfigured   => __( 'Run setup to connect.', 'invoiceninja' ),
		};
	}
}
