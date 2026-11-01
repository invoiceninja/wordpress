<?php
/**
 * Plugin Name: Invoice Ninja API mock (dev/test only)
 * Description: Fakes the Invoice Ninja API at https://in.test and logs every request the plugin makes.
 *
 * Scenario options (set with wp-cli, e.g. `wp option update in_mock_mode down`):
 *   in_mock_mode            up (default) | down (connection error) | empty (no products)
 *   in_mock_track_inventory 0 (default) | 1
 *
 * Never load this on a real site: it is only mounted by dev/docker-compose.yml.
 */

defined( 'ABSPATH' ) || exit;

const IN_MOCK_BASE = 'https://in.test/api/v1/';

function in_mock_log_path() {
    return WP_CONTENT_DIR . '/in-mock-requests.log';
}

function in_mock_log( array $entry ) {
    file_put_contents( in_mock_log_path(), wp_json_encode( $entry ) . "\n", FILE_APPEND );
}

function in_mock_response( $body, $code = 200 ) {
    return [
        'headers'  => [ 'x-app-version' => get_option( 'in_mock_version', '5.13.52' ) ],
        'body'     => wp_json_encode( $body ),
        'response' => [ 'code' => $code, 'message' => 'Mock' ],
        'cookies'  => [],
        'filename' => null,
    ];
}

function in_mock_product( $id, $key, $price, $max_quantity, $in_stock ) {
    return [
        'id' => $id, 'product_key' => $key, 'notes' => "$key description", 'price' => $price, 'cost' => 1,
        'quantity' => 1, 'max_quantity' => $max_quantity, 'in_stock_quantity' => $in_stock,
        'custom_value1' => '', 'custom_value2' => '', 'custom_value3' => '', 'custom_value4' => '',
        'tax_name1' => '', 'tax_rate1' => 0, 'tax_name2' => '', 'tax_rate2' => 0, 'tax_name3' => '', 'tax_rate3' => 0,
        'tax_id' => '', 'product_image' => '',
    ];
}

// Fixed known clients: emails mapped to client ids (contact emails are upper-cased to test case-insensitive matching)
function in_mock_clients() {
    return [
        'a+b@x.com'    => 'CLIENT_PLUS',
        'member@x.com' => 'CLIENT_MEMBER',
    ];
}

add_action( 'generate_rewrite_rules', function () {
    in_mock_log( [ 'event' => 'flush_rewrite_rules' ] );
} );

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    $method = isset( $args['method'] ) ? $args['method'] : 'GET';
    $body   = isset( $args['body'] ) && is_string( $args['body'] ) ? $args['body'] : null;

    $sent_token = isset( $args['headers']['X-API-TOKEN'] ) ? $args['headers']['X-API-TOKEN'] : '';
    in_mock_log( [ 'event' => 'http', 'method' => $method, 'url' => $url, 'body' => $body, 'token' => $sent_token, 'redirection' => isset( $args['redirection'] ) ? $args['redirection'] : null ] );

    if ( strpos( $url, IN_MOCK_BASE ) !== 0 ) {
        return $pre; // Not ours (WP core requests etc.)
    }

    if ( get_option( 'in_mock_mode' ) === 'down' ) {
        return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to in.test port 443 (mock: IN is down)' );
    }

    $token = isset( $args['headers']['X-API-TOKEN'] ) ? $args['headers']['X-API-TOKEN'] : '';
    if ( $token === 'badtoken123456' ) {
        return in_mock_response( [ 'message' => 'Invalid token', 'errors' => new stdClass() ], 403 );
    }
    if ( strpos( $token, 'freeplan' ) === 0 ) {
        return in_mock_response( [ 'message' => 'Feature not available with free / unpaid account.', 'errors' => new stdClass() ], 403 );
    }

    $route = substr( $url, strlen( IN_MOCK_BASE ) );

    // 2.0: admin probe (an invalid webhook create: 422 for admins, 403 for others)
    if ( $route === 'webhooks' && $method === 'POST' ) {
        return strpos( $token, 'nonadmin' ) === 0
            ? in_mock_response( [ 'message' => 'This action is unauthorized.' ], 403 )
            : in_mock_response( [ 'message' => 'The given data was invalid.', 'errors' => [ 'target_url' => [ 'required' ] ] ], 422 );
    }

    if ( strpos( $route, 'company_gateways' ) === 0 ) {
        return in_mock_response( [ 'data' => get_option( 'in_mock_no_gateway' ) ? [] : [ [ 'id' => 'G1', 'label' => 'Stripe', 'gateway_key' => 'd14dd26a37cecc30fdd65700bfb55b23', 'is_deleted' => false ] ] ] );
    }

    if ( strpos( $route, 'companies/current' ) === 0 ) {
        return in_mock_response( [ 'data' => [
            'id' => 'COMPANY1',
            'track_inventory' => (bool) get_option( 'in_mock_track_inventory' ),
            'settings' => [
                'name' => 'Mock Company', 'website' => '', 'currency_id' => '1', 'country_id' => '840',
                'show_currency_code' => false, 'primary_color' => '#123456', 'company_logo' => '',
            ],
        ] ] );
    }

    if ( strpos( $route, 'statics' ) === 0 ) {
        return in_mock_response( [
            'currencies' => [ [ 'id' => '1', 'code' => 'USD', 'symbol' => '$', 'precision' => 2, 'thousand_separator' => ',', 'decimal_separator' => '.', 'swap_currency_symbol' => false ] ],
            'countries'  => [ [ 'id' => '840', 'swap_currency_symbol' => false, 'thousand_separator' => '', 'decimal_separator' => '' ] ],
        ] );
    }

    if ( strpos( $route, 'products' ) === 0 ) {
        if ( get_option( 'in_mock_mode' ) === 'empty' ) {
            return in_mock_response( [ 'data' => [] ] );
        }

        return in_mock_response( [ 'data' => [
            in_mock_product( 'P1', 'Widget', 10, 5, 3 ),
            in_mock_product( 'P2', 'Gadget', 25.5, 0, 0 ),
        ] ] );
    }

    if ( strpos( $route, 'clients?email=' ) === 0 ) {
        $email   = rawurldecode( substr( $route, strlen( 'clients?email=' ) ) );
        $clients = in_mock_clients();

        if ( $email === '' ) {
            // Mirrors the real API: an empty email filter is ignored and every client is returned
            return in_mock_response( [ 'data' => [ [
                'id' => 'STRANGER', 'client_hash' => 'STRANGERHASH',
                'contacts' => [ [ 'id' => 'SC', 'email' => '', 'contact_key' => 'STRANGERKEY' ] ],
            ] ] ] );
        }

        if ( isset( $clients[ $email ] ) ) {
            return in_mock_response( [ 'data' => [ [
                'id' => $clients[ $email ], 'client_hash' => 'HASH',
                'contacts' => [ [ 'id' => 'C1', 'email' => strtoupper( $email ), 'contact_key' => 'KEY_' . $clients[ $email ] ] ],
            ] ] ] );
        }

        return in_mock_response( [ 'data' => [] ] );
    }

    if ( $route === 'clients' && $method === 'POST' ) {
        return in_mock_response( [ 'data' => [ 'id' => 'NEWCLIENT', 'contacts' => [] ] ] );
    }

    if ( preg_match( '#^clients/[^/?]+$#', $route ) && $method === 'PUT' ) {
        return in_mock_response( [ 'data' => [ 'id' => basename( $route ), 'contacts' => [] ] ] );
    }

    if ( strpos( $route, 'invoices' ) === 0 && $method === 'POST' ) {
        return in_mock_response( [ 'data' => [
            'id' => 'INV1',
            'invitations' => [ [ 'link' => 'https://in.test/client/invoice/INVITE1' ] ],
        ] ] );
    }

    return in_mock_response( [ 'message' => 'Mock: unhandled route ' . $route ], 404 );
}, 10, 3 );
