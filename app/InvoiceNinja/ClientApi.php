<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class ClientApi extends BaseApi
{
    public static function load()
    {
        //$route = self::isUsingToken() ? 'products' : 'shop/products';

        $response = self::sendRequest( "clients?per_page=100&status=active" );

        //echo $response; exit;

        if ( $response ) {
            $response = json_encode( json_decode( $response )->data );
        }

        return $response;
    }

    public static function find($email)
    {
        $response = self::sendRequest( "clients?email=$email" );

        if ( $response ) {
            $response = json_decode( $response )->data;

            if ( empty($response) ) {
                return null;
            }

            $response = json_encode( $response );
        }
        
        return $response;
    }    

    public static function create($data)
    {
        $response = self::sendRequest( 'clients', 'POST', $data );

        if ( $response ) {
            $response = json_encode( json_decode( $response )->data );
        }

        return $response;
    }        
}