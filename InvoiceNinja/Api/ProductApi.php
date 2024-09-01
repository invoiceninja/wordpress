<?php

/**
 * @package Invoice Ninja
 */

namespace InvoiceNinja\Api;

class ProductApi extends BaseApi
{
    public static function load()
    {
        $route = self::isUsingToken() ? 'products' : 'shop/products';

        $response = self::sendRequest( "$route?per_page=100&status=active" );

        //echo $response; exit;

        if ( $response ) {
            return json_decode( $response )->data;
        }

        return null;
    }
}