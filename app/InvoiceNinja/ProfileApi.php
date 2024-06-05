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

        //echo $response; exit;

        return $response;
    }
}