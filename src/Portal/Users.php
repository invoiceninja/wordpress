<?php
/**
 * Invoice Ninja on the Users screens: profile panel, column, bulk verify, WP-CLI.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Portal;

use InvoiceNinja\Core\Capabilities;
use InvoiceNinja\Plugin;

/**
 * Admins see whether a user is linked to an Invoice Ninja client and verified, can mark
 * them verified (one by one, in bulk, or by role from WP-CLI) and unlink them.
 */
final class Users {

	/**
	 * Constructor.
	 *
	 * @param Plugin       $plugin       Plugin.
	 * @param Verification $verification Verification.
	 * @param Links        $links        Links.
	 */
	public function __construct( private Plugin $plugin, private Verification $verification, private Links $links ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'panel' ) );
		add_action( 'edit_user_profile', array( $this, 'panel' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
		add_filter( 'manage_users_columns', array( $this, 'column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'cell' ), 10, 3 );
		add_filter( 'bulk_actions-users', array( $this, 'bulkActions' ) );
		add_filter( 'handle_bulk_actions-users', array( $this, 'bulk' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulkNotice' ) );
		add_action( 'cli_init', array( $this, 'cli' ) );
	}

	/**
	 * The Invoice Ninja panel on a user's profile (admins only).
	 *
	 * @param \WP_User $user User.
	 */
	public function panel( $user ): void {
		if ( ! $user instanceof \WP_User || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		$link     = $this->links->get( (int) $user->ID );
		$verified = $this->verification->isVerified( (int) $user->ID );
		$base     = $this->plugin->credentials->url();
		wp_nonce_field( 'invoiceninja_user_' . $user->ID, 'invoiceninja_user_nonce' );
		echo '<h2>' . esc_html__( 'Invoice Ninja', 'invoiceninja' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Client', 'invoiceninja' ) . '</th><td>';
		if ( null !== $link ) {
			$url = '' !== $base ? $base . '/app/clients/' . rawurlencode( $link['client_id'] ) : '';
			echo esc_html__( 'Linked', 'invoiceninja' );
			if ( '' !== $url ) {
				echo ' · <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open in Invoice Ninja', 'invoiceninja' ) . ' <span aria-hidden="true">↗</span><span class="screen-reader-text">' . esc_html__( '(opens in a new tab)', 'invoiceninja' ) . '</span></a>';
			}
			echo '<p><label><input type="checkbox" name="invoiceninja_unlink" value="1"> ' . esc_html__( 'Unlink this user from the client', 'invoiceninja' ) . '</label></p>';
		} else {
			echo esc_html__( 'Not linked yet. Users are linked when they buy or confirm their email in their Account.', 'invoiceninja' );
		}
		echo '</td></tr><tr><th scope="row">' . esc_html__( 'Email verified', 'invoiceninja' ) . '</th><td>';
		echo '<label><input type="checkbox" name="invoiceninja_verified" value="1" ' . checked( $verified, true, false ) . '> ' . esc_html__( 'This person owns this email address', 'invoiceninja' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Only verified users can open the billing portal and payment links from your site. Changing the email clears this.', 'invoiceninja' ) . '</p>';
		echo '</td></tr></tbody></table>';
	}

	/**
	 * Save the panel.
	 *
	 * @param int $user_id User id.
	 */
	public function save( int $user_id ): void {
		if ( ! current_user_can( Capabilities::MANAGE ) || ! isset( $_POST['invoiceninja_user_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['invoiceninja_user_nonce'] ) ), 'invoiceninja_user_' . $user_id ) ) {
			return;
		}
		$want = ! empty( $_POST['invoiceninja_verified'] );
		if ( $want && ! $this->verification->isVerified( $user_id ) ) {
			$this->verification->mark( $user_id, 'admin' );
		} elseif ( ! $want ) {
			$this->verification->clear( $user_id );
		}
		if ( ! empty( $_POST['invoiceninja_unlink'] ) ) {
			$this->links->clear( $user_id );
		}
	}

	/**
	 * Users list column.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function column( array $columns ): array {
		if ( current_user_can( Capabilities::VIEW ) ) {
			$columns['invoiceninja'] = __( 'Invoice Ninja', 'invoiceninja' );
		}
		return $columns;
	}

	/**
	 * Users list cell.
	 *
	 * @param string $output  Output.
	 * @param string $column  Column.
	 * @param int    $user_id User id.
	 */
	public function cell( $output, $column, $user_id ): string {
		if ( 'invoiceninja' !== $column ) {
			return (string) $output;
		}
		$parts = array();
		if ( null !== $this->links->get( (int) $user_id ) ) {
			$parts[] = __( 'Linked', 'invoiceninja' );
		}
		if ( $this->verification->isVerified( (int) $user_id ) ) {
			$parts[] = __( 'Verified', 'invoiceninja' );
		}
		return $parts ? esc_html( implode( ' · ', $parts ) ) : '<span aria-hidden="true">—</span>';
	}

	/**
	 * Bulk actions on the Users list.
	 *
	 * @param array<string, string> $actions Actions.
	 * @return array<string, string>
	 */
	public function bulkActions( array $actions ): array {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			$actions['invoiceninja_verify'] = __( 'Mark email verified (Invoice Ninja)', 'invoiceninja' );
		}
		return $actions;
	}

	/**
	 * Run the bulk action.
	 *
	 * @param string    $redirect Redirect.
	 * @param string    $action   Action.
	 * @param list<int> $ids      User ids.
	 */
	public function bulk( $redirect, $action, $ids ): string {
		if ( 'invoiceninja_verify' !== $action || ! current_user_can( Capabilities::MANAGE ) ) {
			return (string) $redirect;
		}
		foreach ( (array) $ids as $id ) {
			$this->verification->mark( (int) $id, 'bulk' );
		}
		return add_query_arg( 'invoiceninja_verified', count( (array) $ids ), (string) $redirect );
	}

	/**
	 * After the bulk action.
	 */
	public function bulkNotice(): void {
		$count = isset( $_GET['invoiceninja_verified'] ) ? absint( $_GET['invoiceninja_verified'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a count to show.
		if ( $count > 0 ) {
			/* translators: %d: number of users */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d user marked as verified.', '%d users marked as verified.', $count, 'invoiceninja' ), $count ) ) . '</p></div>';
		}
	}

	/**
	 * WP-CLI: wp invoiceninja verify-users --role=<role> [--yes].
	 */
	public function cli(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command(
			'invoiceninja verify-users',
			function ( array $args, array $assoc ): void {
				unset( $args );
				$role = isset( $assoc['role'] ) ? sanitize_key( (string) $assoc['role'] ) : '';
				if ( '' === $role ) {
					\WP_CLI::error( 'Give a role, e.g. --role=customer.' );
				}
				$ids = get_users(
					array(
						'role'   => $role,
						'fields' => 'ID',
					)
				);
				\WP_CLI::confirm( sprintf( 'Mark %d users with the role %s as verified?', count( $ids ), $role ), $assoc );
				foreach ( $ids as $id ) {
					$this->verification->mark( (int) $id, 'cli' );
				}
				\WP_CLI::success( sprintf( '%d users marked as verified.', count( $ids ) ) );
			}
		);
	}
}
