<?php
/**
 * Plugin Name: Invoice Ninja API mock (dev/test only)
 * Description: Fakes the Invoice Ninja API at https://in.test and logs every request the plugin makes.
 *
 * Scenario options (set with wp-cli, e.g. `wp option update in_mock_mode down`):
 *   in_mock_mode            up (default) | down (connection error) | empty (no products)
 *   in_mock_track_inventory 0 (default) | 1
 *   in_mock_live_stock      "P1:1" stock returned by GET products/{id} (changed since the sync)
 *   in_mock_slow_stock      1: GET products/{id} times out
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

// The fake host doesn't exist in DNS; give the plugin's private-network check a public address.
add_filter( 'invoiceninja_pre_resolve_host', function ( $ips, $host ) {
    return $host === 'in.test' ? [ '93.184.216.34' ] : $ips;
}, 10, 2 );

// Capture outgoing email (there is no mail server in the dev stack)
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
    in_mock_log( [ 'event' => 'mail', 'to' => $atts['to'], 'subject' => $atts['subject'], 'body' => $atts['message'] ] );
    return true;
}, 10, 2 );

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

    // One product: in_mock_live_stock ("P1:1,P2:0") is stock that changed since the last sync
    if ( preg_match( '#^products/([A-Za-z0-9]+)$#', $route, $pm ) && $method === 'GET' ) {
        if ( get_option( 'in_mock_slow_stock' ) ) {
            return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 1500 milliseconds' );
        }
        $product = in_mock_product( $pm[1], $pm[1] === 'P1' ? 'Widget' : 'Gadget', $pm[1] === 'P1' ? 10 : 25.5, $pm[1] === 'P1' ? 5 : 0, $pm[1] === 'P1' ? 3 : 0 );
        foreach ( array_filter( explode( ',', (string) get_option( 'in_mock_live_stock', '' ) ) ) as $pair ) {
            [ $id, $stock ] = array_pad( explode( ':', $pair ), 2, '0' );
            if ( $id === $pm[1] ) {
                $product['in_stock_quantity'] = (int) $stock;
            }
        }
        return in_mock_response( [ 'data' => $product ] );
    }

    if ( strpos( $route, 'products' ) === 0 ) {
        if ( get_option( 'in_mock_mode' ) === 'empty' ) {
            return in_mock_response( [ 'data' => [] ] );
        }

        $bump     = (float) get_option( 'in_mock_price_bump', 0 );
        $archived = array_filter( explode( ',', (string) get_option( 'in_mock_archived', '' ) ) );
        $products = [
            in_mock_product( 'P1', 'Widget', 10 + $bump, 5, 3 ),
            in_mock_product( 'P2', 'Gadget', 25.5 + $bump, 0, 0 ),
        ];
        for ( $i = 3; $i <= (int) get_option( 'in_mock_many', 0 ); $i++ ) {
            $products[] = in_mock_product( 'P' . $i, 'Extra ' . $i, 5 + $bump, 0, 0 );
        }
        foreach ( $products as &$product ) {
            $product['updated_at']  = 1700000000;
            $product['archived_at'] = in_array( $product['id'], $archived, true ) ? 1700000000 : 0;
            $product['is_deleted']  = false;
        }
        return in_mock_response( [ 'data' => $products ] );
    }

    if ( strpos( $route, 'clients?email=' ) === 0 ) {
        parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $cq );
        $email   = (string) ( $cq['email'] ?? '' );
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
        $data = json_decode( (string) $body, true ) ?: [];
        return in_mock_response( [ 'data' => [ 'id' => 'NEWCLIENT', 'name' => $data['name'] ?? '', 'contacts' => [ [ 'id' => 'NC1', 'email' => $data['contacts'][0]['email'] ?? '' ] ] ] ] );
    }

    if ( preg_match( '#^clients/[^/?]+$#', $route ) && $method === 'PUT' ) {
        return in_mock_response( [ 'data' => [ 'id' => basename( $route ), 'contacts' => [] ] ] );
    }

    if ( $route === 'invoices/bulk' && $method === 'POST' ) {
        $data     = json_decode( (string) $body, true ) ?: [];
        $invoices = get_option( 'in_mock_invoices', [] );
        $changed  = [];
        foreach ( (array) ( $data['ids'] ?? [] ) as $id ) {
            if ( isset( $invoices[ $id ] ) && ( $data['action'] ?? '' ) === 'cancel' ) {
                $invoices[ $id ]['status_id'] = '5';
                $changed[] = $invoices[ $id ];
            } elseif ( isset( $invoices[ $id ] ) && ( $data['action'] ?? '' ) === 'delete' ) {
                $invoices[ $id ]['is_deleted'] = true;
                $changed[] = $invoices[ $id ];
            }
        }
        update_option( 'in_mock_invoices', $invoices, false );
        return in_mock_response( [ 'data' => $changed ] );
    }

    // 2.0: invoices are remembered so they can be searched by po_number and fetched by id
    if ( strpos( $route, 'invoices' ) === 0 && $method === 'POST' ) {
        $data     = json_decode( (string) $body, true ) ?: [];
        $invoices = get_option( 'in_mock_invoices', [] );
        $n        = count( $invoices ) + 1;
        $amount   = 0;
        foreach ( $data['line_items'] ?? [] as $line ) {
            $amount += (float) $line['cost'] * (float) $line['quantity'];
        }
        $client_id = (string) ( $data['client_id'] ?? '' );
        $contact   = $client_id === 'NEWCLIENT' ? 'NC1' : 'C1';
        $mine      = [ 'key' => 'INVITE' . $n, 'client_contact_id' => $contact, 'link' => 'https://in.test/client/invoice/INVITE' . $n ];
        $other     = [ 'key' => 'OTHER' . $n, 'client_contact_id' => 'OTHERCONTACT', 'link' => 'https://in.test/client/invoice/OTHER' . $n ];
        $invoice = [
            'id' => $n === 1 ? 'INV1' : 'INV' . $n, 'number' => sprintf( '%04d', $n ), 'client_id' => $client_id,
            'po_number' => $data['po_number'] ?? '', 'amount' => $amount, 'balance' => $amount, 'paid_to_date' => 0, 'status_id' => '2',
            'created_at' => time(), 'line_items' => $data['line_items'] ?? [],
            // Another contact's invitation first, like a client whose primary contact isn't the buyer
            'invitations' => get_option( 'in_mock_other_contact_first' ) ? [ $other, $mine ] : [ $mine, $other ],
        ];
        $invoices[ $invoice['id'] ] = $invoice;
        update_option( 'in_mock_invoices', $invoices, false );
        if ( get_option( 'in_mock_invoice_timeout' ) ) {
            // Invoice Ninja saved it, but the response never arrived
            return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 8000 milliseconds' );
        }
        return in_mock_response( [ 'data' => $invoice ] );
    }

    if ( strpos( $route, 'invoices?' ) === 0 && $method === 'GET' ) {
        parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
        // Like the real API: no po_number filter; `filter` is a LIKE search, client_id narrows to a client
        $found = array_values( array_filter( get_option( 'in_mock_invoices', [] ), function ( $inv ) use ( $q ) {
            if ( isset( $q['client_id'] ) && $inv['client_id'] !== $q['client_id'] ) {
                return false;
            }
            return ! isset( $q['filter'] ) || stripos( $inv['po_number'] . ' ' . $inv['number'], $q['filter'] ) !== false;
        } ) );
        return in_mock_response( [ 'data' => $found, 'meta' => [ 'pagination' => [ 'total_pages' => 1 ] ] ] );
    }

    if ( preg_match( '#^invoices/([^/?]+)#', $route, $m ) && $method === 'GET' ) {
        $invoices = get_option( 'in_mock_invoices', [] );
        if ( ! isset( $invoices[ $m[1] ] ) ) {
            return in_mock_response( [ 'message' => 'Not found' ], 404 );
        }
        $invoice = $invoices[ $m[1] ];
        if ( in_array( $invoice['id'], (array) get_option( 'in_mock_paid', [] ), true ) ) {
            $invoice['status_id']    = '4';
            $invoice['balance']      = 0;
            $invoice['paid_to_date'] = $invoice['amount'];
        }
        return in_mock_response( [ 'data' => $invoice ] );
    }

    return in_mock_response( [ 'message' => 'Mock: unhandled route ' . $route ], 404 );
}, 10, 3 );
