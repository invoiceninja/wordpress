<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class ProductApi extends BaseApi
{
    public static function load()
    {
        $response = self::sendRequest( 'shop/products?per_page=100' );

        //echo $response; exit;

        return $response;
    }
}