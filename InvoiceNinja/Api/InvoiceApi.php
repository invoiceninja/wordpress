<?php

/**
 * @package Invoice Ninja
 */

namespace InvoiceNinja\Api;

use \InvoiceNinja\Api\ClientApi;

class InvoiceApi extends BaseApi
{
    public static function create($cart)
    {
        $user = wp_get_current_user();

        // Never look up a client without a known email: an empty email filter
        // makes the API return every client, attaching the invoice to a stranger.
        if ( ! is_user_logged_in() || empty( $user->user_email ) ) {
            return null;
        }

        if ( ! $client = ClientApi::find( $user->user_email ) ) {
            $client = ClientApi::create( $user );
        }

        if ( empty( $client->id ) ) {
            return null;
        }

        $invoice = [
            'client_id' => $client->id,
            'line_items' => [],
        ];

        foreach ($cart as $product_id => $quantity) {

            $quantity = (int) $quantity;

            if ( $quantity < 1 ) {
                continue;
            }

            $args = [
                'post_type' => 'invoiceninja_product',
                'meta_query' => [
                    [
                        'key' => 'product_id',
                        'value' => $product_id,
                        'compare' => 'EQUAL',
                    ],
                ],
            ];
            
            $query = new \WP_Query( $args );
            
            if ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();

                $url = get_permalink();
                $product = get_the_title();
                $content = get_the_content();

                $invoice['line_items'][] = [
                    'product_key' => $product,
                    'quantity' => $quantity,
                    'notes' => $content,
                    'cost' => get_post_meta( $post_id, 'price', true ),
                    'product_cost' => get_post_meta( $post_id, 'cost', true ),
                    'tax_name1' => get_post_meta( $post_id, 'tax_name1', true ),
                    'tax_rate1' => get_post_meta( $post_id, 'tax_rate1', true ),
                    'tax_name2' => get_post_meta( $post_id, 'tax_name2', true ),
                    'tax_rate2' => get_post_meta( $post_id, 'tax_rate2', true ),
                    'tax_name3' => get_post_meta( $post_id, 'tax_name3', true ),
                    'tax_rate3' => get_post_meta( $post_id, 'tax_rate3', true ),
                    'tax_id' => get_post_meta( $post_id, 'tax_id', true ),
                    'custom_value1' => get_post_meta( $post_id, 'custom_value1', true ),
                    'custom_value2' => get_post_meta( $post_id, 'custom_value2', true ),
                    'custom_value3' => get_post_meta( $post_id, 'custom_value3', true ),
                    'custom_value4' => get_post_meta( $post_id, 'custom_value4', true ),
                ];
            }
        }

        wp_reset_postdata();

        if ( empty( $invoice['line_items'] ) ) {
            return null;
        }

        $response = self::sendRequest( 'invoices?mark_sent=true', 'POST', $invoice );

        if ( $response ) {
            return json_decode( $response )->data;
        }

        return null;
    }
}