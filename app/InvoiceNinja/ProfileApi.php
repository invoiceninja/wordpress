<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class ProfileApi extends BaseApi
{
    public static function load()
    {
        $route = self::isUsingToken() ? 'companies' : 'shop/profile';

        $response = self::sendRequest( $route );

        if (self::isUsingToken()) {
            $response = json_encode( json_decode( $response )[0] );
        }

        //echo $response; exit;

        return $response;
    }
}