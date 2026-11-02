<?php
/**
 * Plugin Name: Invoice Ninja API mock — WooCommerce module (dev only)
 * Description: Payments, refunds, draft invoices, invoice updates and mark_sent for tests/flows/test_woocommerce.py.
 *
 * Shares in_mock_invoices with the main mock, so its GET, search and cancel work on these invoices too.
 *
 * Scenario options:
 *   in_mock_lock_invoices        off (default) | when_sent: companies/current reports it, and PUT on a sent invoice is refused
 *   in_mock_woo_invoice_timeout  1: POST invoices saves, then times out
 *   in_mock_payment_timeout      1: POST payments saves, then times out
 *
 * State: in_mock_payments (id => payment).
 */

const IN_MOCK_WOO_BASE = 'https://in.test/api/v1/';

/**
 * The main mock answers every in.test request without looking at an earlier answer, so
 * wrap its filter to let answers from priority 5 (this file) stand.
 */
add_action( 'muplugins_loaded', function () {
    global $wp_filter;
    if ( empty( $wp_filter['pre_http_request'] ) || empty( $wp_filter['pre_http_request']->callbacks[10] ) ) {
        return;
    }
    foreach ( $wp_filter['pre_http_request']->callbacks[10] as $callback ) {
        $fn = $callback['function'];
        if ( ! $fn instanceof Closure ) {
            continue;
        }
        $file = ( new ReflectionFunction( $fn ) )->getFileName();
        if ( 'invoiceninja-mock.php' !== basename( (string) $file ) ) {
            continue;
        }
        remove_filter( 'pre_http_request', $fn, 10 );
        add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $fn ) {
            return false !== $pre ? $pre : $fn( $pre, $args, $url );
        }, 10, 3 );
    }
}, 1 );

function in_mock_woo_response( $body, $code = 200 ) {
    return [
        'headers'  => [ 'x-app-version' => get_option( 'in_mock_version', '5.13.52' ) ],
        'body'     => wp_json_encode( $body ),
        'response' => [ 'code' => $code, 'message' => 'Mock' ],
        'cookies'  => [],
        'filename' => null,
    ];
}

/** Invoice Ninja's own sums: amount discounts, exclusive taxes rounded per line. */
function in_mock_woo_amount( array $lines ) {
    $sum = 0;
    foreach ( $lines as $line ) {
        $total = round( (float) ( $line['cost'] ?? 0 ) * (float) ( $line['quantity'] ?? 0 ) + 0.000000000000004, 2 ) - round( (float) ( $line['discount'] ?? 0 ), 2 );
        $sum  += $total;
        if ( 0.0 === round( $total, 2 ) ) {
            continue;
        }
        foreach ( [ 1, 2, 3 ] as $n ) {
            $sum += round( $total * (float) ( $line[ 'tax_rate' . $n ] ?? 0 ) / 100, 2 );
        }
    }
    return round( $sum, 2 );
}

function in_mock_woo_invitations( $client_id, $n ) {
    $contact = 'NEWCLIENT' === $client_id ? 'NC1' : 'C1';
    return [
        [ 'key' => 'OTHER' . $n, 'client_contact_id' => 'OTHERCONTACT', 'link' => 'https://in.test/client/invoice/OTHER' . $n ],
        [ 'key' => 'INVITE' . $n, 'client_contact_id' => $contact, 'link' => 'https://in.test/client/invoice/INVITE' . $n ],
    ];
}

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    if ( false !== $pre || 0 !== strpos( $url, IN_MOCK_WOO_BASE ) || get_option( 'in_mock_mode' ) === 'down' ) {
        return $pre;
    }
    $method = isset( $args['method'] ) ? $args['method'] : 'GET';
    $body   = isset( $args['body'] ) && is_string( $args['body'] ) ? $args['body'] : null;
    $data   = json_decode( (string) $body, true ) ?: [];
    $route  = substr( $url, strlen( IN_MOCK_WOO_BASE ) );
    $path   = strtok( $route, '?' );
    parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

    $answer = function ( $response ) use ( $method, $url, $body ) {
        if ( function_exists( 'in_mock_log' ) ) {
            in_mock_log( [ 'event' => 'http', 'method' => $method, 'url' => $url, 'body' => $body, 'token' => '', 'redirection' => 0, 'mock' => 'woocommerce' ] );
        }
        return $response;
    };

    // The company's invoice lock setting.
    if ( 'companies/current' === $path && get_option( 'in_mock_lock_invoices' ) ) {
        return $answer( in_mock_woo_response( [ 'data' => [
            'id' => 'COMPANY1', 'track_inventory' => false,
            'settings' => [ 'name' => 'Mock Company', 'currency_id' => '1', 'country_id' => '840', 'lock_invoices' => get_option( 'in_mock_lock_invoices' ) ],
        ] ] ) );
    }

    // Draft invoices for WooCommerce orders (a numeric po_number), with real totals.
    if ( 'invoices' === $path && 'POST' === $method && ctype_digit( (string) ( $data['po_number'] ?? '' ) ) && empty( $query['mark_sent'] ) ) {
        $invoices  = get_option( 'in_mock_invoices', [] );
        $n         = count( $invoices ) + 1;
        $client_id = (string) ( $data['client_id'] ?? '' );
        $amount    = in_mock_woo_amount( (array) ( $data['line_items'] ?? [] ) );
        $invoice   = [
            'id' => 'INV' . $n, 'number' => sprintf( '%04d', $n ), 'client_id' => $client_id, 'po_number' => (string) $data['po_number'],
            'amount' => $amount, 'balance' => 0, 'paid_to_date' => 0, 'status_id' => '1', 'is_deleted' => false, 'date' => (string) ( $data['date'] ?? '' ),
            'created_at' => time(), 'line_items' => $data['line_items'] ?? [], 'invitations' => in_mock_woo_invitations( $client_id, $n ),
        ];
        $invoices[ $invoice['id'] ] = $invoice;
        update_option( 'in_mock_invoices', $invoices, false );
        if ( get_option( 'in_mock_woo_invoice_timeout' ) ) {
            return $answer( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15000 milliseconds' ) );
        }
        return $answer( in_mock_woo_response( [ 'data' => $invoice ] ) );
    }

    // Updating an invoice's lines.
    if ( preg_match( '#^invoices/([^/?]+)$#', $path, $m ) && 'PUT' === $method ) {
        $invoices = get_option( 'in_mock_invoices', [] );
        if ( ! isset( $invoices[ $m[1] ] ) ) {
            return $answer( in_mock_woo_response( [ 'message' => 'Not found' ], 404 ) );
        }
        $invoice = $invoices[ $m[1] ];
        if ( 'when_sent' === get_option( 'in_mock_lock_invoices' ) && (int) $invoice['status_id'] >= 2 ) {
            return $answer( in_mock_woo_response( [ 'message' => '', 'errors' => [ 'number' => [ 'This invoice is locked' ] ] ], 422 ) );
        }
        if ( isset( $data['line_items'] ) ) {
            $invoice['line_items'] = $data['line_items'];
            $invoice['amount']     = in_mock_woo_amount( (array) $data['line_items'] );
            if ( '1' !== (string) $invoice['status_id'] ) {
                $invoice['balance'] = round( $invoice['amount'] - $invoice['paid_to_date'], 2 );
            }
        }
        $invoices[ $m[1] ] = $invoice;
        update_option( 'in_mock_invoices', $invoices, false );
        return $answer( in_mock_woo_response( [ 'data' => $invoice ] ) );
    }

    // Marking invoices sent (no email). Cancel and delete are the main mock's.
    if ( 'invoices/bulk' === $path && 'POST' === $method && 'mark_sent' === ( $data['action'] ?? '' ) ) {
        $invoices = get_option( 'in_mock_invoices', [] );
        $changed  = [];
        foreach ( (array) ( $data['ids'] ?? [] ) as $id ) {
            if ( isset( $invoices[ $id ] ) ) {
                if ( '1' === (string) $invoices[ $id ]['status_id'] ) {
                    $invoices[ $id ]['status_id'] = '2';
                    $invoices[ $id ]['balance']   = $invoices[ $id ]['amount'];
                }
                $changed[] = $invoices[ $id ];
            }
        }
        update_option( 'in_mock_invoices', $invoices, false );
        return $answer( in_mock_woo_response( [ 'data' => $changed ] ) );
    }

    // Payments.
    if ( 'payments' === $path && 'POST' === $method ) {
        $payments = get_option( 'in_mock_payments', [] );
        $key      = (string) ( $data['idempotency_key'] ?? '' );
        foreach ( $payments as $payment ) {
            if ( '' !== $key && $payment['idempotency_key'] === $key ) {
                return $answer( in_mock_woo_response( [ 'message' => 'The given data was invalid.', 'errors' => [ 'idempotency_key' => [ 'The idempotency key has already been taken.' ] ] ], 422 ) );
            }
        }
        $invoices     = get_option( 'in_mock_invoices', [] );
        $paymentables = [];
        foreach ( (array) ( $data['invoices'] ?? [] ) as $applied ) {
            $id = (string) ( $applied['invoice_id'] ?? '' );
            if ( ! isset( $invoices[ $id ] ) || (float) $applied['amount'] > (float) $invoices[ $id ]['balance'] + 0.001 ) {
                return $answer( in_mock_woo_response( [ 'message' => 'The given data was invalid.', 'errors' => [ 'invoices.0.amount' => [ 'Amount cannot be greater than invoice balance' ] ] ], 422 ) );
            }
            $invoices[ $id ]['paid_to_date'] = round( $invoices[ $id ]['paid_to_date'] + (float) $applied['amount'], 2 );
            $invoices[ $id ]['balance']      = round( $invoices[ $id ]['balance'] - (float) $applied['amount'], 2 );
            $invoices[ $id ]['status_id']    = $invoices[ $id ]['balance'] <= 0 ? '4' : '3';
            $paymentables[]                  = [ 'invoice_id' => $id, 'amount' => (float) $applied['amount'], 'refunded' => 0 ];
        }
        $n       = count( $payments ) + 1;
        $payment = [
            'id' => 'PAY' . $n, 'client_id' => (string) ( $data['client_id'] ?? '' ), 'amount' => (float) ( $data['amount'] ?? 0 ), 'refunded' => 0,
            'transaction_reference' => (string) ( $data['transaction_reference'] ?? '' ), 'idempotency_key' => $key, 'is_deleted' => false,
            'email_receipt' => isset( $query['email_receipt'] ) ? (string) $query['email_receipt'] : ( isset( $data['email_receipt'] ) ? (string) $data['email_receipt'] : null ),
            'paymentables' => $paymentables, 'refunds' => [],
        ];
        $payments[ $payment['id'] ] = $payment;
        update_option( 'in_mock_payments', $payments, false );
        update_option( 'in_mock_invoices', $invoices, false );
        if ( get_option( 'in_mock_payment_timeout' ) ) {
            return $answer( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15000 milliseconds' ) );
        }
        unset( $payment['idempotency_key'] ); // The real API doesn't return it.
        return $answer( in_mock_woo_response( [ 'data' => $payment ] ) );
    }

    if ( 'payments' === $path && 'GET' === $method ) {
        $found = array_values( array_filter( get_option( 'in_mock_payments', [] ), function ( $p ) use ( $query ) {
            if ( isset( $query['client_id'] ) && $p['client_id'] !== $query['client_id'] ) {
                return false;
            }
            return ! isset( $query['filter'] ) || false !== stripos( $p['transaction_reference'], (string) $query['filter'] );
        } ) );
        foreach ( $found as &$p ) {
            unset( $p['idempotency_key'] );
        }
        return $answer( in_mock_woo_response( [ 'data' => $found, 'meta' => [ 'pagination' => [ 'total_pages' => 1 ] ] ] ) );
    }

    if ( 'payments/refund' === $path && 'POST' === $method ) {
        $payments = get_option( 'in_mock_payments', [] );
        $id       = (string) ( $data['id'] ?? '' );
        if ( ! isset( $payments[ $id ] ) ) {
            return $answer( in_mock_woo_response( [ 'message' => 'The given data was invalid.', 'errors' => [ 'id' => [ 'Unable to retrieve payment' ] ] ], 422 ) );
        }
        $amount = (float) ( $data['amount'] ?? 0 );
        if ( $amount <= 0 || $payments[ $id ]['refunded'] + $amount > $payments[ $id ]['amount'] + 0.001 ) {
            return $answer( in_mock_woo_response( [ 'message' => 'The given data was invalid.', 'errors' => [ 'amount' => [ 'Refund exceeds the payment' ] ] ], 422 ) );
        }
        $invoices = get_option( 'in_mock_invoices', [] );
        foreach ( (array) ( $data['invoices'] ?? [] ) as $refunded ) {
            $inv = (string) ( $refunded['invoice_id'] ?? '' );
            if ( isset( $invoices[ $inv ] ) ) {
                $invoices[ $inv ]['balance']      = round( $invoices[ $inv ]['balance'] + (float) $refunded['amount'], 2 );
                $invoices[ $inv ]['paid_to_date'] = round( $invoices[ $inv ]['paid_to_date'] - (float) $refunded['amount'], 2 );
                $invoices[ $inv ]['status_id']    = $invoices[ $inv ]['balance'] >= $invoices[ $inv ]['amount'] ? '2' : '3';
            }
        }
        $payments[ $id ]['refunded'] = round( $payments[ $id ]['refunded'] + $amount, 2 );
        $payments[ $id ]['refunds'][] = [ 'amount' => $amount, 'gateway_refund' => $data['gateway_refund'] ?? null, 'send_email' => $data['send_email'] ?? null, 'invoices' => $data['invoices'] ?? [] ];
        update_option( 'in_mock_payments', $payments, false );
        update_option( 'in_mock_invoices', $invoices, false );
        $payment = $payments[ $id ];
        unset( $payment['idempotency_key'] );
        return $answer( in_mock_woo_response( [ 'data' => $payment ] ) );
    }

    if ( preg_match( '#^payments/([^/?]+)$#', $path, $m ) && 'GET' === $method ) {
        $payments = get_option( 'in_mock_payments', [] );
        if ( ! isset( $payments[ $m[1] ] ) ) {
            return $answer( in_mock_woo_response( [ 'message' => 'Not found' ], 404 ) );
        }
        $payment = $payments[ $m[1] ];
        unset( $payment['idempotency_key'] );
        return $answer( in_mock_woo_response( [ 'data' => $payment ] ) );
    }

    return false; // Everything else is the main mock's.
}, 5, 3 );
