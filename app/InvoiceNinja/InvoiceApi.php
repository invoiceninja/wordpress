<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class InvoiceApi extends BaseApi
{
    public static function create($cart)
    {
        //$invoice = new \stdClass;
        //$invoice->client_id = 'J0dNxm2aLO';

        $invoice = [
            'client_id' => 'J0dNxm2aLO',
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
                $price = get_post_meta( $post_id, 'price', true );


                $invoice['line_items'][] = [
                    'product_key' => $product,
                    'quantity' => $quantity,
                    'cost' => $price,
                    'notes' => $content,
                ];
            }
        }

        $response = self::sendRequest( 'invoices', 'POST', $invoice );

        echo $response; exit;

        return $response;
    }
}