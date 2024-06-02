<?php

/**
 * @package Invoice Ninja
 */

namespace App\Controllers;

use \App\WordPress\PostApi;
use \App\InvoiceNinja\ProductApi;

class ProductController extends BaseController
{
    public function __construct()
    {
        parent::__construct();

        $this->posts = new PostApi();
    }

    public function register()
    {
        $types = [
            [
                'id' => 'product',
                'name' => 'Products',
                'singular_name' => 'Product',
            ]
        ];

        $this->posts
            ->setPostTypes($types)
            ->register();
    }

    public static function loadProducts()
    {
        $products = ProductApi::load();

        $products = json_decode( $products );

        foreach ($products->data as $product) 
        { 
            $post_data = array(
                'post_title' => $product->product_key,
                'post_content' => $product->notes,
                'post_status' => 'publish', // publish, draft, pending, private, trash
                'post_type' => 'product',
                'meta_input' => [
                    'id' => $product->id,
                    'price' => $product->price,                    
                ],
            );
 
            $args = [
                'post_type'  => 'product',
                'meta_query' => [
                    [
                        'key' => 'id',
                        'value' => $product->id,
                        'compare' => '=',
                    ],
                ],
            ];

            $query = new \WP_Query($args);

            if ($query->have_posts()) {                
                while ($query->have_posts()) {
                    $query->the_post();
                    wp_update_post($post_data);
                }                
                wp_reset_postdata();
            } else {
                $post_id = wp_insert_post($post_data);
            }                                    
        }        
    }
}