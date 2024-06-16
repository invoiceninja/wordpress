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

        if ( ! $response = self::sendRequest( $route ) ) {
            return false;
        }

        if ( $response ) {
            $response = json_decode( $response )->data;
        }

        if (self::isUsingToken()) {
            $response = $response[0];
        }

        //echo $response; exit;

        if ( $logo_url = $response->settings->company_logo ) {
            if ( $image_data = @file_get_contents( $logo_url ) ) {
                $args = array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    's'              => 'invoiceninja_plugin',
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

        if ( $static_response = self::sendRequest( 'statics' ) ) {
            $static_response = json_decode( $static_response );

            $currency_map = [];
            foreach ( $static_response->currencies as $currency ) {
                $currency_map[$currency->id] = $currency;
            }
            update_option( 'invoiceninja_currencies', json_encode( $currency_map) );

            $country_map = [];
            foreach ( $static_response->countries as $country ) {
                $obj = new \stdClass;
                $obj->swap_currency_symbol = $country->swap_currency_symbol;
                $obj->thousand_separator = $country->thousand_separator;
                $obj->decimal_separator = $country->decimal_separator;
                $country_map[$country->id] = $obj;
            }
            update_option( 'invoiceninja_countries', json_encode( $country_map) );
        }

        return $response;
    }
}