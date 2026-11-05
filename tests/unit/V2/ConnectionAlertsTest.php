<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Core\Notifications;
use InvoiceNinja\Tests\TestCase;

/**
 * One timeout at the daily check isn't a "connection problem" email.
 */
class ConnectionAlertsTest extends TestCase
{
    public function testABlipIsCheckedAgainBeforeAdminsAreTold()
    {
        $this->assertSame( 'recheck', Notifications::afterCheck( false, 'connected', 'connected', 1 ), 'a timeout leaves the status connected' );
        $this->assertSame( 15 * 60, Notifications::recheckDelay( 'recheck', 1 ) );
        $this->assertSame( 'recheck', Notifications::afterCheck( false, 'connected', 'connected', 2 ) );
        $this->assertSame( 'lost', Notifications::afterCheck( false, 'connected', 'connected', Notifications::RECHECKS ), 'still failing half an hour later' );
        $this->assertSame( 'recovered', Notifications::afterCheck( true, 'connected', 'connected', 2 ), 'back before anyone was told' );
    }

    public function testABrokenConnectionIsReportedAtOnce()
    {
        $this->assertSame( 'lost', Notifications::afterCheck( false, 'connected', 'error', 1 ), 'e.g. a revoked token' );
        $this->assertSame( 'none', Notifications::afterCheck( false, 'error', 'error', 1 ), 'already reported' );
        $this->assertSame( 0, Notifications::recheckDelay( 'none', 1 ) );
    }

    public function testAnOutageIsCheckedHourlyForADaySoTheBackEmailIsPrompt()
    {
        $this->assertSame( 3600, Notifications::recheckDelay( 'lost', Notifications::RECHECKS ) );
        $this->assertSame( 3600, Notifications::recheckDelay( 'lost', Notifications::RECHECKS + 23 ) );
        $this->assertSame( 0, Notifications::recheckDelay( 'lost', Notifications::RECHECKS + 24 ), 'then the daily check carries on' );
    }
}
