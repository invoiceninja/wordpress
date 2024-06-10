<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class BaseApi
{
    public static function sendRequest( $route )
    {
        $key = esc_attr( get_option( 'invoiceninja_api_token' ) );
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
                "header" => "X-API-" . ( self::isUsingToken() ? 'TOKEN' : 'COMPANY-KEY' ) . ": $key\r\n",
            ]
        ];

        $context = stream_context_create( $opts );
        $url = 'https://staging.invoicing.co/api/v1/' . $route;

        set_error_handler(
            function ($severity, $message, $file, $line) {
                throw new \ErrorException($message, $severity, $severity, $file, $line);
            }
        );

        try {
            $response = file_get_contents( $url, false, $context );
        } catch (\Exception $e) {
            add_settings_error(
                'invoiceninja',
                'api_request',
                $e->getMessage(),
                'error'
            );
        }

        restore_error_handler();

        if ( $response ) {
            $response = json_encode( json_decode( $response )->data );
        }

        return $response;
    }

    public static function isUsingToken()
    {
        $value = get_option( 'invoiceninja_api_token' );

        return strlen( $value ) == 64;
    }
}