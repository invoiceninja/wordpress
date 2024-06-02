<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class ProfileApi extends BaseApi
{
    public static function load()
    {
        return self::sendRequest( 'shop/profile' );
    }
}