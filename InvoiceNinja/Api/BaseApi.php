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

        if ( \InvoiceNinja\Plugin::booted() ) {
            $plugin = \InvoiceNinja\Plugin::instance();

            // A staging copy or moved site must never create real invoices or clients
            if ( $method !== 'GET' && strpos( $route, 'companies/current' ) !== 0 && ! $plugin->environment->writesAllowed() ) {
                $plugin->logger->warning( 'checkout', 'Blocked a write to Invoice Ninja because orders are paused on this site', [ 'route' => strtok( $route, '?' ) ] );

                return null;
            }

            $base = self::getBaseUrl();
            if ( ! $plugin->state->allowsPrivate( $base ) && \InvoiceNinja\Api\HttpClient::isPrivate( \InvoiceNinja\Api\BaseUrl::host( $base ) ) ) {
                self::reportPrivateHost( $plugin, \InvoiceNinja\Api\BaseUrl::host( $base ) );

                return null;
            }
        }

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

    /**
     * A request was blocked because the address is on a private network that wasn't allowed:
     * log it and show admins why (at most once an hour), instead of failing silently.
     */
    private static function reportPrivateHost( $plugin, $host )
    {
        if ( get_transient( 'invoiceninja_private_host_blocked' ) ) {
            return;
        }
        set_transient( 'invoiceninja_private_host_blocked', 1, HOUR_IN_SECONDS );

        $plugin->logger->warning( 'connection', sprintf( 'Blocked a request to %s: it is on a private network and not allowed in the connection settings', $host ) );
        $plugin->state->markError( new \InvoiceNinja\Api\ApiException( \InvoiceNinja\Api\ErrorKind::PrivateHost, $host . ' resolves to a private or reserved address' ) );
    }

    /**
     * Whether a user has proved they own their account email (2.0's verification, through
     * the invoiceninja_is_verified filter). Nobody counts as verified without it.
     */
    public static function userVerified( $user_id )
    {
        $user_id = (int) $user_id;

        return $user_id > 0 && (bool) apply_filters( 'invoiceninja_is_verified', false, $user_id );
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