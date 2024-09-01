<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class BaseApi
{
    public static function sendRequest( $route, $method = 'GET', $data = false )
    {
        $key = esc_attr( get_option( 'invoiceninja_api_token' ) );
        $url = esc_attr( get_option( 'invoiceninja_api_url' ) );

        if ( ! $key ) {
            return null;
        }

        if ( empty( $url ) ) {
            $url = 'https://invoicing.co/api/v1/';
        } else {
            $url = rtrim( $url, '/' );
            $url = rtrim( $url, 'api/v1' );
            $url = rtrim( $url, '/' );
            $url .= '/api/v1/';
        }

        $url .= $route;

        $args = array(
            'timeout' => '60',
            'headers' => array(
                'X-API-TOKEN' => $key,
                'X-CLIENT-PLATFORM' => 'WordPress',
                'Content-Type' => 'application/json',
            ),
            'body' => $data ? wp_json_encode($data) : null,
            'method' => $method,
        );

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {

            if ( is_admin() && function_exists( 'add_settings_error' ) ) {
                add_settings_error(
                    'invoiceninja',
                    'api_request',
                    $response->get_error_message(),
                    'error'
                );
            } else {
                echo 'Error: ' . esc_attr( $response->get_error_message() );
                exit;
            }

            return null;
        } else {
            $response_code = wp_remote_retrieve_response_code($response);

            if ($response_code === 200) {                                    
                $body = wp_remote_retrieve_body( $response );

                return $body;
            } else {
                if ( is_admin() && function_exists( 'add_settings_error' ) ) {
                    add_settings_error(
                        'invoiceninja',
                        'api_request',
                        json_decode($response['body'])->message,                        
                        'error'
                    );
                } else {
                    echo 'Error: ' . esc_attr( json_decode($response['body'])->message );
                    exit;
                }
            }
        }

        /*
        $opts = [
            "http" => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\nX-API-" . ( self::isUsingToken() ? 'TOKEN' : 'COMPANY-KEY' ) . ": $key\r\n",
                'method'  => $method,
                'content' => wp_json_encode( $data ),
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
            if ( is_admin() && function_exists( 'add_settings_error' ) ) {
                add_settings_error(
                    'invoiceninja',
                    'api_request',
                    $e->getMessage(),
                    'error'
                );
            } else {
                echo 'Error: ' . $e->getMessage();
                exit;
            }

            return null;
        }

        restore_error_handler();
        */

        return null;
    }

    public static function isUsingToken()
    {
        return true;
        
        /*
        $value = get_option( 'invoiceninja_api_token' );

        return strlen( $value ) == 64;
        */
    }
}