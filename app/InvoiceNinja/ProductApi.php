<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class ProductApi extends BaseApi
{
    public static function load()
    {
        $route = self::isUsingToken() ? 'products' : 'shop/products';

        $response = self::sendRequest( "$route?per_page=100&status=active" );

        //echo $response; exit;

        return $response;
    }
}