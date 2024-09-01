<?php

/**
 * @package Invoice Ninja
 */

namespace InvoiceNinja\Utils;

class Formatting
{
    public static function formatMoney( $value )
    {
        $profile = json_decode( get_option( 'invoiceninja_profile' ) );
        $currency_map = json_decode( get_option( 'invoiceninja_currencies' ) );
        $country_map = json_decode( get_option( 'invoiceninja_countries' ) );

        $currency_id = $profile->settings->currency_id;
        $country_id = $profile->settings->country_id;

        $currency = $currency_map->$currency_id;
        $country = $country_map->$country_id;

        $thousand_separator = $currency->thousand_separator;
        $decimal_separator = $currency->decimal_separator;
        $swap_currency_symbol = $currency->swap_currency_symbol;
      
        if ( $currency->id == 3 ) {
          $swap_currency_symbol = $country->swap_currency_symbol;
          if ( $country->thousand_separator ) {
            $thousand_separator = $country->thousand_separator;
          }
          if ( $country->decimal_separator ) {
            $decimal_separator = $country->decimal_separator;
          }
        }

        if ( $swap_currency_symbol ) {
            $value = number_format( $value, $currency->precision, $thousand_separator, $decimal_separator );
        } else {
            $value = number_format( $value, $currency->precision, $decimal_separator, $thousand_separator );
        }

        if ( $profile->settings->show_currency_code ) {
            return $value . ' ' . $currency->code;
        } else {
            return $currency->symbol . $value;
        }
    }
}