<?php

/**
 * @package Invoice Ninja
 */

namespace InvoiceNinja\Api;

class BaseApi
{
    public static function sendRequest( $route, $method = 'GET', $data = false )
    {
        // 2.0 owns the connection: it decrypts the token and honours wp-config.php constants
        $key = self::token();

        if ( ! $key ) {
            return null;
        }

        $url = self::getBaseUrl() . '/api/v1/' . $route;

        $args = array(
            'timeout' => '30',
            'redirection' => 0,
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
                error_log( 'Invoice Ninja API request failed: ' . $response->get_error_message() );
            }

            return null;
        } else {
            $response_code = wp_remote_retrieve_response_code($response);

            if ($response_code === 200) {
                $body = wp_remote_retrieve_body( $response );

                return $body;
            } else {
                $decoded = json_decode( wp_remote_retrieve_body( $response ) );
                $message = isset( $decoded->message ) ? $decoded->message : 'HTTP ' . $response_code;

                if ( is_admin() && function_exists( 'add_settings_error' ) ) {
                    add_settings_error(
                        'invoiceninja',
                        'api_request',
                        $message,
                        'error'
                    );
                } else {
                    error_log( 'Invoice Ninja API request failed: ' . $message );
                }
            }
        }

        return null;
    }

    public static function hasEncryptedToken()
    {
        return strpos( (string) get_option( 'invoiceninja_api_token' ), 'in1:' ) === 0;
    }

    public static function token()
    {
        if ( class_exists( '\\InvoiceNinja\\Plugin' ) && \InvoiceNinja\Plugin::booted() ) {
            return (string) \InvoiceNinja\Plugin::instance()->credentials->token();
        }

        // Without 2.0 an encrypted token can't be used
        return self::hasEncryptedToken() ? '' : (string) get_option( 'invoiceninja_api_token' );
    }

    public static function getBaseUrl()
    {
        if ( class_exists( '\\InvoiceNinja\\Plugin' ) && \InvoiceNinja\Plugin::booted() ) {
            $url = \InvoiceNinja\Plugin::instance()->credentials->url();

            return $url ? $url : 'https://invoicing.co';
        }

        $url = trim( (string) get_option( 'invoiceninja_api_url' ) );
        $url = rtrim( $url, '/' );
        $url = preg_replace( '#/api/v1$#i', '', $url );
        $url = rtrim( $url, '/' );

        return $url ? $url : 'https://invoicing.co';
    }
}