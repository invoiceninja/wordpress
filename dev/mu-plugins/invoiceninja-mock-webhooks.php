<?php
/**
 * Plugin Name: Invoice Ninja API mock: webhooks (dev/test only)
 * Description: Fakes the webhook, payment, client, quote and single-product routes for the M4 flows.
 *
 * Scenario options:
 *   in_mock_webhooks              id => webhook (what Invoice Ninja has stored)
 *   in_mock_webhooks_forbidden    1: creating webhooks is refused (403, no API feature / not admin)
 *   in_mock_webhooks_unreachable  1: creating webhooks fails validation (422 on target_url)
 *   in_mock_payments              id => payment (with invoices)
 *   in_mock_product_override      id => product fields returned by GET products/{id}
 *   in_mock_missing               list of "type/id" that return 404
 *
 * Runs before the main mock (priority 5) and only answers its own routes.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    $base = 'https://in.test/api/v1/';
    if ( false !== $pre || strpos( $url, $base ) !== 0 || get_option( 'in_mock_mode' ) === 'down' ) {
        return $pre;
    }
    $method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
    $route  = substr( $url, strlen( $base ) );
    $path   = (string) strtok( $route, '?' );
    $body   = isset( $args['body'] ) && is_string( $args['body'] ) ? json_decode( $args['body'], true ) : null;
    $reply  = function ( $data, $code = 200 ) use ( $method, $url, $args ) {
        if ( function_exists( 'in_mock_log' ) ) {
            in_mock_log( [ 'event' => 'http', 'method' => $method, 'url' => $url, 'body' => isset( $args['body'] ) && is_string( $args['body'] ) ? $args['body'] : null, 'token' => $args['headers']['X-API-TOKEN'] ?? '', 'redirection' => $args['redirection'] ?? null ] );
        }
        return [
            'headers'  => [ 'x-app-version' => get_option( 'in_mock_version', '5.13.52' ) ],
            'body'     => wp_json_encode( $data ),
            'response' => [ 'code' => $code, 'message' => 'Mock' ],
            'cookies'  => [],
            'filename' => null,
        ];
    };
    if ( in_array( $path, (array) get_option( 'in_mock_missing', [] ), true ) ) {
        return $reply( [ 'message' => 'Record not found' ], 404 );
    }

    // ---------- webhooks ----------
    if ( $path === 'webhooks' && $method === 'GET' ) {
        return $reply( [ 'data' => array_values( (array) get_option( 'in_mock_webhooks', [] ) ), 'meta' => [ 'pagination' => [ 'total_pages' => 1 ] ] ] );
    }
    if ( $path === 'webhooks' && $method === 'POST' ) {
        if ( empty( $body ) ) {
            return false; // The connection test's probe: the main mock answers it.
        }
        if ( get_option( 'in_mock_webhooks_forbidden' ) ) {
            return $reply( [ 'message' => 'This action is unauthorized.' ], 403 );
        }
        if ( get_option( 'in_mock_webhooks_unreachable' ) ) {
            return $reply( [ 'message' => 'The given data was invalid.', 'errors' => [ 'target_url' => [ 'Unable to resolve hostname.' ] ] ], 422 );
        }
        $hooks = (array) get_option( 'in_mock_webhooks', [] );
        $id    = 'WH' . ( count( $hooks ) + 1 );
        $hooks[ $id ] = [
            'id' => $id, 'target_url' => (string) ( $body['target_url'] ?? '' ), 'event_id' => (string) ( $body['event_id'] ?? '' ),
            'format' => (string) ( $body['format'] ?? 'JSON' ), 'rest_method' => (string) ( $body['rest_method'] ?? 'post' ),
            'headers' => (array) ( $body['headers'] ?? [] ), 'is_deleted' => false, 'archived_at' => 0,
        ];
        update_option( 'in_mock_webhooks', $hooks, false );
        return $reply( [ 'data' => $hooks[ $id ] ] );
    }
    if ( $path === 'webhooks/bulk' && $method === 'POST' ) {
        $hooks   = (array) get_option( 'in_mock_webhooks', [] );
        $changed = [];
        foreach ( (array) ( $body['ids'] ?? [] ) as $id ) {
            if ( isset( $hooks[ $id ] ) && ( $body['action'] ?? '' ) === 'delete' ) {
                $hooks[ $id ]['is_deleted']  = true;
                $hooks[ $id ]['archived_at'] = time();
                $changed[]                   = $hooks[ $id ];
            }
        }
        update_option( 'in_mock_webhooks', $hooks, false );
        return $reply( [ 'data' => $changed ] );
    }
    if ( preg_match( '#^webhooks/([A-Za-z0-9]+)$#', $path, $m ) ) {
        $hooks = (array) get_option( 'in_mock_webhooks', [] );
        if ( ! isset( $hooks[ $m[1] ] ) ) {
            return $reply( [ 'message' => 'Record not found' ], 404 );
        }
        if ( $method === 'PUT' ) {
            $hooks[ $m[1] ] = array_merge( $hooks[ $m[1] ], array_intersect_key( (array) $body, array_flip( [ 'target_url', 'event_id', 'format', 'rest_method', 'headers' ] ) ) );
            update_option( 'in_mock_webhooks', $hooks, false );
        } elseif ( $method === 'DELETE' ) {
            $hooks[ $m[1] ]['is_deleted'] = true;
            update_option( 'in_mock_webhooks', $hooks, false );
        }
        return $reply( [ 'data' => $hooks[ $m[1] ] ] );
    }

    // ---------- entities fetched again by the Dispatcher ----------
    if ( preg_match( '#^payments/([A-Za-z0-9]+)$#', $path, $m ) && $method === 'GET' ) {
        $payments = (array) get_option( 'in_mock_payments', [] );
        return isset( $payments[ $m[1] ] ) ? $reply( [ 'data' => $payments[ $m[1] ] ] ) : $reply( [ 'message' => 'Record not found' ], 404 );
    }
    if ( preg_match( '#^quotes/([A-Za-z0-9]+)$#', $path, $m ) && $method === 'GET' ) {
        return $reply( [ 'data' => [ 'id' => $m[1], 'number' => 'Q-' . $m[1], 'status_id' => '4', 'client_id' => 'CLIENT_MEMBER', 'amount' => 100 ] ] );
    }
    if ( preg_match( '#^clients/([A-Za-z0-9_]+)$#', $path, $m ) && $method === 'GET' ) {
        $email = strtolower( $m[1] ) . '@x.com';
        foreach ( ( function_exists( 'in_mock_clients' ) ? in_mock_clients() : [] ) as $known => $id ) {
            $email = $id === $m[1] ? $known : $email;
        }
        return $reply( [ 'data' => [
            'id' => $m[1], 'name' => 'Client ' . $m[1], 'is_deleted' => false, 'archived_at' => 0,
            'contacts' => [
                [ 'id' => 'C1', 'first_name' => 'Pat', 'last_name' => 'Primary', 'email' => $email, 'is_primary' => true, 'link' => 'https://in.test/client/key_login/KEY_' . $m[1] ],
                [ 'id' => 'C2', 'first_name' => 'Other', 'last_name' => 'Contact', 'email' => 'other.' . $email, 'is_primary' => false, 'link' => 'https://in.test/client/key_login/OTHER_' . $m[1] ],
            ],
        ] ] );
    }
    if ( preg_match( '#^products/([A-Za-z0-9]+)$#', $path, $m ) && $method === 'GET' ) {
        $override = (array) get_option( 'in_mock_product_override', [] );
        if ( ! isset( $override[ $m[1] ] ) ) {
            return false; // The main mock's catalogue.
        }
        return $reply( [ 'data' => array_merge( [
            'id' => $m[1], 'product_key' => 'Product ' . $m[1], 'notes' => '', 'price' => 1, 'cost' => 1, 'quantity' => 1,
            'max_quantity' => 0, 'in_stock_quantity' => 0, 'custom_value1' => '', 'custom_value2' => '', 'custom_value3' => '',
            'custom_value4' => '', 'tax_name1' => '', 'tax_rate1' => 0, 'tax_name2' => '', 'tax_rate2' => 0, 'tax_name3' => '',
            'tax_rate3' => 0, 'tax_id' => '', 'product_image' => '', 'is_deleted' => false, 'archived_at' => 0, 'updated_at' => time(),
        ], (array) $override[ $m[1] ] ) ] );
    }
    return false;
}, 5, 3 );
