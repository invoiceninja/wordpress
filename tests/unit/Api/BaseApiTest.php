<?php

namespace InvoiceNinja\Tests\Api;

use InvoiceNinja\Api\BaseApi;
use InvoiceNinja\Tests\TestCase;

class BaseApiTest extends TestCase
{
    /**
     * @dataProvider urlProvider
     */
    public function testBaseUrlIsNormalized( $input, $expected )
    {
        $this->options['invoiceninja_api_url'] = $input;

        $this->assertSame( $expected, BaseApi::getBaseUrl() );
    }

    public function urlProvider()
    {
        return [
            'blank uses hosted'           => [ '', 'https://invoicing.co' ],
            'unset uses hosted'           => [ false, 'https://invoicing.co' ],
            'trailing slash'              => [ 'https://invoice.example.com/', 'https://invoice.example.com' ],
            'api suffix stripped'         => [ 'https://invoice.example.com/api/v1', 'https://invoice.example.com' ],
            'api suffix + slash'          => [ 'https://invoice.example.com/api/v1/', 'https://invoice.example.com' ],
            'api suffix any case'         => [ 'https://invoice.example.com/API/V1', 'https://invoice.example.com' ],
            'whitespace trimmed'          => [ "  https://invoice.example.com \n", 'https://invoice.example.com' ],
            // #3 / #14: the last character of the host was being lost
            'four letter tld (#3)'        => [ 'https://invoice.example.shop', 'https://invoice.example.shop' ],
            'second level tld (#14)'      => [ 'https://invoice.example.co.za', 'https://invoice.example.co.za' ],
            'subdirectory kept'           => [ 'https://example.com/ninja', 'https://example.com/ninja' ],
            'port kept'                   => [ 'http://192.168.1.10:8000/', 'http://192.168.1.10:8000' ],
        ];
    }

    public function testDetectsTokensEncryptedByVersion2()
    {
        $this->options['invoiceninja_api_token'] = 'in1:abcd1234:ZW5jcnlwdGVk';
        $this->assertTrue( BaseApi::hasEncryptedToken() );

        $this->options['invoiceninja_api_token'] = 'plaintexttoken';
        $this->assertFalse( BaseApi::hasEncryptedToken() );
    }

    public function testNoRequestWithoutToken()
    {
        $this->assertNull( BaseApi::sendRequest( 'products' ) );
        $this->assertSame( [], $this->requests );
    }

    public function testEncryptedTokenIsNeverSent()
    {
        $this->options['invoiceninja_api_token'] = 'in1:abcd1234:ZW5jcnlwdGVk';

        $this->assertNull( BaseApi::sendRequest( 'products' ) );
        $this->assertSame( [], $this->requests );
    }

    public function testSendsAuthenticatedRequestToNormalizedUrl()
    {
        $this->options['invoiceninja_api_token'] = 'secret-token';
        $this->options['invoiceninja_api_url'] = 'https://invoice.example.com/api/v1/';
        $this->respondWith( [ 'data' => [ 'ok' => true ] ] );

        $body = BaseApi::sendRequest( 'clients', 'POST', [ 'name' => 'Acme' ] );

        $this->assertSame( '{"data":{"ok":true}}', $body );
        $this->assertSame( 'https://invoice.example.com/api/v1/clients', $this->requests[0]['url'] );
        $args = $this->requests[0]['args'];
        $this->assertSame( 'POST', $args['method'] );
        $this->assertSame( 'secret-token', $args['headers']['X-API-TOKEN'] );
        $this->assertSame( 'WordPress', $args['headers']['X-CLIENT-PLATFORM'] );
        $this->assertSame( '{"name":"Acme"}', $args['body'] );
    }

    public function testErrorResponsesReturnNull()
    {
        $this->options['invoiceninja_api_token'] = 'secret-token';
        $this->respondWith( [ 'message' => 'Invalid token' ], 403 );

        $this->assertNull( BaseApi::sendRequest( 'products' ) );
    }

    public function testTransportErrorsReturnNull()
    {
        $this->options['invoiceninja_api_token'] = 'secret-token';
        $this->responder = function () {
            return new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
        };

        $this->assertNull( BaseApi::sendRequest( 'products' ) );
    }
}
