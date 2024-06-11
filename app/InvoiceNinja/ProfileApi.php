<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class ProfileApi extends BaseApi
{
    public static function load()
    {
        $route = self::isUsingToken() ? 'companies' : 'shop/profile';

        $response = self::sendRequest( $route );

        if (self::isUsingToken()) {
            $response = json_encode( json_decode( $response )[0] );
        }

        //echo $response; exit;

        $data = json_decode( $response );

        if ( $logo_url = $data->settings->company_logo ) {
            if ( $image_data = @file_get_contents( $logo_url ) ) {
                $args = array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'     => '_wp_attached_file',
                            'value'   => 'invoiceninja_plugin',
                            'compare' => 'LIKE',
                        ),
                    ),
                );

                $attachments = get_posts( $args );

                if ( $attachments ) {
                    $attachment_id = $attachments[0];                    
                    $result = wp_delete_attachment( $attachment_id, true );
                }

                $file_extension = pathinfo( $logo_url, PATHINFO_EXTENSION );
                $allowed_mime_types = wp_get_mime_types();
                $post_mime_type = isset( $allowed_mime_types[ $file_extension ] ) ? $allowed_mime_types[ $file_extension ] : 'image/jpeg';
                $filename = 'invoiceninja_plugin.' . $file_extension;                                                
                $upload = wp_upload_bits( $filename, null, $image_data );                

                $attachment_id = wp_insert_attachment( 
                    [
                        'post_mime_type' => $post_mime_type,
                        'post_title' => sanitize_file_name( $filename ),
                        'post_content' => '',
                        'post_status' => 'inherit'
                    ], 
                    $upload['file']
                );
    }
        }

        return $response;
    }
}