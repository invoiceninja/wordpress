<?php

namespace InvoiceNinja\Tests\Api;

use InvoiceNinja\Api\ClientApi;
use InvoiceNinja\Tests\TestCase;

class ClientApiTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        $this->options['invoiceninja_api_token'] = 'secret-token';
    }

    /**
     * An empty email filter makes the API return every client, which once attached
     * guest invoices to an arbitrary client. It must never be sent.
     *
     * @dataProvider emptyEmailProvider
     */
    public function testEmptyEmailNeverQueriesTheApi( $email )
    {
        $this->assertNull( ClientApi::find( $email ) );
        $this->assertSame( [], $this->requests );
    }

    public function emptyEmailProvider()
    {
        return [ 'empty' => [ '' ], 'spaces' => [ '   ' ], 'null' => [ null ] ];
    }

    /**
     * Unencoded '+' turned into a space, so the client was never found and a
     * duplicate was created on every sync (#11).
     */
    public function testEmailIsUrlEncoded()
    {
        $this->respondWith( [ 'data' => [] ] );

        ClientApi::find( 'a+b@x.com' );

        $this->assertSame( [ 'https://invoicing.co/api/v1/clients?email=a%2Bb%40x.com' ], $this->requestedUrls() );
    }

    public function testEmailCannotInjectQueryParameters()
    {
        $this->respondWith( [ 'data' => [] ] );

        ClientApi::find( 'x@y.com&per_page=5000' );

        $this->assertStringEndsWith( 'clients?email=x%40y.com%26per_page%3D5000', $this->requestedUrls()[0] );
    }

    public function testReturnsFirstMatchingClient()
    {
        $this->respondWith( [ 'data' => [ [ 'id' => 'C1' ], [ 'id' => 'C2' ] ] ] );

        $this->assertSame( 'C1', ClientApi::find( 'member@x.com' )->id );
    }

    public function testReturnsNullWhenNoClientMatches()
    {
        $this->respondWith( [ 'data' => [] ] );

        $this->assertNull( ClientApi::find( 'nobody@x.com' ) );
    }

    public function testConvertsWordPressUserToClient()
    {
        $user = (object) [
            'first_name' => 'Jane', 'last_name' => 'Doe', 'user_email' => 'jane@x.com', 'billing_phone' => '555',
            'nickname' => 'jane', 'billing_address_1' => '1 Main St', 'billing_address_2' => 'Apt 2',
            'billing_city' => 'Town', 'billing_state' => 'CA', 'billing_postcode' => '90210',
        ];

        $client = ClientApi::convertUser( $user );

        $this->assertSame( 'jane@x.com', $client['contacts'][0]['email'] );
        $this->assertSame( 'Jane', $client['contacts'][0]['first_name'] );
        $this->assertSame( '1 Main St', $client['address1'] );
        $this->assertSame( '90210', $client['postal_code'] );
    }
}
