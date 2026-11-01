<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Core\LogRedactor;
use InvoiceNinja\Tests\TestCase;

class LogRedactorTest extends TestCase
{
    public function testRemovesSecretsAndMasksPersonalData()
    {
        $json = LogRedactor::context( [
            'api_token' => 'abc',
            'nested'    => [ 'X-API-TOKEN' => 'abc', 'client_hash' => 'h' ],
            'email'     => 'jane.doe@example.com',
            'link'      => 'https://billing.example.com/client/key_login/AbCdEf?client_hash=xyz',
            'envelope'  => 'in1:0123abcd:QUJD',
            'count'     => 3,
        ] );
        $data = json_decode( $json, true );

        $this->assertSame( '[removed]', $data['api_token'] );
        $this->assertSame( '[removed]', $data['nested']['X-API-TOKEN'] );
        $this->assertSame( '[removed]', $data['nested']['client_hash'] );
        $this->assertSame( 'j***@example.com', $data['email'] );
        $this->assertSame( 'https://billing.example.com/client/[link removed]', $data['link'] );
        $this->assertSame( '[encrypted token]', $data['envelope'] );
        $this->assertSame( 3, $data['count'] );
        $this->assertStringNotContainsString( 'abc', $json );
    }

    public function testCapsSize()
    {
        $json = LogRedactor::context( [ 'big' => str_repeat( 'x', 20000 ) ] );
        $this->assertLessThanOrEqual( 8192 + 64, strlen( $json ) );
    }

    public function testRedactsQueryKeysInText()
    {
        $this->assertSame( 'see /x?key=[removed]&a=1', LogRedactor::text( 'see /x?key=SECRET&a=1' ) );
    }
}
