<?php
/**
 * Whether a WordPress user has proved they own their email address.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Portal;

use InvoiceNinja\Plugin;

/**
 * Linked and verified are separate (the plan's security rule 1): a user may be linked to
 * an Invoice Ninja client by email, but only a verified user is ever sent to a portal,
 * invitation or payment link. Verification is tied to the email it was done for, so
 * changing the email clears it.
 */
final class Verification {

	public const AT    = '_invoiceninja_verified_at';
	public const EMAIL = '_invoiceninja_verified_email_hash';
	public const HOW   = '_invoiceninja_verified_by';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'invoiceninja_is_verified', array( $this, 'filter' ), 10, 2 );
		add_action( 'profile_update', array( $this, 'emailChanged' ), 10, 2 );
		add_action( 'after_password_reset', array( $this, 'passwordReset' ) );
		add_action( 'invoiceninja_code_verified', array( $this, 'codeVerified' ), 10, 2 );
	}

	/**
	 * The invoiceninja_is_verified filter.
	 *
	 * @param mixed $verified Earlier answer.
	 * @param mixed $user_id  User id.
	 */
	public function filter( $verified, $user_id ): bool {
		return (bool) $verified || $this->isVerified( (int) $user_id );
	}

	/**
	 * Whether this user's current email is verified.
	 *
	 * @param int $user_id User id.
	 */
	public function isVerified( int $user_id ): bool {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return false;
		}
		$verified = '' !== (string) get_user_meta( $user_id, self::AT, true )
			&& hash_equals( (string) get_user_meta( $user_id, self::EMAIL, true ), $this->hash( (string) $user->user_email ) );
		/**
		 * Whether a user's email counts as verified (e.g. a membership plugin that already
		 * confirms emails can return true).
		 *
		 * @since 2.0.0
		 * @param bool $verified Verified.
		 * @param int  $user_id  User id.
		 */
		return (bool) apply_filters( 'invoiceninja_user_email_verified', $verified, $user_id );
	}

	/**
	 * Record that the user owns their current email.
	 *
	 * @param int    $user_id User id.
	 * @param string $how     code, password_reset, admin, bulk or cli.
	 */
	public function mark( int $user_id, string $how ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		update_user_meta( $user_id, self::AT, gmdate( 'Y-m-d H:i:s' ) );
		update_user_meta( $user_id, self::EMAIL, $this->hash( (string) $user->user_email ) );
		update_user_meta( $user_id, self::HOW, sanitize_key( $how ) );
		/**
		 * Fires when a user's email becomes verified.
		 *
		 * @since 2.0.0
		 * @param int    $user_id User id.
		 * @param string $how     How.
		 */
		do_action( 'invoiceninja_email_verified', $user_id, $how );
	}

	/**
	 * Forget a user's verification.
	 *
	 * @param int $user_id User id.
	 */
	public function clear( int $user_id ): void {
		delete_user_meta( $user_id, self::AT );
		delete_user_meta( $user_id, self::EMAIL );
		delete_user_meta( $user_id, self::HOW );
	}

	/**
	 * A changed email is no longer verified.
	 *
	 * @param int      $user_id  User id.
	 * @param \WP_User $old_user Previous data.
	 */
	public function emailChanged( int $user_id, $old_user ): void {
		$user = get_userdata( $user_id );
		if ( $user && $old_user instanceof \WP_User && strtolower( (string) $old_user->user_email ) !== strtolower( (string) $user->user_email ) ) {
			$this->clear( $user_id );
		}
	}

	/**
	 * Resetting a password through its email link proves the inbox.
	 *
	 * @param \WP_User $user User.
	 */
	public function passwordReset( $user ): void {
		if ( $user instanceof \WP_User ) {
			$this->mark( (int) $user->ID, 'password_reset' );
		}
	}

	/**
	 * A code typed at checkout or in the Account verifies the logged-in user when it was
	 * sent to their own account email.
	 *
	 * @param string $email   Email the code went to.
	 * @param string $purpose checkout or portal.
	 */
	public function codeVerified( string $email, string $purpose ): void {
		unset( $purpose );
		$user_id = \InvoiceNinja\Commerce\Checkout::userId();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( $user && strtolower( (string) $user->user_email ) === strtolower( $email ) ) {
			$this->mark( $user_id, 'code' );
		}
	}

	/**
	 * Hash of an email, keyed with the site secret.
	 *
	 * @param string $email Email.
	 */
	private function hash( string $email ): string {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ), $this->plugin->secrets->get( 'secret' ) );
	}
}
