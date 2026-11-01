<?php

/**
 * @package Invoice Ninja
 */

namespace InvoiceNinja\Utils;

class Formatting
{
    /**
     * Mirrors Invoice Ninja's App\Utils\Number::formatMoney so prices match the invoice.
     */
    public static function formatMoney( $value )
    {
        $value = (float) $value;
        $profile = json_decode( (string) get_option( 'invoiceninja_profile' ) );
        $currency_map = json_decode( (string) get_option( 'invoiceninja_currencies' ) );
        $country_map = json_decode( (string) get_option( 'invoiceninja_countries' ) );

        $currency_id = $profile->settings->currency_id ?? null;
        $country_id = $profile->settings->country_id ?? null;

        $currency = ( $currency_id !== null && isset( $currency_map->$currency_id ) ) ? $currency_map->$currency_id : null;
        $country = ( $country_id !== null && isset( $country_map->$country_id ) ) ? $country_map->$country_id : null;

        // The company profile is cleared when the connection fails; show a plain amount rather than warnings
        if ( ! $currency ) {
            return number_format( $value, 2 );
        }

        $thousand = $currency->thousand_separator ?? ',';
        $decimal = $currency->decimal_separator ?? '.';
        $swap_symbol = ! empty( $currency->swap_currency_symbol );

        // Country settings override the currency's
        if ( isset( $country->thousand_separator ) && strlen( $country->thousand_separator ) >= 1 ) {
            $thousand = $country->thousand_separator;
        }
        if ( isset( $country->decimal_separator ) && strlen( $country->decimal_separator ) >= 1 ) {
            $decimal = $country->decimal_separator;
        }
        if ( ! empty( $country->swap_currency_symbol ) ) {
            $swap_symbol = true;
        }

        $formatted = number_format( $value, (int) ( $currency->precision ?? 2 ), $decimal, $thousand );
        $symbol = $currency->symbol ?? '';
        $code = $currency->code ?? '';

        if ( ! empty( $profile->settings->show_currency_code ) ) {
            return $code === 'CHF' ? "{$code} {$formatted}" : "{$formatted} {$code}";
        }

        if ( $swap_symbol ) {
            return "{$formatted} " . trim( $symbol );
        }

        // Put the minus sign ahead of the currency symbol
        if ( $value < 0 ) {
            return '-' . $symbol . substr( $formatted, 1 );
        }

        return $symbol . $formatted;
    }
}
