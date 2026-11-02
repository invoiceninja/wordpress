<?php
/**
 * Plugin Name: Invoice Ninja mock — portal routes
 * Description: Dev only. Account tabs (quotes, payments, credits) and invoice PDFs.
 */

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    if ( strpos( $url, 'https://in.test/api/v1/' ) !== 0 ) {
        return $pre;
    }
    $route  = substr( $url, strlen( 'https://in.test/api/v1/' ) );
    $method = $args['method'] ?? 'GET';
    if ( $method !== 'GET' ) {
        return $pre;
    }
    $json = function ( $body ) {
        return [ 'headers' => [ 'x-app-version' => get_option( 'in_mock_version', '5.13.52' ), 'content-type' => 'application/json' ], 'body' => wp_json_encode( $body ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
    };
    // clients/{id} (with each contact's link) comes from invoiceninja-mock-webhooks.php.
    // Payment lists come from the WooCommerce mock; quotes and credits are empty here.
    if ( preg_match( '#^(quotes|credits)\?#', $route ) ) {
        return $json( [ 'data' => [], 'meta' => [ 'pagination' => [ 'total_pages' => 1 ] ] ] );
    }
    if ( preg_match( '#^invoice/([A-Za-z0-9]+)/download$#', $route ) ) {
        return [ 'headers' => [ 'content-type' => 'application/pdf' ], 'body' => '%PDF-1.4 mock ' . $route, 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
    }
    return $pre;
}, 5, 3 );
