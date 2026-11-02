<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Tests\TestCase;
use InvoiceNinja\WooCommerce\Backfill;
use InvoiceNinja\WooCommerce\IdMaps;
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

    public function testStaticsBecomeCodeMaps()
    {
        $maps = IdMaps::fromStatics( [
            'currencies' => [ [ 'id' => 1, 'code' => 'usd' ], [ 'id' => '3', 'code' => 'EUR' ], [ 'code' => 'XXX' ] ],
            'countries'  => [ [ 'id' => 840, 'iso_3166_2' => 'US' ], [ 'id' => '276', 'iso_3166_2' => 'de' ], 'junk' ],
        ] );
        $this->assertSame( [ 'USD' => '1', 'EUR' => '3' ], $maps['currencies'] );
        $this->assertSame( [ 'US' => '840', 'DE' => '276' ], $maps['countries'] );
        $this->assertGreaterThan( 0, $maps['at'] );
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
