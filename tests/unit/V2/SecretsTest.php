<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Secrets;
use InvoiceNinja\Tests\TestCase;

class SecretsTest extends TestCase
{
    public function testEncryptRoundTripAndEnvelopeFormat()
    {
        $secrets  = new Secrets( new Options() );
        $envelope = $secrets->encrypt( 'my-token-123' );

        $this->assertMatchesRegularExpression( '/^in1:[0-9a-f]{8}:[A-Za-z0-9+\/=]+$/', $envelope );
        $this->assertStringNotContainsString( 'my-token-123', $envelope );
        $this->assertTrue( Secrets::isEnvelope( $envelope ) );
        $this->assertSame( 'my-token-123', $secrets->decrypt( $envelope ) );
        $this->assertNotSame( $envelope, $secrets->encrypt( 'my-token-123' ), 'a fresh nonce each time' );
    }

    public function testDecryptReturnsNullWhenTheKeyChanged()
    {
        $envelope = ( new Secrets( new Options() ) )->encrypt( 'my-token' );
        \Brain\Monkey\Functions\when( 'wp_salt' )->justReturn( 'rotated-salts' );

        $this->assertNull( ( new Secrets( new Options() ) )->decrypt( $envelope ) );
    }

    public function testDecryptRejectsDamagedEnvelopes()
    {
        $secrets  = new Secrets( new Options() );
        $envelope = $secrets->encrypt( 'my-token' );

        $this->assertNull( $secrets->decrypt( substr( $envelope, 0, -4 ) . 'AAAA' ) );
        $this->assertNull( $secrets->decrypt( 'in1:zzzz:abc' ) );
        $this->assertNull( $secrets->decrypt( 'plain-token' ) );
    }

    public function testPurposeSecretsAreRandomAndStable()
    {
        $secrets = new Secrets( new Options() );
        $a       = $secrets->get( 'cart_secret' );

        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $a );
        $this->assertSame( $a, $secrets->get( 'cart_secret' ) );
        $this->assertNotSame( $a, $secrets->get( 'device_secret' ) );
    }

    public function testDamagedSecretIsReplaced()
    {
        $this->options['invoiceninja_cart_secret'] = 'short';
        $value = ( new Secrets( new Options() ) )->get( 'cart_secret' );

        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $value );
        $this->assertSame( $value, $this->options['invoiceninja_cart_secret'] );
    }

    public function testUnknownPurposeIsRejected()
    {
        $this->expectException( \InvalidArgumentException::class );
        ( new Secrets( new Options() ) )->get( 'nope' );
    }

    public function testSiteCode()
    {
        $secrets = new Secrets( new Options() );
        $code    = $secrets->siteCode();

        $this->assertMatchesRegularExpression( '/^[A-Z][1-9]$/', $code );
        $this->assertSame( $code, $secrets->siteCode() );
    }
}
