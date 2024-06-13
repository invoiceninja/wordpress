<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class InvoiceApi extends BaseApi
{
    public static function create($cart)
    {
        $cart = [
            'client_id' => 'J0dNxm2aLO',
        ];

        $response = self::sendRequest( "invoices", $cart );

        echo $response; exit;

        return $response;
    }
}