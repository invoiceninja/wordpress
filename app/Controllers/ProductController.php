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
                'id' => 'invoiceninja_product',
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
        $args = [
            'post_type' => 'invoiceninja_product',
            'posts_per_page' => -1,
        ];
        
        $query = new \WP_Query( $args );
        
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();
                wp_delete_post( $post_id, true );
            }
            wp_reset_postdata();
        }

        $products = ProductApi::load();
        $products = json_decode( $products );

        foreach ($products as $product) 
        {             
            $post_data = array(
                'post_title' => $product->product_key,
                'post_content' => $product->notes,
                'post_status' => 'publish', // publish, draft, pending, private, trash
                'post_type' => 'invoiceninja_product',
                'meta_input' => [
                    'id' => $product->id,
                    'price' => $product->price,                    
                ],
            );

            $post_id = wp_insert_post($post_data);
 

            if ( $product->product_image && $post_id && ! is_wp_error( $post_id ) ) 
            {
                $file_extension = pathinfo( $product->product_image, PATHINFO_EXTENSION );
                $allowed_mime_types = wp_get_mime_types();
                $post_mime_type = isset( $allowed_mime_types[ $file_extension ] ) ? $allowed_mime_types[ $file_extension ] : 'image/jpeg';
            
                $image_data = file_get_contents( $product->product_image );
                $filename = $product->id . '.' . $file_extension;

                $upload = wp_upload_bits( $filename, null, $image_data );

                if ( ! $upload['error'] ) {
                    $attachment_id = wp_insert_attachment( array(
                        'post_mime_type' => $post_mime_type,
                        'post_title' => sanitize_file_name( $filename ),
                        'post_content' => '',
                        'post_status' => 'inherit'
                    ), $upload['file'] );

                    set_post_thumbnail( $post_id, $attachment_id );
                }
            }

            /*
            $args = [
                'post_type'  => 'invoiceninja_product',
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
            */
        }        
    }
}