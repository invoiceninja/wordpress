<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class BaseApi
{
    public static function sendRequest( $route )
    {
        $key = esc_attr( get_option( 'invoiceninja_company_key' ) );
        $url = esc_attr( get_option( 'invoiceninja_api_url' ) );

        if ( empty( $url ) ) {
            $url = 'https://invoicing.co/api/v1/';
        } else {
            $url = rtrim( $url, '/' );
            $url = rtrim( $url, 'api/v1' );
            $url = rtrim( $url, '/' );
            $url .= '/api/v1/';
        }

        $opts = [
            "http" => [
                "header" => "X-API-COMPANY-KEY: $key\r\n",
            ]
        ];

        $context = stream_context_create( $opts );
        $url = 'https://staging.invoicing.co/api/v1/' . $route;

        $response = @file_get_contents( $url, false, $context );

        return $response;
    }
}