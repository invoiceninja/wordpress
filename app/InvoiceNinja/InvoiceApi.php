<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class InvoiceApi extends BaseApi
{
    public static function create($cart)
    {
        $invoice = new \stdClass;
        $invoice->client_id = 'J0dNxm2aLO';

        $response = self::sendRequest( 'invoices', 'POST', $invoice );

        echo $response; exit;

        return $response;
    }
}