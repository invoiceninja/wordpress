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
 *   in_mock_calculate_taxes 1: the company calculates taxes from the client's address
 *                           (new invoices then get 19% on every line, ignoring the lines' own rates)
 *   in_mock_inclusive_taxes 1: the company's prices include tax
 *   in_mock_checkout_min_seconds  seconds from the first add to checkout (the flows set 0)
 *   in_mock_captcha_fail    1: a quote-form CAPTCHA (invoiceninja_captcha, ignoring its context) refuses everyone
 *   in_mock_checkout_captcha_fail     1: invoiceninja_checkout_captcha refuses everyone
 *   in_mock_pay_invoice_captcha_fail  1: invoiceninja_pay_invoice_captcha refuses everyone
 *
 * Known clients: a+b@x.com and member@x.com (the company's currency), euro@x.com (EUR).
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
        'euro@x.com'   => 'CLIENT_EURO',
    ];
}

// Client settings: CLIENT_EURO uses euros (currency 3); the others inherit the company's dollars
function in_mock_client_settings( $id ) {
    return $id === 'CLIENT_EURO' ? [ 'currency_id' => '3' ] : new stdClass();
}

// A quote-form CAPTCHA plugin that refuses everyone and ignores the context (in_mock_captcha_fail)
add_filter( 'invoiceninja_captcha', function ( $passed ) {
    return get_option( 'in_mock_captcha_fail' ) ? false : $passed;
} );
// CAPTCHA plugins hooked to checkout and Pay Invoice
add_filter( 'invoiceninja_checkout_captcha', function ( $passed ) {
    return get_option( 'in_mock_checkout_captcha_fail' ) ? false : $passed;
} );
add_filter( 'invoiceninja_pay_invoice_captcha', function ( $passed ) {
    return get_option( 'in_mock_pay_invoice_captcha_fail' ) ? false : $passed;
} );

// The checkout's minimum time from the first add (the flow tests turn it off except where they test it)
add_filter( 'invoiceninja_checkout_min_seconds', function ( $seconds ) {
    $set = get_option( 'in_mock_checkout_min_seconds', false );
    return false === $set ? $seconds : (int) $set;
} );

// The fake host doesn't exist in DNS; give the plugin's private-network check a public address.
// The flow tests run every queued job themselves; Action Scheduler's loopback runner would
// otherwise run the same job at the same moment in another request (run.py sets this).
add_filter( 'action_scheduler_allow_async_request_runner', function ( $allow ) {
    return get_option( 'in_mock_no_async' ) ? false : $allow;
} );

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
    // A module's own mock (dev/mu-plugins/invoiceninja-mock-*.php, priority 5) already answered.
    if ( false !== $pre ) {
        return $pre;
    }
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

    // 2.0: admin probe (an invalid webhook create: 422 for admins; for others 401 "This action
    // is unauthorized.", like Invoice Ninja 5.13)
    if ( $route === 'webhooks' && $method === 'POST' ) {
        return strpos( $token, 'nonadmin' ) === 0
            ? in_mock_response( [ 'message' => 'This action is unauthorized.' ], 401 )
            : in_mock_response( [ 'message' => 'The given data was invalid.', 'errors' => [ 'target_url' => [ 'required' ] ] ], 422 );
    }

    if ( strpos( $route, 'company_gateways' ) === 0 ) {
        return in_mock_response( [ 'data' => get_option( 'in_mock_no_gateway' ) ? [] : [ [ 'id' => 'G1', 'label' => 'Stripe', 'gateway_key' => 'd14dd26a37cecc30fdd65700bfb55b23', 'is_deleted' => false ] ] ] );
    }

    if ( strpos( $route, 'companies/current' ) === 0 ) {
        return in_mock_response( [ 'data' => [
            'id' => 'COMPANY1',
            'track_inventory' => (bool) get_option( 'in_mock_track_inventory' ),
            'calculate_taxes' => (bool) get_option( 'in_mock_calculate_taxes' ),
            'settings' => [
                'name' => 'Mock Company', 'website' => '', 'currency_id' => '1', 'country_id' => '840',
                'show_currency_code' => false, 'primary_color' => '#123456', 'company_logo' => '',
                'inclusive_taxes' => (bool) get_option( 'in_mock_inclusive_taxes' ), 'email' => 'billing@mock.test',
            ],
        ] ] );
    }

    if ( strpos( $route, 'statics' ) === 0 ) {
        return in_mock_response( [
            'currencies' => [
                [ 'id' => '1', 'code' => 'USD', 'symbol' => '$', 'precision' => 2, 'thousand_separator' => ',', 'decimal_separator' => '.', 'swap_currency_symbol' => false ],
                [ 'id' => '3', 'code' => 'EUR', 'symbol' => '€', 'precision' => 2, 'thousand_separator' => '.', 'decimal_separator' => ',', 'swap_currency_symbol' => true ],
            ],
            'countries'  => [
                [ 'id' => '840', 'name' => 'United States', 'iso_3166_2' => 'US', 'swap_currency_symbol' => false, 'thousand_separator' => '', 'decimal_separator' => '' ],
                [ 'id' => '276', 'name' => 'Germany', 'iso_3166_2' => 'DE', 'swap_currency_symbol' => false, 'thousand_separator' => '', 'decimal_separator' => '' ],
            ],
            'languages'  => [
                [ 'id' => '1', 'name' => 'English - United States', 'locale' => 'en' ],
                [ 'id' => '3', 'name' => 'German', 'locale' => 'de' ],
                [ 'id' => '23', 'name' => 'English - United Kingdom', 'locale' => 'en_GB' ],
            ],
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
        $stock_all = get_option( 'in_mock_stock_all', '' ); // Every product sold some: only the stock changed
        foreach ( $products as &$product ) {
            if ( '' !== (string) $stock_all ) {
                $product['in_stock_quantity'] = (int) $stock_all;
            }
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
                'id' => $clients[ $email ], 'client_hash' => 'HASH', 'settings' => in_mock_client_settings( $clients[ $email ] ),
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
            // Like the real API: a deleted, cancelled or reversed invoice can't be cancelled
            if ( isset( $invoices[ $id ] ) && ( $data['action'] ?? '' ) === 'cancel' && ( ! empty( $invoices[ $id ]['is_deleted'] ) || in_array( (string) $invoices[ $id ]['status_id'], [ '5', '6' ], true ) ) ) {
                return in_mock_response( [ 'message' => 'The given data was invalid.', 'errors' => [ 'action' => [ 'This invoice cannot be cancelled' ] ] ], 422 );
            }
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
        // Like Invoice Ninja: each line's tax rates, or its own rate when it calculates taxes
        $own_tax   = get_option( 'in_mock_calculate_taxes' ) ? 19.0 : null;
        $inclusive = ! empty( $data['uses_inclusive_taxes'] );
        foreach ( $data['line_items'] ?? [] as $line ) {
            $net     = round( (float) $line['cost'] * (float) $line['quantity'], 2 );
            $amount += $net;
            foreach ( null !== $own_tax ? [ $own_tax ] : [ $line['tax_rate1'] ?? 0, $line['tax_rate2'] ?? 0, $line['tax_rate3'] ?? 0 ] as $rate ) {
                $amount += $inclusive ? 0 : round( $net * (float) $rate / 100, 2 );
            }
        }
        $amount = round( $amount, 2 );
        $client_id = (string) ( $data['client_id'] ?? '' );
        $contact   = $client_id === 'NEWCLIENT' ? 'NC1' : 'C1';
        $mine      = [ 'key' => 'INVITE' . $n, 'client_contact_id' => $contact, 'link' => 'https://in.test/client/invoice/INVITE' . $n ];
        $other     = [ 'key' => 'OTHER' . $n, 'client_contact_id' => 'OTHERCONTACT', 'link' => 'https://in.test/client/invoice/OTHER' . $n ];
        $invoice = [
            'id' => $n === 1 ? 'INV1' : 'INV' . $n, 'number' => sprintf( '%04d', $n ), 'client_id' => $client_id,
            'po_number' => $data['po_number'] ?? '', 'amount' => $amount, 'balance' => $amount, 'paid_to_date' => 0, 'status_id' => '2',
            'created_at' => time(), 'line_items' => $data['line_items'] ?? [],
            'public_notes' => $data['public_notes'] ?? '', 'uses_inclusive_taxes' => (bool) ( $data['uses_inclusive_taxes'] ?? false ),
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
        // Changed in Invoice Ninja since: in_mock_invoice_state ("INV1:cancelled", "INV1:deleted")
        foreach ( array_filter( explode( ',', (string) get_option( 'in_mock_invoice_state', '' ) ) ) as $pair ) {
            [ $sid, $what ] = array_pad( explode( ':', $pair ), 2, '' );
            if ( $sid === $invoice['id'] ) {
                if ( 'deleted' === $what ) {
                    $invoice['is_deleted'] = true; // Soft-deleted: still 200, like the real API
                } elseif ( 'cancelled' === $what ) {
                    $invoice['status_id'] = '5';
                }
            }
        }
        if ( in_array( $invoice['id'], (array) get_option( 'in_mock_paid', [] ), true ) ) {
            $invoice['status_id']    = '4';
            $invoice['balance']      = 0;
            $invoice['paid_to_date'] = $invoice['amount'];
        }
        return in_mock_response( [ 'data' => $invoice ] );
    }

    return in_mock_response( [ 'message' => 'Mock: unhandled route ' . $route ], 404 );
}, 10, 3 );

// Dev only: the latest code emailed to an address, for the browser smoke test.
add_action( 'rest_api_init', function () {
    register_rest_route( 'in-mock/v1', '/code', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $request ) {
            $email = strtolower( (string) $request->get_param( 'email' ) );
            $code  = '';
            foreach ( file_exists( in_mock_log_path() ) ? file( in_mock_log_path() ) : [] as $line ) {
                $entry = json_decode( $line, true );
                if ( is_array( $entry ) && ( $entry['event'] ?? '' ) === 'mail' && false !== strpos( strtolower( wp_json_encode( $entry['to'] ) ), $email ) && preg_match( '/^(\d{6}) is your/', (string) $entry['subject'], $m ) ) {
                    $code = $m[1];
                }
            }
            return [ 'code' => $code ];
        },
    ] );
} );
