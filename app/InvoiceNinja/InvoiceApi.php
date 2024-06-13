<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class InvoiceApi extends BaseApi
{
    public static function create($cart)
    {
        //$invoice = new \stdClass;
        //$invoice->client_id = 'J0dNxm2aLO';

        $invoice = [
            'client_id' => 'J0dNxm2aLO',
            'line_items' => [],
        ];

        foreach ($cart as $product => $quantity) {
            $invoice['line_items'][] = [
                'product_key' => $product,
                'quantity' => $quantity,
            ];
        }

        $response = self::sendRequest( 'invoices', 'POST', $invoice );

        echo $response; exit;

        return $response;
    }
}