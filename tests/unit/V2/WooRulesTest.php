<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Tests\TestCase;
use InvoiceNinja\WooCommerce\Backfill;
use InvoiceNinja\WooCommerce\IdMaps;
use InvoiceNinja\WooCommerce\OrderMeta;
use InvoiceNinja\WooCommerce\OrderToInvoice;
use InvoiceNinja\WooCommerce\Payments;
use InvoiceNinja\WooCommerce\WooCustomerSync;

class WooRulesTest extends TestCase
{
    private const PAID = [ 'processing', 'completed' ];

    public function testCardPaymentsCountWhenAnUnpaidOrderBecomesPaid()
    {
        $this->assertTrue( Payments::becamePaid( 'pending', 'processing', 'stripe', self::PAID ) );
        $this->assertTrue( Payments::becamePaid( 'on-hold', 'processing', 'bacs', self::PAID ) );
        $this->assertTrue( Payments::becamePaid( 'on-hold', 'completed', 'cheque', self::PAID ) );
        $this->assertFalse( Payments::becamePaid( 'processing', 'completed', 'stripe', self::PAID ), 'already paid' );
        $this->assertFalse( Payments::becamePaid( 'pending', 'on-hold', 'bacs', self::PAID ) );
        $this->assertFalse( Payments::becamePaid( 'processing', 'refunded', 'stripe', self::PAID ) );
    }

    public function testCashOnDeliveryIsPaidOnlyWhenCompleted()
    {
        $this->assertFalse( Payments::becamePaid( 'pending', 'processing', 'cod', self::PAID ), 'processing is not paid for cash on delivery' );
        $this->assertTrue( Payments::becamePaid( 'processing', 'completed', 'cod', self::PAID ) );
        $this->assertTrue( Payments::becamePaid( 'pending', 'completed', 'cod', self::PAID ) );
        $this->assertFalse( Payments::becamePaid( 'completed', 'completed', 'cod', self::PAID ) );
    }

    public function testIdempotencyKeyFitsInvoiceNinja()
    {
        $key = Payments::key( 'K7', 123456789 );
        $this->assertSame( 'wp_K7_123456789', $key );
        $this->assertLessThanOrEqual( 64, strlen( $key ) );
    }

    public function testAReplacedInvoiceGetsANewPaymentKey()
    {
        $this->assertSame( 'wp_K7_12', Payments::key( 'K7', 12, 0 ), 'the first payment keeps the original key' );
        $this->assertSame( 'wp_K7_12_r1', Payments::key( 'K7', 12, 1 ) );
        $this->assertLessThanOrEqual( 64, strlen( Payments::key( 'ABCDEFGHIJ', PHP_INT_MAX, 999 ) ) );
    }

    public function testStaticsBecomeCodeMaps()
    {
        $maps = IdMaps::fromStatics( [
            'currencies' => [ [ 'id' => 1, 'code' => 'usd', 'precision' => '2' ], [ 'id' => '3', 'code' => 'EUR' ], [ 'id' => 89, 'code' => 'KWD', 'precision' => 3 ], [ 'code' => 'XXX' ] ],
            'countries'  => [ [ 'id' => 840, 'iso_3166_2' => 'US' ], [ 'id' => '276', 'iso_3166_2' => 'de' ], 'junk' ],
        ] );
        $this->assertSame( [ 'USD' => '1', 'EUR' => '3', 'KWD' => '89' ], $maps['currencies'] );
        $this->assertSame( [ 'USD' => 2, 'KWD' => 3 ], $maps['precisions'] );
        $this->assertSame( [ 'US' => '840', 'DE' => '276' ], $maps['countries'] );
        $this->assertGreaterThan( 0, $maps['at'] );
    }

    public function testPaymentsNeverExceedTheBalanceInThreeDecimalCurrencies()
    {
        $this->assertSame( 10.125, Payments::amount( 10.125, 10.125, 3 ), '10.125 KWD is not rounded up to 10.13' );
        $this->assertSame( 10.13, Payments::amount( 10.125, 10.13, 2 ) );
        $this->assertSame( 5.0, Payments::amount( 10.0, 5.0, 2 ), 'never more than the balance' );
        $this->assertSame( 1000.5, Payments::amount( 1000.5, 1000.5, 0 ), 'rounding up past the limit falls back to the limit' );
    }

    public function testARefundLandedOnlyWhenTheRefundedTotalMovedByItsAmount()
    {
        $this->assertTrue( Payments::refundLanded( 5.0, 0.0, 5.0 ) );
        $this->assertTrue( Payments::refundLanded( 12.5, 2.5, 10.0 ), 'an earlier refund in the baseline' );
        $this->assertFalse( Payments::refundLanded( 2.5, 2.5, 10.0 ), 'not there yet' );
        $this->assertFalse( Payments::refundLanded( 10.124, 0.0, 10.125, 3 ) );
        $this->assertTrue( Payments::refundLanded( 0.30000000000000004, 0.1, 0.2 ), 'float noise' );
    }

    public function testWaitingForAnInvoiceStops()
    {
        $this->assertSame( 120, Payments::waitFor( 0 ) );
        $this->assertSame( 240, Payments::waitFor( 1 ) );
        $this->assertSame( 3600, Payments::waitFor( 6 ) );
        $total = 0;
        for ( $waits = 0; null !== Payments::waitFor( $waits ); $waits++ ) {
            $total += Payments::waitFor( $waits );
            $this->assertLessThan( 100, $waits, 'it stops' );
        }
        $this->assertGreaterThan( 6 * 3600, $total );
        $this->assertLessThan( 12 * 3600, $total );
    }

    public function testOnlyAVerifiedCustomerWhoIsTheClientMayChangeIt()
    {
        $this->assertTrue( WooCustomerSync::mayUpdate( '', 'CL1', 'jane@example.com', 'jane@example.com', true ) );
        $this->assertTrue( WooCustomerSync::mayUpdate( 'CL1', 'CL1', 'jane@example.com', 'billing@acme.test', true ), 'linked to that client' );
        $this->assertFalse( WooCustomerSync::mayUpdate( '', 'CL1', 'jane@example.com', 'jane@example.com', false ), 'unverified' );
        $this->assertFalse( WooCustomerSync::mayUpdate( '', 'CL9', 'mallory@example.com', 'victim@corp.test', true ), 'a billing email typed in proves nothing' );
        $this->assertFalse( WooCustomerSync::mayUpdate( 'CL1', 'CL9', 'jane@example.com', 'victim@corp.test', true ), 'linked to another client' );
        $this->assertFalse( WooCustomerSync::mayUpdate( 'CL1', 'CL1', 'jane@example.com', 'billing@acme.test', false ), 'linked, but not verified' );
    }

    public function testTheInvitationLinkIsNeverHandedOutByTheRestApi()
    {
        $rows = OrderMeta::withoutPrivate( [
            [ 'id' => 1, 'key' => OrderMeta::LINK, 'value' => 'https://in.test/client/invoice/SECRET' ],
            [ 'id' => 2, 'key' => OrderMeta::INVOICE_NUMBER, 'value' => '0001' ],
            (object) [ 'id' => 3, 'key' => OrderMeta::LINK, 'value' => 'https://in.test/client/invoice/SECRET' ],
        ] );
        $this->assertCount( 1, $rows );
        $this->assertSame( OrderMeta::INVOICE_NUMBER, $rows[0]['key'] );
    }

    public function testOnlyTheBuyersOwnInvitationIsKept()
    {
        $invitations = [
            [ 'client_contact_id' => 'OTHER', 'link' => 'https://in.test/client/invoice/OTHER' ],
            [ 'client_contact_id' => 'C1', 'link' => 'https://in.test/client/invoice/MINE' ],
        ];
        $this->assertSame( 'https://in.test/client/invoice/MINE', OrderToInvoice::buyerLink( $invitations, 'C1' ) );
        $this->assertSame( '', OrderToInvoice::buyerLink( $invitations, '' ), 'no contact, no link' );
        $this->assertSame( '', OrderToInvoice::buyerLink( $invitations, 'C2' ) );
    }

    public function testBackfillDates()
    {
        $this->assertTrue( Backfill::validDate( '2026-02-28' ) );
        $this->assertFalse( Backfill::validDate( '2026-02-30' ) );
        $this->assertFalse( Backfill::validDate( '26-02-01' ) );
        $this->assertFalse( Backfill::validDate( '' ) );
        $this->assertFalse( Backfill::validDate( '2026-02-01; DROP' ) );
    }

    public function testRolesMatch()
    {
        $this->assertTrue( WooCustomerSync::roleMatches( [ 'subscriber', 'customer' ], [ 'customer' ] ) );
        $this->assertFalse( WooCustomerSync::roleMatches( [ 'administrator' ], [ 'customer' ] ) );
        $this->assertFalse( WooCustomerSync::roleMatches( [], [ 'customer' ] ) );
    }

    public function testUpdatingAClientKeepsEveryContact()
    {
        $existing = [
            'id'       => 'CL1',
            'name'     => 'Acme',
            'contacts' => [
                [ 'id' => 'C1', 'first_name' => 'Old', 'last_name' => 'Name', 'email' => 'Jane@Example.com', 'phone' => '1', 'contact_key' => 'SECRET' ],
                [ 'id' => 'C2', 'first_name' => 'Bob', 'last_name' => 'B', 'email' => 'bob@example.com', 'phone' => '2' ],
            ],
        ];
        $body = WooCustomerSync::updateBody( $existing, 'jane@example.com', [ 'first_name' => 'Jane', 'last_name' => '', 'phone' => '555', 'address1' => '1 Main St', 'city' => '', 'postal_code' => '10001' ] );
        $this->assertSame( '1 Main St', $body['address1'] );
        $this->assertSame( '10001', $body['postal_code'] );
        $this->assertArrayNotHasKey( 'city', $body, 'empty values never blank a field' );
        $this->assertArrayNotHasKey( 'name', $body, 'the client name stays as the business keeps it' );
        $this->assertCount( 2, $body['contacts'] );
        $this->assertSame( [ 'id' => 'C1', 'first_name' => 'Jane', 'last_name' => 'Name', 'email' => 'Jane@Example.com', 'phone' => '555' ], $body['contacts'][0] );
        $this->assertSame( 'Bob', $body['contacts'][1]['first_name'] );
        $this->assertArrayNotHasKey( 'contact_key', $body['contacts'][0] );
    }
}
