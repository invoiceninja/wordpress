<?php
/**
 * The plugin's capabilities.
 *
 * @package InvoiceNinja
 */

declare(strict_types=1);

namespace InvoiceNinja\Core;

/**
 * manage_invoiceninja runs the plugin; view_invoiceninja sees orders and status.
 * The connection itself (address, token, company) always needs manage_options.
 */
final class Capabilities {

	public const MANAGE = 'manage_invoiceninja';

	public const VIEW = 'view_invoiceninja';

	public const ROLES = array( 'administrator', 'shop_manager' );

	/**
	 * Grant the capabilities to administrators and shop managers.
	 */
	public static function grant(): void {
		foreach ( self::ROLES as $name ) {
			$role = get_role( $name );
			if ( $role ) {
				$role->add_cap( self::MANAGE );
				$role->add_cap( self::VIEW );
			}
		}
	}

	/**
	 * Remove them from every role (uninstall).
	 */
	public static function revoke(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( self::MANAGE );
			$role->remove_cap( self::VIEW );
		}
	}
}
