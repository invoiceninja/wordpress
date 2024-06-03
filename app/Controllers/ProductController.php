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
        $profile = json_decode( get_option( 'invoiceninja_profile' ) );

        $types = [
            [
                'id' => 'invoiceninja_product',
                'name' => $profile ? $profile->settings->products : 'Products',
                'singular_name' => $profile ? $profile->settings->product : 'Product',
            ]
        ];

        $this->posts
            ->setPostTypes($types)
            ->register();
    }

    public static function loadProducts()
    {
        // Disable email notifications for new posts
        function disable_post_email_notifications() {
            remove_action( 'publish_post', 'wp_notify_postauthor' );
        }
        add_action( 'pre_insert_post', 'disable_post_email_notifications' );

        // Re-enable email notifications after post insertion
        function enable_post_email_notifications() {
            add_action( 'publish_post', 'wp_notify_postauthor' );
        }
        add_action( 'wp_insert_post', 'enable_post_email_notifications' );
        
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
                    'quantity' => $product->quantity,
                    'max_quantity' => $product->max_quantity,
                    'in_stock_quantity' => $product->in_stock_quantity,
                    'custom_value1' => $product->custom_value1,
                    'custom_value2' => $product->custom_value2,
                    'custom_value3' => $product->custom_value3,
                    'custom_value4' => $product->custom_value4,
                ],
            );

            $post_id = wp_insert_post($post_data); 

            if ( $product->product_image && $post_id && ! is_wp_error( $post_id ) ) 
            {
                $file_extension = pathinfo( $product->product_image, PATHINFO_EXTENSION );
                $allowed_mime_types = wp_get_mime_types();
                $post_mime_type = isset( $allowed_mime_types[ $file_extension ] ) ? $allowed_mime_types[ $file_extension ] : 'image/jpeg';            
                $filename = $product->id . '.' . $file_extension;

                // Delete the old image if it exists 
                $args = array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'     => '_wp_attached_file',
                            'value'   => $filename,
                            'compare' => 'LIKE',
                        ),
                    ),
                );

                $attachments = get_posts( $args );
                if ( $attachments ) {
                    $attachment_id = $attachments[0];                    
                    $result = wp_delete_attachment( $attachment_id, true );
                }

                $image_data = file_get_contents( $product->product_image );
                $upload = wp_upload_bits( $filename, null, $image_data );

                if ( ! $upload['error'] ) {
                    $attachment_id = wp_insert_attachment( 
                        [
                            'post_mime_type' => $post_mime_type,
                            'post_title' => sanitize_file_name( $filename ),
                            'post_content' => $product->product_key,
                            'post_status' => 'inherit'
                        ], 
                        $upload['file']
                    );

                    set_post_thumbnail( $post_id, $attachment_id );
                }
            }
        }     

        $profile = json_decode( get_option( 'invoiceninja_profile' ) );
        $page = '<div class="wp-block-query alignwide is-layout-flow wp-block-query-is-layout-flow">';
        
        $count = 0;
        $args = [
            'post_type' => 'invoiceninja_product',
            'posts_per_page' => -1,
            'order_by' => 'title', // date, menu_order
            'order' => 'ASC',
        ];
        
        $query = new \WP_Query( $args );
        
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();
                $price = get_post_meta( $post_id, 'price', true );

                if ($count % 3 == 0) {
                    $page .= '<div class="wp-block-columns" style="padding:0px; margin:0px;">';
                }

                $template = INVOICENINJA_DEFAULT_PRODUCT_TEMPLATE;

                $image = '';

                if ( has_post_thumbnail( $post_id ) ) {
                    $image_url = get_the_post_thumbnail_url( $post_id, 'medium' );
                    $image = str_replace( '$image_url', $image_url, INVOICENINJA_DEFAULT_PRODUCT_IMAGE_TEMPLATE );
                }

                $template = str_replace( [
                    '$post_url',
                    '$title',
                    '$content',
                    '$price',
                    '$image',
                ], [
                    get_permalink(),
                    get_the_title(),
                    get_the_content(),
                    $price,
                    $image,
                ], $template );

                $page .= $template;

                if ($count % 3 == 2) {
                    $page .= '</div>';
                }
    
                $count++;
    
            }
            wp_reset_postdata();
        }

        while ($count % 3 != 0) {
            $page .= '<div class="wp-block-column" style="margin-left:16px;margin-right:16px;"></div>';
            $count++;
        }        

        $page_data = array(
            'ID' => get_option('invoiceninja_product_page_id'),
            'post_title' => $profile ? $profile->settings->products : 'Products',
            'post_content' => $page,
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => 'page',
        );

        $page_id = wp_insert_post( $page_data );

        if ( ! is_wp_error( $page_id ) ) {
            update_option('invoiceninja_product_page_id', $page_id);
        }
    }
}