<?php

/**
 * @package Invoice Ninja
 */

namespace InvoiceNinja\Controllers;

use \InvoiceNinja\Api\ClientApi;

class ClientController extends BaseController
{
    public function register()
    {
        add_action('user_register', [ $this, 'export' ] );
        add_action('profile_update', [ $this, 'export' ] );

        if ( class_exists( 'WooCommerce' ) ) {
            add_action('woocommerce_checkout_order_processed',           [ $this, 'exportFromOrder' ], 20, 3 );
            add_action('woocommerce_store_api_checkout_order_processed', [ $this, 'exportFromOrder' ], 20, 1 );
            add_action('woocommerce_customer_save_address',              [ $this, 'export' ],          20, 1 );
        }
    }

    public static function export($user_id)
    {
        if ( get_option( 'invoiceninja_sync_clients' ) )
        {
            try {
                $user = get_userdata($user_id);

                ClientApi::exportUser($user);
            } catch ( \Throwable $e ) {
                error_log( 'Invoice Ninja client export failed: ' . $e->getMessage() );
            }
        }
    }

    public static function exportFromOrder( $order_or_id, $posted_data = null, $order = null )
    {
        if ( ! get_option( 'invoiceninja_sync_clients' ) ) {
            return;
        }

        try {
            if ( is_object( $order_or_id ) ) {
                $wc_order = $order_or_id;
            } elseif ( $order ) {
                $wc_order = $order;
            } elseif ( function_exists( 'wc_get_order' ) ) {
                $wc_order = wc_get_order( $order_or_id );
            } else {
                return;
            }

            if ( ! $wc_order ) {
                return;
            }

            $user_id = $wc_order->get_customer_id();
            if ( $user_id ) {
                $user = get_userdata( $user_id );
                if ( $user ) {
                    ClientApi::exportUserFromOrder( $user, $wc_order );
                }
            } else {
                ClientApi::exportOrder( $wc_order );
            }
        } catch ( \Throwable $e ) {
            error_log( 'Invoice Ninja client export from order failed: ' . $e->getMessage() );
        }
    }
}