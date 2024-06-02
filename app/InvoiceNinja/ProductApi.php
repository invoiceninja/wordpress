<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class ProductApi extends BaseApi
{
    public static function load()
    {
        return self::sendRequest( 'shop/products?per_page=100' );
    }
}