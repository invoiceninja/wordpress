<?php

/**
 * @package Invoice Ninja
 */

namespace App\Controllers;

use \App\InvoiceNinja\ClientApi;

class ClientController extends BaseController
{
    public function register()
    {
        add_action('user_register', [ $this, 'export' ] );
        add_action('profile_update', [ $this, 'export' ] );
    }

    public static function export($user_id)
    {
        if ( get_option( 'invoiceninja_sync_clients' ) ) 
        {
            $user = get_userdata($user_id);

            ClientApi::exportUser($user);
        }
    }
}