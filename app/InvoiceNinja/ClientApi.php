<?php

/**
 * @package Invoice Ninja
 */

namespace App\InvoiceNinja;

class ClientApi extends BaseApi
{
    public static function load()
    {
        $response = self::sendRequest( "clients?per_page=100&status=active" );

        if ( $response ) {
            return json_decode( $response )->data;
        }

        return null;
    }

    public static function find($email)
    {
        $response = self::sendRequest( "clients?email=$email" );

        if ( $response ) {
            $response = json_decode( $response )->data;

            if ( empty($response) ) {
                return null;
            }

            return $response[0];
        }
        
        return null;
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
            'private_notes' => 'Synced from WordPress (' . site_url() . ') on ' . gmdate('j F Y H:i'),
        ];
    }

    public static function create( $user )
    {
        $data = self::convertUser( $user );
        $response = self::sendRequest( 'clients', 'POST', $data );

        if ( $response ) {
            return json_decode( $response )->data;
        }

        return null;
    }        

    public static function update( $client, $user )
    {
        $data = self::convertUser( $user );

        $user_contact = $data['contacts'][0];

        // Match up the existing contact ids
        foreach ( $client->contacts as $contact ) {
            if ($contact->email == $user_contact['email']) {
                $data['contacts'][0]['id'] = $contact->id;
            } else {
                $data['contacts'][] = (array) $contact;
            }
        }

        $response = self::sendRequest( 'clients/' . $client->id, 'PUT', $data );

        if ( $response ) {
            return json_decode( $response )->data;
        }

        return null;
    }        

    public static function export()
    {
        $args = [
            //'role' => '',
        ];
    
        $count = 0;
        $users = get_users($args);

        foreach ( $users as $user ) {
            if ( $client = self::exportUser($user) ) {
                $count++;
            }
        }

        return $count;
    }

    public static function exportUser($user)
    {
        $matches_roles = false;
        $included_roles = get_option( 'invoiceninja_included_roles', [] );

        if ( $included_roles ) {
            foreach ( $included_roles as $role ) {
                if (in_array($role, $user->roles)) {
                    $matches_roles = true;
                }
            }
        }

        if ( ! $matches_roles) {
            return false;
        }

        if ( $client = self::find( $user->user_email ) ) {
            if ( get_option( 'invoiceninja_match_found' ) == 'update' ) {
                $client = self::update( $client, $user );
            }
        } else {
            $client = self::create( $user );
        }

        return $client;
    }
}