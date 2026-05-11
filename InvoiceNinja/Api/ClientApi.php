<?php

/**
 * @package Invoice Ninja
 */

namespace InvoiceNinja\Api;

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

        $count += self::exportGuestOrders();

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

        // Defer to the order-triggered path when WC hasn't populated billing meta yet —
        // typically the case when user_register fires during account-at-checkout, before
        // WC's update_user_meta() copies the order's billing data onto the user.
        if ( class_exists( 'WooCommerce' )
            && empty( $user->billing_first_name )
            && empty( $user->billing_last_name )
            && empty( $user->billing_address_1 ) ) {
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

    public static function exportGuestOrders()
    {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return 0;
        }

        $date_after = gmdate( 'Y-m-d', strtotime( '-6 months' ) );
        $date_after = apply_filters( 'invoiceninja_guest_export_date_after', $date_after );

        $orders = wc_get_orders( [
            'limit'       => -1,
            'customer_id' => 0,
            'date_after'  => $date_after,
            'return'      => 'objects',
        ] );

        $seen  = [];
        $count = 0;
        foreach ( $orders as $order ) {
            $email = strtolower( trim( (string) $order->get_billing_email() ) );
            if ( $email === '' || isset( $seen[ $email ] ) ) {
                continue;
            }
            $seen[ $email ] = true;
            if ( self::exportOrder( $order ) ) {
                $count++;
            }
        }

        return $count;
    }

    public static function exportOrder( $order )
    {
        $email = strtolower( trim( (string) $order->get_billing_email() ) );
        if ( $email === '' ) {
            return false;
        }

        if ( $client = self::find( $email ) ) {
            if ( get_option( 'invoiceninja_match_found' ) === 'update' ) {
                return self::updateFromOrder( $client, $order );
            }
            return $client;
        }

        return self::createFromOrder( $order );
    }

    public static function exportUserFromOrder( $user, $order )
    {
        $email = strtolower( trim( (string) ( $order->get_billing_email() ?: $user->user_email ) ) );
        if ( $email === '' ) {
            return false;
        }

        if ( $client = self::find( $email ) ) {
            if ( get_option( 'invoiceninja_match_found' ) === 'update' ) {
                return self::updateFromOrder( $client, $order, $user );
            }
            return $client;
        }

        return self::createFromOrder( $order, $user );
    }

    public static function convertOrder( $order, $user = null )
    {
        $email = $order->get_billing_email() ?: ( $user ? $user->user_email : '' );
        $first = $order->get_billing_first_name() ?: ( $user ? $user->first_name : '' );
        $last  = $order->get_billing_last_name()  ?: ( $user ? $user->last_name  : '' );
        $phone = $order->get_billing_phone() ?: ( $user ? $user->billing_phone : '' );
        $name  = trim( $first . ' ' . $last );
        if ( ! $name && $user ) {
            $name = $user->nickname;
        }

        return [
            'contacts' => array(
                array(
                    'first_name' => $first,
                    'last_name'  => $last,
                    'email'      => $email,
                    'phone'      => $phone,
                ),
            ),
            'name'          => $name,
            'address1'      => $order->get_billing_address_1(),
            'address2'      => $order->get_billing_address_2(),
            'city'          => $order->get_billing_city(),
            'state'         => $order->get_billing_state(),
            'postal_code'   => $order->get_billing_postcode(),
            'private_notes' => 'Synced from WordPress (' . site_url() . ') / WooCommerce order #' . $order->get_id() . ' on ' . gmdate( 'j F Y H:i' ),
        ];
    }

    public static function createFromOrder( $order, $user = null )
    {
        $data = self::convertOrder( $order, $user );
        $response = self::sendRequest( 'clients', 'POST', $data );

        if ( $response ) {
            $decoded = json_decode( $response );
            return $decoded->data ?? null;
        }

        return null;
    }

    public static function updateFromOrder( $client, $order, $user = null )
    {
        $data = self::convertOrder( $order, $user );

        $order_email = strtolower( trim( (string) ( $order->get_billing_email() ?: ( $user ? $user->user_email : '' ) ) ) );
        foreach ( ( $client->contacts ?? [] ) as $contact ) {
            if ( isset( $contact->email ) && strtolower( trim( (string) $contact->email ) ) === $order_email ) {
                $data['contacts'][0]['id'] = $contact->id;
            } else {
                $data['contacts'][] = (array) $contact;
            }
        }

        $response = self::sendRequest( 'clients/' . $client->id, 'PUT', $data );

        if ( $response ) {
            $decoded = json_decode( $response );
            return $decoded->data ?? null;
        }

        return null;
    }
}