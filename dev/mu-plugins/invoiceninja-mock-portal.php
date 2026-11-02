<?php
/**
 * Plugin Name: Invoice Ninja mock — portal routes
 * Description: Dev only. One client with its contact's portal link, account tabs and PDFs.
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
    if ( preg_match( '#^clients/([A-Za-z0-9_]+)$#', $route, $m ) ) {
        if ( function_exists( 'in_mock_log' ) ) {
            in_mock_log( [ 'event' => 'http', 'method' => 'GET', 'url' => $url, 'body' => null, 'token' => '', 'redirection' => 0 ] );
        }
        return $json( [ 'data' => [ 'id' => $m[1], 'contacts' => [
            [ 'id' => 'OTHERCONTACT', 'email' => 'someone@else.test', 'link' => 'https://in.test/client/key_login/OTHER' ],
            [ 'id' => 'C1', 'email' => (string) ( array_search( $m[1], function_exists( 'in_mock_clients' ) ? in_mock_clients() : [], true ) ?: 'member@x.com' ), 'link' => 'https://in.test/client/key_login/KEY_' . $m[1] ],
        ] ] ] );
    }
    if ( preg_match( '#^(quotes|payments|credits)\?#', $route ) ) {
        return $json( [ 'data' => [], 'meta' => [ 'pagination' => [ 'total_pages' => 1 ] ] ] );
    }
    if ( preg_match( '#^invoice/([A-Za-z0-9]+)/download$#', $route ) ) {
        return [ 'headers' => [ 'content-type' => 'application/pdf' ], 'body' => '%PDF-1.4 mock ' . $route, 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
    }
    return $pre;
}, 5, 3 );
