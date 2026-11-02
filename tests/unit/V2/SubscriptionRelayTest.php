<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Subscriptions\Payload;
use InvoiceNinja\Subscriptions\ProvisioningRelay;
use InvoiceNinja\Subscriptions\Signature;
use InvoiceNinja\Tests\TestCase;

class SubscriptionRelayTest extends TestCase
{
    private const SECRET = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

    public function testSignatureIsHmacSha256OfTimestampDotBody()
    {
        $body = '{"event":"purchased"}';
        $sig  = Signature::sign( 1700000000, $body, self::SECRET );

        $this->assertSame( 'sha256=' . hash_hmac( 'sha256', '1700000000.' . $body, self::SECRET ), $sig );
        $this->assertTrue( Signature::verify( $sig, '1700000000', $body, self::SECRET, 1700000100 ) );
    }

    public function testVerifyRefusesTamperingOldDeliveriesAndOtherSecrets()
    {
        $body = '{"event":"purchased"}';
        $sig  = Signature::sign( 1700000000, $body, self::SECRET );

        $this->assertFalse( Signature::verify( $sig, '1700000000', '{"event":"cancelled"}', self::SECRET, 1700000000 ), 'body changed' );
        $this->assertFalse( Signature::verify( $sig, '1700000001', $body, self::SECRET, 1700000000 ), 'timestamp changed' );
        $this->assertFalse( Signature::verify( $sig, '1700000000', $body, self::SECRET, 1700000000 + 301 ), 'too old' );
        $this->assertFalse( Signature::verify( $sig, '1700000000', $body, str_repeat( '0', 64 ), 1700000000 ), 'other secret' );
        $this->assertFalse( Signature::verify( $sig, 'x', $body, self::SECRET, 1700000000 ), 'bad timestamp' );
    }

    public function testRelayRequestIsSignedOverTheExactBodySent()
    {
        $payload = [ 'version' => 1, 'id' => 'evt_1', 'event' => 'suspended', 'url' => 'https://a.example/x' ];
        $request = ProvisioningRelay::request( $payload, self::SECRET, 1700000000 );

        $this->assertSame( 'application/json', $request['headers']['Content-Type'] );
        $this->assertSame( '1700000000', $request['headers']['X-Invoiceninja-Timestamp'] );
        $this->assertSame( 'suspended', $request['headers']['X-Invoiceninja-Event'] );
        $this->assertSame( 'evt_1', $request['headers']['X-Invoiceninja-Id'] );
        $this->assertTrue( Signature::verify( $request['headers']['X-Invoiceninja-Signature'], '1700000000', $request['body'], self::SECRET, 1700000000 ) );
        $decoded = json_decode( $request['body'], true );
        $this->assertSame( '2023-11-14T22:13:20Z', $decoded['sent_at'] );
        $this->assertSame( 'https://a.example/x', $decoded['url'], 'slashes are not escaped' );
    }

    public function testPayloadHasAStableVersionedShape()
    {
        $client  = [
            'id' => 'CL1', 'number' => '0007', 'name' => 'Acme', 'custom_value2' => 'ACC-1', 'balance' => 99,
            'contacts' => [
                [ 'id' => 'C2', 'email' => 'second@acme.test', 'is_primary' => false, 'contact_key' => 'secret-key' ],
                [ 'id' => 'C1', 'email' => 'boss@acme.test', 'first_name' => 'Ann', 'is_primary' => true, 'link' => 'https://in/client/key_login/x' ],
            ],
        ];
        $ri      = [ 'id' => 'RI1', 'number' => 'R-1', 'status_id' => '2', 'frequency_id' => '5', 'next_send_date' => '2026-12-01', 'amount' => 10, 'line_items' => [ [ 'product_key' => 'VPS', 'quantity' => 1, 'cost' => 10, 'notes' => 'Small' ] ] ];
        $payload = Payload::build( 'purchased', 'recurring_purchase', [ 'id' => 'S1', 'name' => 'Pro', 'price' => 10, 'frequency_id' => 5 ], $client, $ri, null, 12, 1700000000 );

        $this->assertSame( 1, $payload['version'] );
        $this->assertSame( 'purchased', $payload['event'] );
        $this->assertSame( '2023-11-14T22:13:20Z', $payload['occurred_at'] );
        $this->assertSame( [ 'id' => 'S1', 'name' => 'Pro', 'price' => 10.0, 'frequency_id' => 5 ], $payload['subscription'] );
        $this->assertSame( 'boss@acme.test', $payload['client']['email'], 'the primary contact' );
        $this->assertSame( 'ACC-1', $payload['client']['account_key'] );
        $this->assertArrayNotHasKey( 'balance', $payload['client'] );
        $this->assertStringNotContainsString( 'key_login', json_encode( $payload ), 'no login links' );
        $this->assertStringNotContainsString( 'secret-key', json_encode( $payload ), 'no contact keys' );
        $this->assertSame( 2, $payload['recurring_invoice']['status_id'] );
        $this->assertSame( 'VPS', $payload['recurring_invoice']['line_items'][0]['product_key'] );
        $this->assertNull( $payload['invoice'] );
        $this->assertSame( 12, $payload['wordpress_user_id'] );
        $this->assertSame(
            [ 'version', 'id', 'event', 'context', 'occurred_at', 'subscription', 'client', 'recurring_invoice', 'invoice', 'wordpress_user_id' ],
            array_keys( $payload )
        );
        $again = Payload::build( 'purchased', 'recurring_purchase', [ 'id' => 'S1' ], $client, $ri, null, 0, 1800000000 );
        $this->assertSame( $payload['id'], $again['id'], 'the event id is stable across retries' );
        $other = Payload::build( 'paid', 'plan_paid', [ 'id' => 'S1' ], $client, $ri, [ 'id' => 'INV9' ], 0, 1700000000 );
        $this->assertNotSame( $payload['id'], $other['id'] );
        $this->assertMatchesRegularExpression( '/^evt_[0-9a-f]{32}$/', $payload['id'] );
    }
}
