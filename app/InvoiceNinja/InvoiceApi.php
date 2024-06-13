<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class InvoiceApi extends BaseApi
{
    public static function create($cart)
    {
        $response = self::sendRequest( "invoices" );

        //echo $response; exit;

        return $response;
    }
}