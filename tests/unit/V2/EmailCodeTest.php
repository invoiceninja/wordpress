<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Core\Logger;
use InvoiceNinja\Core\Mailer;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Secrets;
use InvoiceNinja\Verification\DeviceRemember;
use InvoiceNinja\Verification\EmailCode;
use InvoiceNinja\Tests\TestCase;

class EmailCodeTest extends TestCase
{
    private function service(): EmailCode
    {
        global $wpdb;
        $wpdb    = new class { public $prefix = 'wp_'; public function insert() { return 1; } };
        $options = new Options();
        return new EmailCode( new Secrets( $options ), new Mailer( $options ), new Logger( $options ), $options );
    }

    private function sentCode(): string
    {
        preg_match( '/^(\d{6}) is your/', end( $this->mails )['subject'], $m );
        return $m[1];
    }

    public function testSendAndVerifyOnce()
    {
        $codes = $this->service();
        $this->assertSame( 'sent', $codes->send( 'Jane@Example.com ', '1.2.3.4' )['status'] );
        $code = $this->sentCode();
        $this->assertStringContainsString( substr( $code, 0, 3 ) . ' ' . substr( $code, 3 ), end( $this->mails )['body'] );

        foreach ( $this->transients as $value ) {
            $this->assertStringNotContainsString( $code, json_encode( $value ), 'only a hash is stored' );
        }
        $this->assertSame( 'ok', $codes->verify( 'jane@example.com', substr( $code, 0, 3 ) . ' ' . substr( $code, 3 ) ) );
        $this->assertSame( 'expired', $codes->verify( 'jane@example.com', $code ), 'a code works once' );
    }

    public function testWrongCodesLockAfterFiveAttempts()
    {
        $codes = $this->service();
        $codes->send( 'jane@example.com', '1.2.3.4' );
        $code  = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ( $i = 1; $i < EmailCode::MAX_ATTEMPTS; $i++ ) {
            $this->assertSame( 'wrong', $codes->verify( 'jane@example.com', $wrong ) );
        }
        $this->assertSame( 'locked', $codes->verify( 'jane@example.com', $wrong ) );
        $this->assertSame( 'locked', $codes->verify( 'jane@example.com', $code ), 'even the right code is refused once locked' );
    }

    public function testCodesArePerPurposeAndEmail()
    {
        $codes = $this->service();
        $codes->send( 'jane@example.com', '1.2.3.4', 'checkout' );
        $code = $this->sentCode();
        $this->assertSame( 'expired', $codes->verify( 'jane@example.com', $code, 'portal' ) );
        $this->assertSame( 'expired', $codes->verify( 'john@example.com', $code ) );
    }

    public function testResendWaitAndRateLimits()
    {
        $codes = $this->service();
        $codes->send( 'jane@example.com', '1.2.3.4' );
        $again = $codes->send( 'jane@example.com', '1.2.3.4' );
        $this->assertSame( 'wait', $again['status'] );
        $this->assertGreaterThan( 0, $again['retry_after'] );

        for ( $i = 0; $i < EmailCode::PER_IP_HOUR + 2; $i++ ) {
            $last = $codes->send( "user$i@example.com", '9.9.9.9' );
        }
        $this->assertSame( 'limited', $last['status'] );
        $this->assertSame( 'invalid', $codes->send( 'not-an-email', '1.2.3.4' )['status'] );
    }

    public function testParallelGuessesAreCappedPerEmail()
    {
        $codes = $this->service();
        $codes->send( 'jane@example.com', '1.2.3.4' );
        $code = $this->sentCode();
        // Wrong guesses from many IPs: the per-email cap still holds
        for ( $i = 0; $i < EmailCode::GUESSES_PER_EMAIL_HOUR; $i++ ) {
            $codes->verify( 'jane@example.com', '999999' === $code ? '888888' : '999999', 'checkout', "10.0.0.$i" );
        }
        $this->assertSame( 'locked', $codes->verify( 'jane@example.com', $code, 'checkout', '10.0.1.1' ) );
    }

    public function testGuessesWithoutACodeDontLockTheEmailOut()
    {
        $codes = $this->service();
        // Someone guessing at an email that has no code waiting, from many addresses
        for ( $i = 0; $i < EmailCode::GUESSES_PER_EMAIL_HOUR + 5; $i++ ) {
            $this->assertSame( 'expired', $codes->verify( 'jane@example.com', '123456', 'checkout', "10.0.2.$i" ) );
        }
        $codes->send( 'jane@example.com', '1.2.3.4' );
        $this->assertSame( 'ok', $codes->verify( 'jane@example.com', $this->sentCode(), 'checkout', '1.2.3.4' ), 'the owner can still use their code' );
    }

    public function testRememberedDeviceIsTiedToTheEmail()
    {
        $device = new DeviceRemember( new Secrets( new Options() ) );
        $value  = $device->value( 'Jane@Example.com', 1000 );

        $this->assertTrue( $device->remembers( $value, 'jane@example.com', 2000 ) );
        $this->assertFalse( $device->remembers( $value, 'other@example.com', 2000 ), 'only the same email' );
        $this->assertFalse( $device->remembers( $value, 'jane@example.com', 1000 + 31 * 86400 ), 'expires after 30 days' );
        $this->assertFalse( $device->remembers( str_replace( '.', 'x.', $value ), 'jane@example.com', 2000 ), 'tampered cookie' );
        $this->assertFalse( $device->remembers( 'garbage', 'jane@example.com', 2000 ) );
    }
}
