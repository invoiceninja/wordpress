<?php

/**
 * @package Invoice Ninja
 */

namespace InvoiceNinja\Api;

class ProductApi extends BaseApi
{
    public static function load()
    {
        $response = self::sendRequest( 'products?per_page=100&status=active' );

        if ( $response ) {
            return json_decode( $response )->data;
        }

        return null;
    }
}