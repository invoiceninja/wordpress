<?php

namespace InvoiceNinja\Tests\V2;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use InvoiceNinja\Core\Logger;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Queue;
use InvoiceNinja\Core\Secrets;
use InvoiceNinja\Plugin;
use InvoiceNinja\Subscriptions\Callback;
use InvoiceNinja\Subscriptions\Configurator;
use InvoiceNinja\Subscriptions\Lifecycle;
use InvoiceNinja\Subscriptions\ProvisioningRelay;
use InvoiceNinja\Subscriptions\SubscriptionSync;
use InvoiceNinja\Tests\TestCase;
use InvoiceNinja\Webhooks\Receiver;

require_once __DIR__ . '/Support/RestStandIns.php';

/**
 * The webhook receiver and subscription callback (who is believed, what they answer) and the
 * Lifecycle's checks against Invoice Ninja's records.
 */
class InboundTrustTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public function insert() { return 1; }
        };
        Functions\when( 'wp_unslash' )->returnArg();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    }

    protected function tear_down(): void
    {
        global $wpdb;
        $wpdb = null;
        unset( $_SERVER['REMOTE_ADDR'] );
        parent::tear_down();
    }

    private function plugin(): Plugin
    {
        $plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
        ( function () {
            $this->options = new Options();
            $this->secrets = new Secrets( $this->options );
            $this->logger  = new Logger( $this->options );
            $this->queue   = new Queue( $this->logger );
        } )->call( $plugin );
        return $plugin;
    }

    private function request( string $secret, array $body = array(), array $params = array() ): \WP_REST_Request
    {
        $request = new \WP_REST_Request( 'POST', '/' );
        if ( '' !== $secret ) {
            $request->set_header( 'X-Invoiceninja-Secret', $secret );
        }
        $request->set_body( json_encode( $body ) );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        return $request;
    }

    public function testWebhookWithTheRightSecretIsNeverLockedOutByOthersWrongOnes()
    {
        $plugin   = $this->plugin();
        $receiver = new Receiver( $plugin );
        $secret   = $plugin->secrets->get( 'webhook_secret' );

        for ( $i = 1; $i <= Receiver::MAX_FAILURES; $i++ ) {
            $this->assertSame( 401, $receiver->handle( $this->request( 'wrong' ) )->get_status(), "wrong secret $i: 401" );
        }
        $locked = $receiver->handle( $this->request( 'wrong' ) );
        $this->assertSame( 429, $locked->get_status(), 'too many wrong secrets from one address: 429' );
        $this->assertGreaterThan( 0, (int) $locked->headers['Retry-After'] );

        $genuine = $receiver->handle( $this->request( $secret, array( 'id' => 'P1' ), array( 'event' => 51 ) ) );
        $this->assertSame( 202, $genuine->get_status(), 'Invoice Ninja, from the same (shared) address, still gets through' );
    }

    public function testCountersUseOneRowPerIpv6Slash64()
    {
        $this->assertSame( Receiver::bucket( '2001:db8:1:2:aaaa::1' ), Receiver::bucket( '2001:db8:1:2:ffff:1:2:3' ) );
        $this->assertNotSame( Receiver::bucket( '2001:db8:1:2::1' ), Receiver::bucket( '2001:db8:1:3::1' ) );
        $this->assertSame( 16, strlen( Receiver::bucket( '198.51.100.1' ) ) );
    }

    private function makeCallback( Plugin $plugin ): Callback
    {
        $sync = ( new \ReflectionClass( SubscriptionSync::class ) )->newInstanceWithoutConstructor();
        return new Callback( $plugin, new Configurator( $plugin, $sync ) );
    }

    public function testCallbackRepliesAlwaysCarryStatusCodeAndAreNeverA4xx()
    {
        $plugin   = $this->plugin();
        $callback = $this->makeCallback( $plugin );
        $secret   = $plugin->secrets->get( 'callback_secret' );
        $body     = array( 'body' => array( 'context' => 'is_eligible', 'subscription' => 'S1', 'client' => 'C1', 'contact' => 'K1', 'contact_email' => 'a@b.test' ) );

        $ok = $callback->handle( $this->request( $secret, $body ) );
        $this->assertSame( 200, $ok->get_status() );
        $this->assertSame( array( 'message' => 'Success', 'status_code' => 200 ), $ok->get_data(), "IN's v1 free-purchase step reads status_code" );

        $wrong = $callback->handle( $this->request( 'wrong', $body ) );
        $this->assertSame( 200, $wrong->get_status(), 'a 4xx would become status_code 500 in Invoice Ninja and block the buyer' );
        $this->assertSame( array( 'message' => 'Success', 'status_code' => 200, 'code' => 'ignored' ), $wrong->get_data() );

        for ( $i = 0; $i < 40; $i++ ) {
            $callback->handle( $this->request( 'wrong', $body ) );
        }
        $this->assertSame( array( 'message' => 'Success', 'status_code' => 200 ), $callback->handle( $this->request( $secret, $body ) )->get_data(), 'the right secret is never locked out' );
    }

    public function testARefusalCarriesTheMessageAndANon200StatusCode()
    {
        $plugin   = $this->plugin();
        $callback = $this->makeCallback( $plugin );
        Filters\expectApplied( 'invoiceninja_subscription_eligible' )->andReturn( 'Members only' );
        $reply = $callback->handle( $this->request( $plugin->secrets->get( 'callback_secret' ), array( 'body' => array( 'context' => 'is_eligible', 'subscription' => 'S1', 'client' => 'C1' ) ) ) );

        $this->assertSame( 200, $reply->get_status() );
        $this->assertSame( array( 'message' => 'Members only', 'status_code' => 403 ), $reply->get_data() );
        $this->assertSame( array( 'message' => 'Success', 'status_code' => 200 ), Callback::answer( 'Success' ) );
    }

    public function testPurchasesAndPaymentsNeedAPaidInvoice()
    {
        $sub     = array( 'id' => 'S1', 'trial_enabled' => false );
        $active  = array( 'id' => 'R1', 'client_id' => 'C1', 'subscription_id' => 'S1', 'status_id' => '2' );
        $paid    = array( 'id' => 'I1', 'client_id' => 'C1', 'subscription_id' => 'S1', 'recurring_id' => 'R1', 'status_id' => '4', 'balance' => 0 );
        $unpaid  = array( 'status_id' => '2', 'balance' => 10 ) + $paid;
        $free    = array( 'status_id' => '2', 'balance' => 0 ) + $paid;
        $deleted = array( 'is_deleted' => true ) + $paid;

        $this->assertSame( '', Lifecycle::refusal( 'single_purchase', $sub, null, $paid ) );
        $this->assertNotSame( '', Lifecycle::refusal( 'single_purchase', $sub, null, null ), 'no invoice: nothing was bought' );
        $this->assertNotSame( '', Lifecycle::refusal( 'single_purchase', $sub, null, $unpaid ) );
        $this->assertNotSame( '', Lifecycle::refusal( 'single_purchase', $sub, null, $deleted ) );
        $this->assertSame( '', Lifecycle::refusal( 'recurring_purchase', $sub, $active, $paid ) );
        $this->assertSame( '', Lifecycle::refusal( 'recurring_purchase', $sub, $active, $free ), 'nothing left to pay' );
        $this->assertNotSame( '', Lifecycle::refusal( 'recurring_purchase', $sub, $active, $unpaid ) );
        $this->assertNotSame( '', Lifecycle::refusal( 'recurring_purchase', $sub, array( 'status_id' => '3' ) + $active, $paid ), 'recurring invoice stopped' );
        $this->assertSame( '', Lifecycle::refusal( 'plan_paid', $sub, $active, $paid ) );
        $this->assertNotSame( '', Lifecycle::refusal( 'plan_paid', $sub, $active, $unpaid ), 'no resume without payment' );
        $this->assertSame( '', Lifecycle::refusal( 'change_plan', $sub, $active, null ), 'a downgrade paid by credit has no invoice' );
        $this->assertNotSame( '', Lifecycle::refusal( 'change_plan', $sub, $active, $unpaid ) );
    }

    public function testTrialsAndCancellationsFollowTheRecurringInvoice()
    {
        $active  = array( 'id' => 'R1', 'client_id' => 'C1', 'subscription_id' => 'S1', 'status_id' => '2' );
        $stopped = array( 'status_id' => '3', 'archived_at' => 1700000000 ) + $active;
        $trial   = array( 'id' => 'S1', 'trial_enabled' => true );

        $this->assertSame( '', Lifecycle::refusal( 'trial', $trial, $active, null ) );
        $this->assertSame( '', Lifecycle::refusal( 'trial', $trial, array( 'status_id' => '-1' ) + $active, null ), 'pending counts as started' );
        $this->assertNotSame( '', Lifecycle::refusal( 'trial', array( 'trial_enabled' => false ) + $trial, $active, null ), 'no trials on this plan' );
        $this->assertNotSame( '', Lifecycle::refusal( 'trial', $trial, null, null ) );
        $this->assertSame( '', Lifecycle::refusal( 'cancellation', $trial, $stopped, null ) );
        $this->assertSame( '', Lifecycle::refusal( 'cancellation', $trial, array( 'status_id' => '4' ) + $active, null ), 'completed' );
        $this->assertNotSame( '', Lifecycle::refusal( 'cancellation', $trial, $active, null ), 'still billing: not cancelled' );
        $this->assertNotSame( '', Lifecycle::refusal( 'cancellation', $trial, null, null ) );
    }

    public function testRecordsMustBelongToTheClientTheSubscriptionAndEachOther()
    {
        $ri  = array( 'id' => 'R1', 'client_id' => 'C1', 'subscription_id' => 'S1' );
        $inv = array( 'id' => 'I1', 'client_id' => 'C1', 'subscription_id' => 'S1', 'recurring_id' => 'R1' );

        $this->assertTrue( Lifecycle::belong( 'C1', 'S1', $ri, $inv ) );
        $this->assertTrue( Lifecycle::belong( 'C1', 'S1', null, array( 'recurring_id' => '' ) + $inv ) );
        $this->assertFalse( Lifecycle::belong( 'C2', 'S1', $ri, $inv ), 'another client' );
        $this->assertFalse( Lifecycle::belong( 'C1', 'S2', $ri, $inv ), 'another subscription' );
        $this->assertFalse( Lifecycle::belong( 'C1', 'S1', array( 'subscription_id' => '' ) + $ri, $inv ), 'a recurring invoice outside any subscription' );
        $this->assertFalse( Lifecycle::belong( 'C1', 'S1', $ri, array( 'recurring_id' => 'R9' ) + $inv ), 'an invoice of another recurring invoice' );
    }

    public function testRelayLanesKeepOneClientsEventsInOrder()
    {
        $a = ProvisioningRelay::lane( array( 'client' => array( 'id' => 'C1' ), 'subscription' => array( 'id' => 'S1' ) ) );
        $b = ProvisioningRelay::lane( array( 'client' => array( 'id' => 'C1' ), 'subscription' => array( 'id' => 'S2' ) ) );
        $this->assertSame( $a, $b, 'a plan change stays in the same lane' );
        $this->assertNotSame( $a, ProvisioningRelay::lane( array( 'client' => array( 'id' => 'C2' ) ) ) );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $a );

        $keys = array( ProvisioningRelay::rowKey( $a, 10 ), ProvisioningRelay::rowKey( $a, 9 ), ProvisioningRelay::rowKey( $a, 100 ) );
        sort( $keys );
        $this->assertSame( array( ProvisioningRelay::rowKey( $a, 9 ), ProvisioningRelay::rowKey( $a, 10 ), ProvisioningRelay::rowKey( $a, 100 ) ), $keys, 'row names sort in event order' );
        $this->assertLessThan( 191, strlen( 'invoiceninja_' . ProvisioningRelay::rowKey( $a, PHP_INT_MAX ) ) );
    }

    public function testRelayRetriesThenGivesUp()
    {
        $this->assertSame( array( 'done', 0 ), ProvisioningRelay::next( true, 3 ) );
        [ $what, $delay ] = ProvisioningRelay::next( false, 1 );
        $this->assertSame( 'retry', $what );
        $this->assertGreaterThanOrEqual( 60, $delay );
        $this->assertSame( 'retry', ProvisioningRelay::next( false, 8 )[0] );
        $this->assertSame( array( 'give_up', 0 ), ProvisioningRelay::next( false, 9 ) );
    }

    public function testRelayJobArgumentsStaySmallWhateverThePayload()
    {
        $plugin   = $this->plugin();
        $sync     = ( new \ReflectionClass( SubscriptionSync::class ) )->newInstanceWithoutConstructor();
        $this->options['invoiceninja_subscriptions_config'] = array( 'S1' => array( 'connected' => true, 'relay_url' => 'https://relay.test/x' ) );
        $relay    = new ProvisioningRelay( $plugin, new Configurator( $plugin, $sync ) );
        $pushed   = array();
        Functions\when( 'as_enqueue_async_action' )->alias( function ( $hook, $args ) use ( &$pushed ) {
            $pushed[] = $args;
            return 1;
        } );
        Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
        $lines   = array_fill( 0, 400, array( 'product_key' => 'vps', 'notes' => str_repeat( 'x', 80 ), 'quantity' => 1.0, 'cost' => 10.0 ) );
        $payload = array( 'id' => 'evt_big', 'sequence' => 7, 'event' => 'purchased', 'client' => array( 'id' => 'C1' ), 'recurring_invoice' => array( 'line_items' => $lines ) );

        $relay->queue( 'S1', $payload );

        $this->assertCount( 1, $pushed );
        $this->assertLessThan( 200, strlen( json_encode( $pushed[0] ) ), 'Action Scheduler refuses arguments over 8,000 characters' );
        $row = $this->options[ 'invoiceninja_' . ProvisioningRelay::rowKey( ProvisioningRelay::lane( $payload ), 7 ) ] ?? null;
        $this->assertSame( $payload, $row['payload'] ?? null, 'the payload waits in the outbox' );
    }

    public function testAnOldAddressOfThisSiteIsStillOurs()
    {
        $now = 'https://shop.example.com/wp-json/invoiceninja/v1/subscription-callback';
        $this->assertTrue( Configurator::ours( 'http://shop.example.com/wp-json/invoiceninja/v1/subscription-callback', $now ), 'http → https' );
        $this->assertTrue( Configurator::ours( 'https://www.shop.example.com/wp-json/invoiceninja/v1/subscription-callback', $now ), 'www' );
        $old = 'https://shop.example.com/?rest_route=/invoiceninja/v1/subscription-callback';
        $this->assertTrue( Configurator::ours( $old, $now, $old ), 'a permalink change: the URL this site set' );
        $this->assertFalse( Configurator::ours( $old, $now ), 'not without it' );
        $live = 'https://live.example.com/wp-json/invoiceninja/v1/subscription-callback';
        $this->assertFalse( Configurator::ours( $live, $now, $live ), "a copy of the site never takes over another host's subscriptions" );
        $this->assertFalse( Configurator::ours( '', $now ) );

        $plan = Configurator::plan( array( 'post_purchase_url' => 'http://shop.example.com/wp-json/invoiceninja/v1/subscription-callback' ), $now, 'https://shop.example.com/?invoiceninja=subscribed', str_repeat( 'a', 64 ) );
        $this->assertTrue( $plan['ok'], 'reconnecting after http → https is not "another WordPress site"' );
        $this->assertNull( $plan['original'], 'and keeps the original stored when it first connected' );
    }
}
