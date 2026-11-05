<?php

namespace InvoiceNinja\Tests\V2;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use InvoiceNinja\Catalog\Statics;
use InvoiceNinja\Commerce\Cart;
use InvoiceNinja\Commerce\Checkout;
use InvoiceNinja\Commerce\OrderService;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Queue;
use InvoiceNinja\Core\Secrets;
use InvoiceNinja\Sync\Mapping;
use InvoiceNinja\Tests\TestCase;

class CheckoutRulesTest extends TestCase
{
    public function testTaxIsAddedOnTopOrBackedOutLikeInvoiceNinja()
    {
        // Exclusive: each rate rounded on its own, added to the line
        $this->assertSame( 2.0, Cart::taxFor( 20.0, [ 10.0, 0.0, 0.0 ], false ) );
        $this->assertSame( 0.34, Cart::taxFor( 3.33, [ 5.0, 5.0, 0.0 ], false ), '0.17 + 0.17, not round(0.333)' );
        // Inclusive: the combined rate is factored out once, each tax rounded on its own
        $this->assertSame( 1.82, Cart::taxFor( 20.0, [ 10.0, 0.0, 0.0 ], true ) );
        $this->assertSame( 2.0 + 1.0, Cart::taxFor( 23.0, [ 10.0, 5.0, 0.0 ], true ) );
        $this->assertSame( 0.0, Cart::taxFor( 20.0, [ 0.0, 0.0, 0.0 ], true ) );
    }

    public function testInclusiveTaxesFollowTheCompanySetting()
    {
        $cart = new Cart( new Secrets( new Options() ), new Mapping() );
        $this->assertFalse( $cart->inclusiveTaxes() );
        $this->options['invoiceninja_connection'] = [ 'company' => [ 'inclusive_taxes' => true ] ];
        $this->assertTrue( $cart->inclusiveTaxes() );
    }

    public function testTheInvoiceIsToldWhetherPricesIncludeTax()
    {
        $this->assertTrue( OrderService::inclusive( [ 'items' => [ [ 'inclusive' => true ] ] ] ) );
        $this->assertFalse( OrderService::inclusive( [ 'items' => [ [ 'inclusive' => false ] ] ] ) );
        $this->assertFalse( OrderService::inclusive( [ 'items' => [ [ 'title' => 'Saved before 2.0.1' ] ] ] ), 'older orders were exclusive' );
    }

    public function testAnExistingClientInAnotherCurrencyIsRefused()
    {
        $this->assertTrue( OrderService::currencyDiffers( [ 'settings' => [ 'currency_id' => '3' ] ], '1' ), 'EUR client, USD store' );
        $this->assertFalse( OrderService::currencyDiffers( [ 'settings' => [ 'currency_id' => '1' ] ], '1' ) );
        $this->assertFalse( OrderService::currencyDiffers( [ 'settings' => [] ], '1' ), "no currency of its own: the company's" );
        $this->assertFalse( OrderService::currencyDiffers( [], '1' ) );
        $this->assertFalse( OrderService::currencyDiffers( [ 'settings' => [ 'currency_id' => '3' ] ], '' ), 'store currency unknown' );
    }

    public function testTaxAddressIsCheckedAgainstInvoiceNinjasCountries()
    {
        $countries = [ '840', '276' ];
        $this->assertSame( [], Checkout::taxAddressErrors( '276', '10115', $countries ) );
        $this->assertSame( [], Checkout::taxAddressErrors( '840', '10001', $countries ) );
        $this->assertSame( [], Checkout::taxAddressErrors( '826', 'SW1A 1AA', [ '826' ] ) );
        $this->assertSame( [ 'country_id' ], array_keys( Checkout::taxAddressErrors( '', '', $countries ) ), 'the country is required' );
        $this->assertSame( [ 'country_id' ], array_keys( Checkout::taxAddressErrors( '999', '10115', $countries ) ) );
        $this->assertSame( [ 'postal_code' ], array_keys( Checkout::taxAddressErrors( '276', '<script>', $countries ) ) );
        $this->assertSame( [ 'postal_code' ], array_keys( Checkout::taxAddressErrors( '276', str_repeat( '1', 30 ), $countries ) ) );
    }

    public function testUsBuyersNeedAZipCodeForTheirStatesTax()
    {
        // Invoice Ninja finds a US client's state from the ZIP code; without one it taxes as California
        $countries = [ '840', '276' ];
        $this->assertSame( [ 'postal_code' ], array_keys( Checkout::taxAddressErrors( '840', '', $countries ) ), 'required for the US' );
        $this->assertSame( [ 'postal_code' ], array_keys( Checkout::taxAddressErrors( '840', '  ', $countries ) ) );
        $this->assertSame( [ 'postal_code' ], array_keys( Checkout::taxAddressErrors( '840', 'SW1A 1AA', $countries ) ), 'must look like a ZIP code' );
        $this->assertSame( [ 'postal_code' ], array_keys( Checkout::taxAddressErrors( '840', '1234', $countries ) ) );
        $this->assertSame( [], Checkout::taxAddressErrors( '840', '94103', $countries ) );
        $this->assertSame( [], Checkout::taxAddressErrors( '840', ' 94103-1234 ', $countries ), 'ZIP+4' );
        $this->assertSame( [], Checkout::taxAddressErrors( '276', '', $countries ), 'still optional elsewhere' );
        $this->assertSame( [ '840' ], Checkout::POSTCODE_REQUIRED );
    }

    public function testThePostcodeIsOptionalForCountriesWithoutOne()
    {
        $countries = [ '784', '344', '634' ]; // United Arab Emirates, Hong Kong, Qatar
        $this->assertSame( [], Checkout::taxAddressErrors( '784', '', $countries ) );
        $this->assertSame( [], Checkout::taxAddressErrors( '344', '   ', $countries ), 'blank is no postcode' );
        $this->assertSame( [], Checkout::taxAddressErrors( '634', '00000', $countries ), 'one given is still accepted' );
    }

    public function testTheSpamTrapOnlyCatchesAFilledField()
    {
        $this->assertFalse( Checkout::trapped( [] ) );
        $this->assertFalse( Checkout::trapped( [ 'trap' => '' ] ) );
        $this->assertFalse( Checkout::trapped( [ 'trap' => '  ' ] ) );
        $this->assertFalse( Checkout::trapped( [ 'website' => 'https://jane.example' ] ), 'an autofilled "website" field no longer drops a shopper' );
        $this->assertTrue( Checkout::trapped( [ 'trap' => 'http://spam.example' ] ) );
        $this->assertTrue( Checkout::trapped( [ 'trap' => [ 'x' ] ] ) );
    }

    public function testCheckoutHasItsOwnCaptchaFilter()
    {
        // A CAPTCHA hooked to the quote form (invoiceninja_captcha) never sees checkout.
        Filters\expectApplied( 'invoiceninja_captcha' )->never();
        Filters\expectApplied( 'invoiceninja_checkout_captcha' )->twice()->andReturnUsing( fn( $passed, $input ) => $input ? false : $passed );
        $this->assertFalse( Checkout::captchaPassed( [ 'email' => 'a@b.c' ] ) );
        $this->assertTrue( Checkout::captchaPassed( [] ), 'none by default' );
    }

    public function testOnlyOrdersWaitingForTheBuyerCountAgainstTheCap()
    {
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public $sql = '';
            public $args = [];
            public function prepare( $query, ...$args ) {
                $this->args = is_array( $args[0] ?? null ) ? $args[0] : $args;
                return $query;
            }
            public function get_var( $sql ) {
                $this->sql = $sql;
                return '2';
            }
        };
        $this->assertSame( 2, ( new \InvoiceNinja\Commerce\Orders() )->openFor( 'Jane@X.com' ) );
        $this->assertSame( 'jane@x.com', $wpdb->args[0] );
        $statuses = array_slice( $wpdb->args, 1, -1 );
        $this->assertSame( [ 'queued', 'placing', 'awaiting_payment' ], $statuses );
        $this->assertNotContains( 'attention', $wpdb->args, "orders held up on the shop's side don't count" );
        $this->assertNotContains( 'cancelled', $wpdb->args );
        $this->assertSame( substr_count( $wpdb->sql, '%s' ), count( $wpdb->args ), 'one placeholder per value' );
        $this->assertSame( 5, Checkout::MAX_OPEN_ORDERS );
    }

    public function testStaticsKeepCountriesAndLanguagesForNewClients()
    {
        $lists = Statics::lists( [
            'countries' => [ [ 'id' => '276', 'name' => 'Germany' ], [ 'id' => '40', 'name' => 'Austria' ], [ 'name' => 'no id' ] ],
            'languages' => [ [ 'id' => '1', 'locale' => 'en' ], [ 'id' => '23', 'locale' => 'en_GB' ], [ 'id' => '3', 'locale' => 'de' ], [ 'id' => '20', 'locale' => 'pt_BR' ] ],
        ] );
        $this->assertSame( [ '40' => 'Austria', '276' => 'Germany' ], $lists['countries'], 'sorted by name' );
        $this->assertSame( '23', Statics::matchLanguage( $lists['languages'], 'en_GB' ) );
        $this->assertSame( '1', Statics::matchLanguage( $lists['languages'], 'en_US' ), 'falls back to the language' );
        $this->assertSame( '3', Statics::matchLanguage( $lists['languages'], 'de_DE_formal' ) );
        $this->assertSame( '20', Statics::matchLanguage( $lists['languages'], 'pt-BR' ) );
        $this->assertSame( '', Statics::matchLanguage( $lists['languages'], 'ja' ) );
        $this->assertSame( '', Statics::matchLanguage( $lists['languages'], '' ) );
    }

    public function testDeactivationParksOneOffJobsAndReactivationRestoresThem()
    {
        $recurring = new class {
            public function is_recurring() { return true; }
            public function get_date() { return null; }
        };
        $single = new class {
            public function is_recurring() { return false; }
            public function get_date() { return new \DateTime( '@2000000000' ); }
        };
        $action = static fn( $hook, $args, $schedule ) => new class( $hook, $args, $schedule ) {
            public function __construct( private $hook, private $args, private $schedule ) {}
            public function get_hook() { return $this->hook; }
            public function get_args() { return $this->args; }
            public function get_schedule() { return $this->schedule; }
        };
        $order = [ [ 'payload' => [ 'order_id' => 7 ], 'attempt' => 2 ] ];
        Functions\when( 'as_get_scheduled_actions' )->justReturn( [
            $action( 'invoiceninja_daily', [], $recurring ),
            $action( 'invoiceninja_place_order', $order, $single ),
        ] );
        $cleared = 0;
        Functions\when( 'as_unschedule_all_actions' )->alias( function () use ( &$cleared ) {
            $cleared++;
        } );
        Queue::park();
        $this->assertSame( 1, $cleared, 'every job is removed, so no other plugin runs them without a handler' );
        $this->assertCount( 1, $this->options[ Queue::PARKED ], 'only the one-off job is kept: recurring ones come back on load' );

        Queue::park(); // Deactivated twice before coming back
        $this->assertCount( 1, $this->options[ Queue::PARKED ], 'no duplicates' );

        $scheduled = [];
        Functions\when( 'as_schedule_single_action' )->alias( function ( $at, $hook, $args, $group ) use ( &$scheduled ) {
            $scheduled[] = compact( 'at', 'hook', 'args', 'group' );
            return 1;
        } );
        $this->options[ Queue::PARKED ][] = [ 'hook' => 'someone_elses_hook', 'args' => [], 'at' => 0 ];
        Queue::restore();
        $this->assertSame( [ [ 'at' => 2000000000, 'hook' => 'invoiceninja_place_order', 'args' => $order, 'group' => Queue::GROUP ] ], $scheduled, 'put back as it was, at its time' );
        $this->assertArrayNotHasKey( Queue::PARKED, $this->options, 'restored once' );
    }

    public function testAJobIsFoundWhateverItsAttempt()
    {
        $search = null;
        Functions\when( 'as_get_scheduled_actions' )->alias( function ( $query ) use ( &$search ) {
            $search = $query['search'];
            return [ 5 ];
        } );
        $this->assertTrue( Queue::waiting( 'invoiceninja_place_order', [ 'order_id' => 12 ] ) );
        $this->assertSame( '"payload":{"order_id":12},', $search, 'matches the whole payload, so order 12 never matches 120' );
    }
}
