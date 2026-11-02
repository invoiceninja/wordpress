<?php
/**
 * Plugin Name: Invoice Ninja mock — showcase
 * Description: Dev only, and only when the in_mock_showcase option is set: a ceramics shop for screenshots (dev/showcase).
 */

function in_mock_showcase_products() {
    $rows = [
        [ 'SPECKLEDMUG', 'Speckled mug', 'Holds 350 ml. Oatmeal glaze with iron speckles; dishwasher safe.', 24, 12 ],
        [ 'SERVINGBOWL', 'Serving bowl', 'Wide and shallow, 26 cm across. Sage glaze inside, raw clay outside.', 38, 6 ],
        [ 'BUDVASE', 'Bud vase', 'For a single stem, about 12 cm tall. Each one is thrown by hand.', 19, 3 ],
        [ 'DINNERPLATES', 'Dinner plates, set of 4', 'Everyday plates in a soft lavender glaze, 27 cm.', 86, 0 ],
        [ 'TEAPOT', 'Teapot', 'Pours cleanly, holds 900 ml. Comes with a stoneware strainer.', 64, 4 ],
        [ 'PLANTER', 'Planter, small', 'With a drainage hole and saucer. Fits a 10 cm pot.', 22, 15 ],
        [ 'POUROVER', 'Pour-over cone', 'Fits standard #2 filters. Slate-blue glaze.', 32, 8 ],
        [ 'BUTTERDISH', 'Butter dish', 'Keeps a standard block of butter, with a lid.', 34, 5 ],
    ];
    $products = [];
    foreach ( $rows as [ $id, $key, $notes, $price, $stock ] ) {
        $products[] = [
            'id' => $id, 'product_key' => $key, 'notes' => $notes, 'price' => $price, 'cost' => 1, 'quantity' => 1,
            'max_quantity' => 0, 'in_stock_quantity' => $stock, 'custom_value1' => '', 'custom_value2' => '', 'custom_value3' => '',
            'custom_value4' => '', 'tax_name1' => '', 'tax_rate1' => 0, 'tax_name2' => '', 'tax_rate2' => 0, 'tax_name3' => '',
            'tax_rate3' => 0, 'tax_id' => '', 'product_image' => '', 'updated_at' => 1700000000, 'archived_at' => 0, 'is_deleted' => false,
        ];
    }
    return $products;
}

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    if ( false !== $pre || ! get_option( 'in_mock_showcase' ) || strpos( $url, 'https://in.test/api/v1/' ) !== 0 ) {
        return $pre;
    }
    $route  = substr( $url, strlen( 'https://in.test/api/v1/' ) );
    $path   = (string) strtok( $route, '?' );
    $method = $args['method'] ?? 'GET';
    $reply  = function ( $body ) {
        return [ 'headers' => [ 'x-app-version' => '5.13.52', 'content-type' => 'application/json' ], 'body' => wp_json_encode( $body ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
    };
    if ( 'products' === $path && 'GET' === $method ) {
        return $reply( [ 'data' => in_mock_showcase_products(), 'meta' => [ 'pagination' => [ 'total_pages' => 1 ] ] ] );
    }
    if ( preg_match( '#^products/([A-Z]+)$#', $path, $m ) && 'GET' === $method ) {
        foreach ( in_mock_showcase_products() as $product ) {
            if ( $product['id'] === $m[1] ) {
                return $reply( [ 'data' => $product ] );
            }
        }
    }
    // The member shops as Mei Lin: give her the member's fixed client.
    if ( 'clients' === $path && false !== strpos( urldecode( $route ), 'email=mei.lin@example.com' ) ) {
        return $reply( [ 'data' => [ [
            'id' => 'CLIENT_PLUS', 'client_hash' => 'HASH',
            'contacts' => [ [ 'id' => 'C1', 'email' => 'mei.lin@example.com', 'contact_key' => 'KEY_CLIENT_PLUS' ] ],
        ] ] ] );
    }
    if ( 0 === strpos( $path, 'companies/current' ) ) {
        return $reply( [ 'data' => [
            'id' => 'COMPANY1', 'track_inventory' => true, 'calculate_taxes' => false,
            'settings' => [ 'name' => 'Larch & Pine Ceramics', 'website' => '', 'currency_id' => '1', 'country_id' => '840', 'show_currency_code' => false, 'primary_color' => '#5d7a6b', 'company_logo' => '' ],
        ] ] );
    }
    return $pre;
}, 4, 3 );
