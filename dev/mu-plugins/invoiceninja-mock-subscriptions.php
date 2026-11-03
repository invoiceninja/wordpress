<?php
/**
 * Plugin Name: Invoice Ninja API mock: subscriptions (dev/test only)
 * Description: Fakes subscriptions, recurring invoices, overdue invoices, a provisioning receiver and a "legacy" callback URL for the M4 flows.
 *
 * Scenario options:
 *   in_mock_subscription_config   id => webhook_configuration (what Invoice Ninja has stored; PUT updates it)
 *   in_mock_subscription_fields   id => fields merged into that subscription (e.g. trial_enabled)
 *   in_mock_subscription_put_fail 1: PUT subscriptions/{id} fails with a 500
 *   in_mock_recurring             id => recurring invoice
 *   in_mock_relay_fail            1: https://relay.test answers 500
 *   in_mock_legacy_reply          JSON reply of https://legacy.test/hook (default {"message":"Success"})
 *   in_mock_legacy_down           1: https://legacy.test times out
 * Invoices (with subscription_id, recurring_id, due_date) live in the main mock's in_mock_invoices.
 *
 * Runs before the main mock (priority 5) and only answers its own routes.
 */

defined( 'ABSPATH' ) || exit;

// Test stand-ins for filters other code provides: user meta in_mock_verified marks a user's
// email verified (the Portal module decides this on real sites), and option in_mock_eligible
// ("false", "throw" or a sentence) answers the eligibility filter.
add_filter( 'invoiceninja_is_verified', function ( $verified, $user_id ) {
    return $verified || (bool) get_user_meta( (int) $user_id, 'in_mock_verified', true );
}, 20, 2 );
add_filter( 'invoiceninja_subscription_eligible', function ( $eligible ) {
    $answer = get_option( 'in_mock_eligible', '' );
    if ( 'throw' === $answer ) {
        throw new RuntimeException( 'mock eligibility check broke' );
    }
    return '' === $answer ? $eligible : ( 'false' === $answer ? false : $answer );
} );

function in_mock_subscriptions() {
    $defaults = [
        'SUBM' => [ 'name' => 'Pro Monthly', 'price' => 10, 'frequency_id' => '5', 'trial_enabled' => false, 'trial_duration' => 0, 'allow_cancellation' => true, 'allow_plan_changes' => true ],
        'SUBY' => [ 'name' => 'Pro Yearly', 'price' => 100, 'frequency_id' => '10', 'trial_enabled' => false, 'trial_duration' => 0, 'allow_cancellation' => true, 'allow_plan_changes' => true ],
        'SUBT' => [ 'name' => 'Starter', 'price' => 5, 'frequency_id' => '5', 'trial_enabled' => true, 'trial_duration' => 14 * 86400, 'allow_cancellation' => false, 'allow_plan_changes' => false ],
        'SUBW' => [ 'name' => 'Reseller', 'price' => 50, 'frequency_id' => '5', 'trial_enabled' => false, 'trial_duration' => 0, 'allow_cancellation' => false, 'allow_plan_changes' => false ],
    ];
    foreach ( (array) get_option( 'in_mock_subscription_fields', [] ) as $id => $fields ) {
        if ( isset( $defaults[ $id ] ) && is_array( $fields ) ) {
            $defaults[ $id ] = array_merge( $defaults[ $id ], $fields );
        }
    }
    $configs = (array) get_option( 'in_mock_subscription_config', [] );
    $initial = [
        'SUBM' => [ 'post_purchase_url' => 'https://legacy.test/hook', 'post_purchase_rest_method' => 'post', 'post_purchase_headers' => [ 'X-Legacy' => 'yes' ], 'return_url' => 'https://legacy.test/thanks' ],
        'SUBW' => [ 'post_purchase_url' => 'https://live.example.com/wp-json/invoiceninja/v1/subscription-callback', 'post_purchase_rest_method' => 'post', 'post_purchase_headers' => [], 'return_url' => '' ],
    ];
    $out = [];
    foreach ( $defaults as $id => $sub ) {
        $out[ $id ] = $sub + [
            'id' => $id, 'product_ids' => '', 'recurring_product_ids' => 'P1', 'currency_id' => '1', 'group_id' => '',
            'purchase_page' => 'https://in.test/client/subscriptions/' . $id . '/purchase', 'steps' => 'cart,auth.login-or-register',
            'is_deleted' => false, 'archived_at' => 0, 'promo_price' => 0,
            'webhook_configuration' => $configs[ $id ] ?? ( $initial[ $id ] ?? [ 'post_purchase_url' => '', 'post_purchase_rest_method' => 'post', 'post_purchase_headers' => [], 'return_url' => '' ] ),
        ];
    }
    return $out;
}

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    if ( false !== $pre ) {
        return $pre;
    }
    $method  = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
    $raw     = isset( $args['body'] ) && is_string( $args['body'] ) ? $args['body'] : '';
    $log     = function ( array $entry ) {
        if ( function_exists( 'in_mock_log' ) ) {
            in_mock_log( $entry );
        }
    };
    $respond = function ( $data, $code = 200 ) {
        return [
            'headers'  => [ 'x-app-version' => get_option( 'in_mock_version', '5.13.52' ) ],
            'body'     => is_string( $data ) ? $data : wp_json_encode( $data ),
            'response' => [ 'code' => $code, 'message' => 'Mock' ],
            'cookies'  => [],
            'filename' => null,
        ];
    };

    // A provisioning system receiving the relay.
    if ( strpos( $url, 'https://relay.test/' ) === 0 ) {
        $log( [ 'event' => 'relay', 'url' => $url, 'method' => $method, 'headers' => $args['headers'] ?? [], 'body' => $raw ] );
        return get_option( 'in_mock_relay_fail' ) ? $respond( [ 'error' => 'down' ], 500 ) : $respond( [ 'ok' => true ] );
    }
    // The integration a subscription had before opting in.
    if ( strpos( $url, 'https://legacy.test/' ) === 0 ) {
        $log( [ 'event' => 'legacy', 'url' => $url, 'method' => $method, 'headers' => $args['headers'] ?? [], 'body' => $raw, 'timeout' => $args['timeout'] ?? null ] );
        if ( get_option( 'in_mock_legacy_down' ) ) {
            return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 1000 milliseconds' );
        }
        return $respond( (string) get_option( 'in_mock_legacy_reply', '{"message":"Success"}' ) );
    }

    $base = 'https://in.test/api/v1/';
    if ( strpos( $url, $base ) !== 0 || get_option( 'in_mock_mode' ) === 'down' ) {
        return false;
    }
    $route = substr( $url, strlen( $base ) );
    $path  = (string) strtok( $route, '?' );
    parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
    $reply = function ( $data, $code = 200 ) use ( $respond, $log, $method, $url, $raw, $args ) {
        $log( [ 'event' => 'http', 'method' => $method, 'url' => $url, 'body' => $raw ?: null, 'token' => $args['headers']['X-API-TOKEN'] ?? '', 'redirection' => $args['redirection'] ?? null ] );
        return $respond( $data, $code );
    };

    if ( $path === 'subscriptions' && $method === 'GET' ) {
        return $reply( [ 'data' => array_values( in_mock_subscriptions() ), 'meta' => [ 'pagination' => [ 'total_pages' => 1 ] ] ] );
    }
    if ( preg_match( '#^subscriptions/([A-Za-z0-9]+)$#', $path, $m ) ) {
        $subs = in_mock_subscriptions();
        if ( ! isset( $subs[ $m[1] ] ) ) {
            return $reply( [ 'message' => 'Record not found' ], 404 );
        }
        if ( $method === 'PUT' ) {
            if ( get_option( 'in_mock_subscription_put_fail' ) ) {
                return $reply( [ 'message' => 'Server Error' ], 500 );
            }
            $body = json_decode( $raw, true ) ?: [];
            if ( empty( $body['steps'] ) ) {
                return $reply( [ 'message' => 'The given data was invalid.', 'errors' => [ 'steps' => [ 'The steps field is required.' ] ] ], 422 );
            }
            $configs          = (array) get_option( 'in_mock_subscription_config', [] );
            $configs[ $m[1] ] = (array) ( $body['webhook_configuration'] ?? [] );
            update_option( 'in_mock_subscription_config', $configs, false );
            $subs = in_mock_subscriptions();
        }
        return $reply( [ 'data' => $subs[ $m[1] ] ] );
    }

    if ( preg_match( '#^recurring_invoices/([A-Za-z0-9]+)$#', $path, $m ) && $method === 'GET' ) {
        $all = (array) get_option( 'in_mock_recurring', [] );
        return isset( $all[ $m[1] ] ) ? $reply( [ 'data' => $all[ $m[1] ] ] ) : $reply( [ 'message' => 'Record not found' ], 404 );
    }
    if ( $path === 'recurring_invoices' && $method === 'GET' ) {
        $all = array_values( array_filter( (array) get_option( 'in_mock_recurring', [] ), function ( $ri ) use ( $query ) {
            return ! isset( $query['client_id'] ) || $ri['client_id'] === $query['client_id'];
        } ) );
        return $reply( [ 'data' => $all, 'meta' => [ 'pagination' => [ 'total_pages' => 1 ] ] ] );
    }

    // Overdue invoices (client_status=overdue): sent or partial, past due, not paid.
    if ( $path === 'invoices' && $method === 'GET' && ( $query['client_status'] ?? '' ) === 'overdue' ) {
        $paid  = (array) get_option( 'in_mock_paid', [] );
        $today = gmdate( 'Y-m-d' );
        $found = array_values( array_filter( (array) get_option( 'in_mock_invoices', [] ), function ( $inv ) use ( $query, $paid, $today ) {
            return in_array( (string) $inv['status_id'], [ '2', '3' ], true ) && ! in_array( $inv['id'], $paid, true )
                && ! empty( $inv['due_date'] ) && $inv['due_date'] < $today
                && ( ! isset( $query['client_id'] ) || $inv['client_id'] === $query['client_id'] );
        } ) );
        return $reply( [ 'data' => $found, 'meta' => [ 'pagination' => [ 'total_pages' => 1 ] ] ] );
    }
    return false;
}, 5, 3 );
