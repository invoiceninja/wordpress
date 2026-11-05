<?php

namespace InvoiceNinja\Tests\V2;

use Brain\Monkey\Functions;
use InvoiceNinja\Core\Logger;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Queue;
use InvoiceNinja\Tests\TestCase;

/**
 * Recurring jobs are checked about hourly, not with two queries on every request.
 */
class QueueRecurringTest extends TestCase
{
    private int $lookups = 0;

    private array $scheduled = [];

    protected function set_up(): void
    {
        parent::set_up();
        $this->lookups   = 0;
        $this->scheduled = [];
        Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
        Functions\when( 'wp_rand' )->justReturn( 100 );
        Functions\when( 'as_has_scheduled_action' )->alias( function ( $hook ) {
            ++$this->lookups;
            return isset( $this->scheduled[ $hook ] );
        } );
        Functions\when( 'as_schedule_recurring_action' )->alias( function ( $at, $interval, $hook ) {
            $this->scheduled[ $hook ] = $interval;
            return 1;
        } );
        Functions\when( 'as_unschedule_all_actions' )->alias( function ( $hook ) {
            unset( $this->scheduled[ $hook ] );
        } );
    }

    public function testTheCheckRunsAboutHourly()
    {
        $queue = new Queue( new Logger( new Options() ) );
        $queue->recurring( 'invoiceninja_daily', 86400 );
        $queue->recurring( 'invoiceninja_daily', 86400 );
        $queue->recurring( 'invoiceninja_daily', 86400 );
        $this->assertSame( 1, $this->lookups, 'one lookup, then remembered' );
        $this->assertSame( [ 'invoiceninja_daily' => 86400 ], $this->scheduled );

        $this->options[ Queue::CHECKED ]['invoiceninja_daily']['at'] = time() - 3601;
        $queue->recurring( 'invoiceninja_daily', 86400 );
        $this->assertSame( 2, $this->lookups, 'checked again after an hour' );

        $queue->recurring( 'invoiceninja_daily', 86400, true );
        $this->assertSame( 3, $this->lookups, 'Repair checks now' );
    }

    public function testStoppingAndStartingAgainIsNotDelayed()
    {
        $queue = new Queue( new Logger( new Options() ) );
        $queue->recurring( 'invoiceninja_poll', 900 );
        $queue->stopRecurring( 'invoiceninja_poll' );
        $this->assertSame( [], $this->scheduled );
        $queue->stopRecurring( 'invoiceninja_poll' );
        $this->assertSame( 2, $this->lookups, 'stopped: remembered too' );

        $queue->recurring( 'invoiceninja_poll', 900 );
        $this->assertSame( [ 'invoiceninja_poll' => 900 ], $this->scheduled, 'turned on again: scheduled at once' );
    }

    public function testParkingForgetsTheChecks()
    {
        Functions\when( 'as_get_scheduled_actions' )->justReturn( [] );
        $queue = new Queue( new Logger( new Options() ) );
        $queue->recurring( 'invoiceninja_daily', 86400 );
        Queue::park();
        $this->assertArrayNotHasKey( Queue::CHECKED, $this->options, 'scheduled again on reactivation' );
    }
}
