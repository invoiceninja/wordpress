<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Commerce\Checkout;
use InvoiceNinja\Commerce\OrderService;
use InvoiceNinja\Core\Logger;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Customers\ClientResolver;
use InvoiceNinja\Plugin;
use InvoiceNinja\Tests\TestCase;

/**
 * The optional delivery address, and what Order Status shows from the invoice.
 */
class DeliveryAddressTest extends TestCase
{
    private function address( array $override = [] ): array
    {
        return $override + [
            'shipping_address1'    => '22 Elm St',
            'shipping_address2'    => '',
            'shipping_city'        => 'Portland',
            'shipping_state'       => 'OR',
            'shipping_postal_code' => '97201',
            'shipping_country_id'  => '840',
        ];
    }

    public function testTheDeliveryAddressIsOffByDefault()
    {
        $this->assertFalse( Options::SETTING_DEFAULTS['store']['delivery_address'] );
    }

    public function testAStreetTownAndCountryAreNeeded()
    {
        $countries = [ '840', '276' ];
        $this->assertSame( [], Checkout::deliveryAddressErrors( $this->address(), $countries ) );
        $this->assertSame( [], Checkout::deliveryAddressErrors( $this->address( [ 'shipping_state' => '', 'shipping_postal_code' => '' ] ), $countries ), 'region and postcode are optional' );
        $this->assertSame( [ 'shipping_address1', 'shipping_city', 'shipping_country_id' ], array_keys( Checkout::deliveryAddressErrors( [], $countries ) ) );
        $this->assertSame( [ 'shipping_country_id' ], array_keys( Checkout::deliveryAddressErrors( $this->address( [ 'shipping_country_id' => '999' ] ), $countries ) ), "only Invoice Ninja's countries" );
        $this->assertSame( [ 'shipping_postal_code' ], array_keys( Checkout::deliveryAddressErrors( $this->address( [ 'shipping_postal_code' => '<b>' ] ), $countries ) ) );
        $this->assertSame( [ 'shipping_address2' ], array_keys( Checkout::deliveryAddressErrors( $this->address( [ 'shipping_address2' => str_repeat( 'x', 101 ) ] ), $countries ) ) );
        // Before the country list has been fetched, any country id (or none) is let through.
        $this->assertSame( [], Checkout::deliveryAddressErrors( $this->address( [ 'shipping_country_id' => '' ] ), [] ) );
        $this->assertSame( [ 'shipping_country_id' ], array_keys( Checkout::deliveryAddressErrors( $this->address( [ 'shipping_country_id' => 'US' ] ), [] ) ) );
    }

    public function testOnlyTheShippingFieldsAreTakenAndTrimmed()
    {
        $address = Checkout::deliveryAddress( [ 'shipping_address1' => ' 22 Elm St ', 'shipping_city' => [ 'x' ], 'email' => 'a@b.c', 'address1' => 'Billing' ] );
        $this->assertSame( Checkout::DELIVERY_FIELDS, array_keys( $address ) );
        $this->assertSame( '22 Elm St', $address['shipping_address1'] );
        $this->assertSame( '', $address['shipping_city'], 'not a string: dropped' );
    }

    public function testTheAddressReadsAsLines()
    {
        $this->assertSame( [ '22 Elm St', 'Portland, OR 97201', 'United States' ], Checkout::addressLines( $this->address(), [ '840' => 'United States' ] ) );
        $this->assertSame( [ '1 Main Rd', 'Flat 2', 'Leeds' ], Checkout::addressLines( [ 'shipping_address1' => '1 Main Rd', 'shipping_address2' => 'Flat 2', 'shipping_city' => 'Leeds' ], [] ) );
        $this->assertSame( [], Checkout::addressLines( [], [] ), 'no address asked for: nothing to show' );
    }

    public function testTheBreakdownIsTheInvoicesOwnFigures()
    {
        $invoice   = [
            'amount'       => 121.52,
            'balance'      => 121.52,
            'paid_to_date' => 0,
            'total_taxes'  => 9.52,
            'line_items'   => [
                [ 'product_key' => 'Teapot', 'quantity' => 1, 'cost' => 64, 'line_total' => 64 ],
                [ 'product_key' => 'Speckled mug', 'quantity' => 2, 'cost' => 24, 'line_total' => 48 ],
            ],
        ];
        $breakdown = OrderService::breakdown( $invoice );
        $this->assertSame( 112.0, $breakdown['subtotal'] );
        $this->assertSame( 9.52, $breakdown['tax'] );
        $this->assertSame( 121.52, $breakdown['total'] );
        $this->assertSame( 0.0, $breakdown['adjustments'] );
        $this->assertSame( [ 'title' => 'Speckled mug', 'quantity' => 2.0, 'line_total' => 48.0 ], $breakdown['lines'][1] );

        // A discount Invoice Ninja applied shows as its own row, so the rows add up to the total.
        $discounted = OrderService::breakdown( [ 'amount' => 100.0, 'total_taxes' => 0 ] + $invoice );
        $this->assertSame( -12.0, $discounted['adjustments'] );

        // Prices that include tax: the tax is inside the lines.
        $inclusive = OrderService::breakdown( [ 'uses_inclusive_taxes' => true, 'amount' => 112, 'total_taxes' => 10.18, 'balance' => 112 ] + $invoice );
        $this->assertTrue( $inclusive['inclusive'] );
        $this->assertSame( 0.0, $inclusive['adjustments'] );

        // Part paid: what's left is the balance.
        $part = OrderService::breakdown( [ 'paid_to_date' => 21.52, 'balance' => 100 ] + $invoice );
        $this->assertSame( 21.52, $part['paid'] );
        $this->assertSame( 100.0, $part['balance'] );

        // No line_total from Invoice Ninja: cost times quantity.
        $this->assertSame( 48.0, OrderService::breakdown( [ 'line_items' => [ [ 'product_key' => 'Mug', 'quantity' => 2, 'cost' => 24 ] ] ] )['lines'][0]['line_total'] );
    }

    public function testThePdfUsesTheBuyersOwnInvitation()
    {
        $this->assertSame( 'INVITE7', Checkout::invitationKey( 'https://in.test/client/invoice/INVITE7' ) );
        $this->assertSame( 'abc123XYZ', Checkout::invitationKey( 'https://billing.example.com/client/invoice/abc123XYZ/' ) );
        $this->assertSame( '', Checkout::invitationKey( 'https://in.test/client/quote/Q1' ) );
        $this->assertSame( '', Checkout::invitationKey( 'https://in.test/client/invoice/../../x' ) );
        $this->assertSame( '', Checkout::invitationKey( '' ) );
    }

    public function testAnExistingClientGetsOnlyItsShippingAddressChanged()
    {
        global $wpdb;
        $wpdb    = new class {
            public $prefix = 'wp_';
            public function insert() { return 1; }
        };
        $plugin  = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
        $options = new Options();
        ( function () use ( $options ) {
            $this->options = $options;
            $this->logger  = new Logger( $options );
        } )->call( $plugin );
        $this->respondWith( [ 'data' => [ 'id' => 'CLIENT1' ] ] );

        ( new ClientResolver( $plugin ) )->updateShipping( new HttpClient( 'https://in.test', 'secret-token-value' ), 'CLIENT1', $this->address() );

        $this->assertCount( 1, $this->requests );
        $this->assertSame( 'PUT', $this->requests[0]['args']['method'] );
        $this->assertStringEndsWith( '/api/v1/clients/CLIENT1', $this->requests[0]['url'] );
        $body = json_decode( $this->requests[0]['args']['body'], true );
        $this->assertSame( Checkout::DELIVERY_FIELDS, array_keys( $body ), 'no contacts, settings or billing address' );
        $this->assertSame( '840', $body['shipping_country_id'] );

        $this->requests = [];
        ( new ClientResolver( $plugin ) )->updateShipping( new HttpClient( 'https://in.test', 'secret-token-value' ), 'CLIENT1', $this->address( [ 'shipping_country_id' => '' ] ) );
        $this->assertArrayNotHasKey( 'shipping_country_id', json_decode( $this->requests[0]['args']['body'], true ), 'a blank country id is left alone, not sent as ""' );

        // Invoice Ninja fills an update without a currency with the company's: the client's
        // own currency goes back unchanged, so it isn't pinned to the company's.
        $this->requests = [];
        ( new ClientResolver( $plugin ) )->updateShipping( new HttpClient( 'https://in.test', 'secret-token-value' ), 'CLIENT1', $this->address(), '3' );
        $this->assertSame( [ 'currency_id' => '3' ], json_decode( $this->requests[0]['args']['body'], true )['settings'] );
    }
}
