<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Subscriptions\Blocks;
use InvoiceNinja\Subscriptions\Callback;
use InvoiceNinja\Subscriptions\Configurator;
use InvoiceNinja\Subscriptions\Lifecycle;
use InvoiceNinja\Subscriptions\Redirects;
use InvoiceNinja\Subscriptions\Payload;
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

    public function testPlanTextIsCleanedAndHasNoDefaults()
    {
        \Brain\Monkey\Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );
        $this->assertSame( [], Blocks::details( '' ) );
        $this->assertSame( [], Blocks::details( [] ) );
        $details = Blocks::details( [
            'SUBM'    => [ 'name' => ' Pro ', 'description' => 'For regulars.', 'perks' => "First pick\r\n\n  15% off  \n" ],
            'bad id!' => [ 'name' => 'x' ],
            'SUBT'    => 'not an array',
        ] );
        $this->assertSame( [ 'SUBM' => [ 'name' => 'Pro', 'description' => 'For regulars.', 'perks' => [ 'First pick', '15% off' ] ] ], $details );
    }

    public function testYearlySavingsComeFromThePairedPrices()
    {
        $monthly = [ 'id' => 'M', 'price' => 10, 'frequency_id' => 5, 'currency_id' => '1' ];
        $yearly  = [ 'id' => 'Y', 'price' => 100, 'frequency_id' => 10, 'currency_id' => '1' ];
        $saving  = Blocks::savings( $monthly, $yearly );
        $this->assertSame( 'Y', $saving['yearly_id'] );
        $this->assertSame( 20.0, $saving['amount'] );
        $this->assertSame( 2, $saving['months'] );
        $this->assertSame( $saving, Blocks::savings( $yearly, $monthly ), 'whichever way round they are paired' );

        $odd = Blocks::savings( $monthly, [ 'price' => 105 ] + $yearly );
        $this->assertSame( 15.0, $odd['amount'] );
        $this->assertSame( 0, $odd['months'], '1.5 months is not a whole number of payments' );

        $this->assertNull( Blocks::savings( $monthly, [ 'price' => 120 ] + $yearly ), 'no saving, nothing shown' );
        $this->assertNull( Blocks::savings( $monthly, [ 'price' => 130 ] + $yearly ) );
        $this->assertNull( Blocks::savings( $monthly, [ 'currency_id' => '2' ] + $yearly ), 'different currencies' );
        $this->assertNull( Blocks::savings( $monthly, [ 'frequency_id' => 7 ] + $yearly ), 'only monthly against yearly' );
    }

    public function testTheToggleHintNeverPromisesMoreThanEveryPlanSaves()
    {
        $two  = [ 'yearly_id' => 'Y', 'amount' => 20.0, 'months' => 2, 'percent' => 16.67 ];
        $odd  = [ 'yearly_id' => 'Z', 'amount' => 15.0, 'months' => 0, 'percent' => 12.5 ];
        $this->assertSame( '2 months free', Blocks::toggleHint( [ $two, $two ] ) );
        $this->assertSame( 'save up to 16%', Blocks::toggleHint( [ $two, $odd ] ) );
        $this->assertSame( 'save up to 16%', Blocks::toggleHint( [ $two, null ] ), 'one pair saves nothing' );
        $this->assertSame( '', Blocks::toggleHint( [ null ] ) );
        $this->assertSame( '', Blocks::toggleHint( [] ) );
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

    public function testAForgivenInvoiceLiftsASuspensionLikeAPaidOne()
    {
        $this->assertTrue( Lifecycle::settled( [ 'status_id' => 4, 'balance' => 0 ] ), 'paid' );
        $this->assertTrue( Lifecycle::settled( [ 'status_id' => 5, 'balance' => 100 ] ), 'cancelled' );
        $this->assertTrue( Lifecycle::settled( [ 'status_id' => 6, 'balance' => 100 ] ), 'reversed' );
        $this->assertTrue( Lifecycle::settled( [ 'status_id' => 2, 'balance' => 100, 'is_deleted' => true ] ), 'deleted' );
        $this->assertFalse( Lifecycle::settled( [ 'status_id' => 2, 'balance' => 100 ] ), 'still owed' );
        $this->assertFalse( Lifecycle::settled( [ 'status_id' => 3, 'balance' => 40 ] ), 'partly paid' );
    }

    public function testASecondSuspensionForTheSameInvoiceGetsItsOwnEventId()
    {
        $first = Payload::id( 'suspended', 'S1', 'C1', 'R1', 'I1', 'overdue_poll' );
        $this->assertSame( $first, Payload::id( 'suspended', 'S1', 'C1', 'R1', 'I1', 'overdue_poll', 1 ), 'the first keeps the id it always had' );
        $again = Payload::id( 'suspended', 'S1', 'C1', 'R1', 'I1', 'overdue_poll', 2 );
        $this->assertNotSame( $first, $again, 'receivers must not drop it as a repeat' );
        $this->assertSame( $again, Payload::id( 'suspended', 'S1', 'C1', 'R1', 'I1', 'overdue_poll', 2 ), 'stable for retries' );
    }
}
