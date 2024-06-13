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

            $response = json_encode( $response[0] );
        }
        
        return $response;
    }    

    public static function convertUser( $user )
    {
        return [
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
    }

    public static function create( $user )
    {
        $data = self::convertUser( $user );
        $response = self::sendRequest( 'clients', 'POST', $data );

        if ( $response ) {
            $response = json_encode( json_decode( $response )->data );
        }

        return $response;
    }        

    public static function update( $client_id, $user )
    {
        $data = self::convertUser( $user );
        $response = self::sendRequest( 'clients/' . $client_id, 'PUT', $data );

        if ( $response ) {
            $response = json_encode( json_decode( $response )->data );
        }

        return $response;
    }        

    public static function export()
    {
        $args = [
            //'role' => '',
        ];
    
        $users = get_users($args);

        foreach ( $users as $user ) {
            if ( $client = self::find( $user->user_email ) ) {            
                $client = self::update( $client['id'], $user );
            } else {
                $client = self::create( $user );
            }
        }
    }
}