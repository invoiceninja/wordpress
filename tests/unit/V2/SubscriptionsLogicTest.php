<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Subscriptions\Blocks;
use InvoiceNinja\Subscriptions\Callback;
use InvoiceNinja\Subscriptions\Configurator;
use InvoiceNinja\Subscriptions\Lifecycle;
use InvoiceNinja\Subscriptions\Redirects;
use InvoiceNinja\Tests\TestCase;

class SubscriptionsLogicTest extends TestCase
{
    private const OURS   = 'https://shop.example.com/wp-json/invoiceninja/v1/subscription-callback';
    private const BACK   = 'https://shop.example.com/?invoiceninja=subscribed';
    private const SECRET = 'abcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabca';

    public function testOptInSetsOurCallbackAndKeepsTheOriginal()
    {
        $current = [ 'post_purchase_url' => 'https://crm.test/hook', 'post_purchase_rest_method' => 'put', 'post_purchase_headers' => [ 'X-Crm' => '1' ], 'return_url' => 'https://crm.test/thanks', 'post_purchase_body' => 'x' ];
        $plan    = Configurator::plan( $current, self::OURS, self::BACK, self::SECRET );

        $this->assertTrue( $plan['ok'] );
        $this->assertSame( $current, $plan['original'] );
        $this->assertSame( self::OURS, $plan['config']['post_purchase_url'] );
        $this->assertSame( 'post', $plan['config']['post_purchase_rest_method'], 'Invoice Ninja calls $client->{method}' );
        $this->assertSame( [ 'X-Invoiceninja-Secret' => self::SECRET ], $plan['config']['post_purchase_headers'] );
        $this->assertSame( self::BACK, $plan['config']['return_url'] );
        $this->assertSame( 'x', $plan['config']['post_purchase_body'], 'other keys are kept' );
    }

    public function testOptInRefusesASubscriptionThatReportsToAnotherWordPressSite()
    {
        foreach ( [ 'https://live.example.com/wp-json/invoiceninja/v1/subscription-callback', 'https://other.test/?rest_route=/x/y', 'https://w.test/wp-admin/admin-ajax.php?action=x' ] as $url ) {
            $plan = Configurator::plan( [ 'post_purchase_url' => $url ], self::OURS, self::BACK, self::SECRET );
            $this->assertFalse( $plan['ok'], $url );
            $this->assertSame( 'other_wordpress', $plan['error'] );
            $this->assertNotSame( '', $plan['host'] );
        }
    }

    public function testOptInAgainKeepsTheStoredOriginalAndAnEmptyConfigIsFine()
    {
        $again = Configurator::plan( [ 'post_purchase_url' => self::OURS ], self::OURS, self::BACK, self::SECRET );
        $this->assertTrue( $again['ok'] );
        $this->assertNull( $again['original'], 'already ours: the stored original stays' );

        $fresh = Configurator::plan( [], self::OURS, self::BACK, self::SECRET );
        $this->assertTrue( $fresh['ok'] );
        $this->assertSame( [], $fresh['original'] );
    }

    public function testCallbackContextKeepsOnlyKnownContextsAndCleanIds()
    {
        $ctx = Callback::context( [ 'context' => 'recurring_purchase', 'subscription' => 'S1', 'client' => 'C/1', 'recurring_invoice' => 'R1', 'db' => 'db-ninja-01' ] );
        $this->assertSame( 'recurring_purchase', $ctx['context'] );
        $this->assertSame( 'C1', $ctx['client'] );
        $this->assertSame( '', $ctx['invoice'] );
        $this->assertArrayNotHasKey( 'db', $ctx );
        $this->assertNull( Callback::context( [ 'context' => 'nope', 'subscription' => 'S1', 'client' => 'C1' ] ) );
        $this->assertNull( Callback::context( [ 'context' => 'trial', 'subscription' => 'S1' ] ), 'a client is required' );
        foreach ( [ 'is_eligible', 'single_purchase', 'recurring_purchase', 'trial', 'change_plan', 'plan_paid', 'cancellation', 'plan_expired' ] as $context ) {
            $this->assertNotNull( Callback::context( [ 'context' => $context, 'subscription' => 'S1', 'client' => 'C1' ] ), $context );
        }
    }

    public function testDaysOverdueUsesTheDueDateOrThePartialDueDate()
    {
        $now = strtotime( '2026-11-10 15:00:00 UTC' );
        $this->assertSame( 9, Lifecycle::daysOverdue( [ 'due_date' => '2026-11-01' ], $now ) );
        $this->assertSame( 0, Lifecycle::daysOverdue( [ 'due_date' => '2026-11-10' ], $now ) );
        $this->assertSame( -2, Lifecycle::daysOverdue( [ 'due_date' => '2026-11-12' ], $now ) );
        $this->assertSame( 4, Lifecycle::daysOverdue( [ 'due_date' => '2026-12-01', 'partial' => 5, 'partial_due_date' => '2026-11-06' ], $now ) );
        $this->assertSame( -1, Lifecycle::daysOverdue( [ 'due_date' => '' ], $now ) );
    }

    public function testPricingTableAttributesParse()
    {
        $this->assertSame( [ 'a', 'b' ], Blocks::ids( 'a, b,,a' ) );
        $this->assertSame( [ 'a' => 'x', 'b' => 'y' ], Blocks::pairs( 'a:x,b:y,c:,c:c,bad' ) );
    }

    public function testAccountRowsMapStatusesAndLinks()
    {
        $subs      = [ 'S1' => [ 'name' => 'Pro', 'allow_plan_changes' => true, 'allow_cancellation' => false ] ];
        $recurring = [
            [ 'id' => 'R1', 'client_id' => 'C1', 'subscription_id' => 'S1', 'status_id' => '2', 'next_send_date' => '2026-12-01', 'amount' => 10 ],
            [ 'id' => 'R2', 'client_id' => 'C1', 'subscription_id' => 'S1', 'status_id' => '2', 'next_send_date' => '2026-12-05', 'amount' => 10 ],
            [ 'id' => 'R3', 'client_id' => 'C1', 'subscription_id' => 'S1', 'status_id' => '4', 'next_send_date' => '2026-12-05', 'amount' => 10 ],
            [ 'id' => 'R4', 'client_id' => 'C1', 'subscription_id' => 'S1', 'status_id' => '-1', 'next_send_date' => '', 'amount' => 10 ],
            [ 'id' => 'R5', 'client_id' => 'C1', 'subscription_id' => '', 'status_id' => '2' ],
            [ 'id' => 'R6', 'client_id' => 'OTHER', 'subscription_id' => 'S1', 'status_id' => '2' ],
        ];
        $overdue   = [ [ 'recurring_id' => 'R2', 'status_id' => '2' ] ];
        $states    = [ 'ri:R4' => [ 'status' => 'trial' ], 'ri:R1' => [ 'status' => 'suspended' ] ];
        $rows      = Redirects::rows( $recurring, $overdue, 'C1', $subs, $states );

        $this->assertSame( [ 'R1', 'R2', 'R3', 'R4' ], array_column( $rows, 'id' ), 'only subscriptions, only this client' );
        $this->assertSame( [ 'suspended', 'overdue', 'cancelled', 'trial' ], array_column( $rows, 'status' ) );
        $this->assertSame( '2026-12-01', $rows[0]['next_date'] );
        $this->assertSame( '', $rows[2]['next_date'], 'no next bill once cancelled' );
        $this->assertTrue( $rows[0]['links']['change'] );
        $this->assertFalse( $rows[0]['links']['cancel'], 'cancelling is off for this subscription' );
        $this->assertFalse( $rows[2]['links']['payment_method'] );
        $this->assertSame( 'Pro', $rows[0]['name'] );
    }

    public function testARefusedCallbackAddressIsRecognised()
    {
        $refused = new \InvoiceNinja\Api\ApiException( \InvoiceNinja\Api\ErrorKind::Validation, '422', 422, [ 'webhook_configuration.post_purchase_url' => [ 'Invalid URL' ] ] );
        $this->assertTrue( Configurator::urlRefused( $refused ) );
        $this->assertFalse( Configurator::urlRefused( new \InvoiceNinja\Api\ApiException( \InvoiceNinja\Api\ErrorKind::Validation, '422', 422, [ 'steps' => [ 'required' ] ] ) ) );
        $this->assertFalse( Configurator::urlRefused( new \InvoiceNinja\Api\ApiException( \InvoiceNinja\Api\ErrorKind::Server, '500' ) ) );
    }
}
