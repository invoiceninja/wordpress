<?php

/**
 * @package Invoice Ninja
 */

namespace App\Utils;

class Formatting
{
    public static function formatMoney( $value )
    {
        $currency_map = json_decode( get_option( 'invoiceninja_currencies' ) );
        $country_map = json_decode( get_option( 'invoiceninja_countries' ) );

        return 'ABC';
    }
}