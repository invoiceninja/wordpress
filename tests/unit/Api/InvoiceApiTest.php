<?php

namespace InvoiceNinja\Tests\Api;

use Brain\Monkey\Functions;
use InvoiceNinja\Api\InvoiceApi;
use InvoiceNinja\Tests\TestCase;

class InvoiceApiTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        $this->options['invoiceninja_api_token'] = 'secret-token';
    }

    public function testLoggedOutVisitorsNeverCreateInvoices()
    {
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'wp_get_current_user' )->justReturn( (object) [ 'ID' => 0, 'user_email' => '' ] );

        $this->assertNull( InvoiceApi::create( [ 'P1' => 1 ] ) );
        $this->assertSame( [], $this->requests, 'no client lookup or invoice should be sent' );
    }

    public function testUsersWithoutEmailNeverCreateInvoices()
    {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'wp_get_current_user' )->justReturn( (object) [ 'ID' => 5, 'user_email' => '' ] );

        $this->assertNull( InvoiceApi::create( [ 'P1' => 1 ] ) );
        $this->assertSame( [], $this->requests );
    }

    public function testNoInvoiceWhenClientCannotBeFoundOrCreated()
    {
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'wp_get_current_user' )->justReturn( (object) [
            'ID' => 5, 'user_email' => 'jane@x.com', 'first_name' => 'Jane', 'last_name' => 'Doe',
            'billing_phone' => '', 'nickname' => 'jane', 'billing_address_1' => '', 'billing_address_2' => '',
            'billing_city' => '', 'billing_state' => '', 'billing_postcode' => '',
        ] );
        $this->respondWith( [ 'message' => 'Server error' ], 500 );

        $this->assertNull( InvoiceApi::create( [ 'P1' => 1 ] ) );

        foreach ( $this->requestedUrls() as $url ) {
            $this->assertStringNotContainsString( '/invoices', $url, 'must not create an invoice without a client' );
        }
    }
}
