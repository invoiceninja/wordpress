<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

use \App\InvoiceNinja\ClientApi;

class InvoiceApi extends BaseApi
{
    public static function create($cart)
    {
        $user = wp_get_current_user();        

        if ( ! $client = ClientApi::find( $user->user_email ) ) {            
            $data = [
                'contacts' => array(
                    array(
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'email' => $user->user_email,
                        'phone' => $user->billing_phone,
                    ),
                ),
                'name' => $user->nickname,
                'address1' => $user->billing_address_1,
                'address2' => $user->billing_address_2,
                'city' => $user->billing_city,
                'state' => $user->billing_state,
                'postal_code' => $user->billing_postcode,
                'private_notes' => 'Synced from WordPress (' . site_url() . ') on ' . date('j F Y H:i'),
            ];

            $client = ClientApi::create( $data );
        }

        $client = json_decode( $client );
        $client_id = $client->id;

        $invoice = [
            'client_id' => $client_id,
            'line_items' => [],
        ];

        foreach ($cart as $product_id => $quantity) {

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

        $response = self::sendRequest( 'invoices?mark_sent=true', 'POST', $invoice );

        if ( $response ) {
            $response = json_encode( json_decode( $response )->data );
        }

        return $response;
    }
}