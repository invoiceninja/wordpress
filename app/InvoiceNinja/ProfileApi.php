<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class ProfileApi extends BaseApi
{
    public static function load()
    {
        $response = self::sendRequest( 'shop/profile' );

        //echo $response; exit;

        return $response;
    }
}