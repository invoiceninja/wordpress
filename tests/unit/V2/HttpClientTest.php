<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Tests\TestCase;

class HttpClientTest extends TestCase
{
    private function client(): HttpClient
    {
        return new HttpClient( 'https://in.test', 'secret-token-value' );
    }

    private function reply( int $code, $body, array $headers = [] ): void
    {
        $this->responder = function () use ( $code, $body, $headers ) {
            return [ 'response' => [ 'code' => $code ], 'body' => is_string( $body ) ? $body : json_encode( $body ), 'headers' => $headers + [ 'x-app-version' => '5.13.52' ] ];
        };
    }

    private function kindOf( callable $call ): ErrorKind
    {
        try {
            $call();
        } catch ( ApiException $e ) {
            $this->assertStringNotContainsString( 'secret-token-value', $e->getMessage() . $e->technical );
            return $e->kind;
        }
        $this->fail( 'expected an ApiException' );
    }

    public function testSendsSafeRequests()
    {
        $this->reply( 200, [ 'data' => [ 'id' => 'x' ] ] );
        $response = $this->client()->get( 'products', [ 'per_page' => 2 ] );

        $this->assertSame( [ 'id' => 'x' ], $response->data() );
        $request = $this->requests[0];
        $this->assertSame( 'https://in.test/api/v1/products?per_page=2', $request['url'] );
        $this->assertSame( 'secret-token-value', $request['args']['headers']['X-API-TOKEN'] );
        $this->assertSame( 'WordPress', $request['args']['headers']['X-CLIENT-PLATFORM'] );
        $this->assertSame( 0, $request['args']['redirection'], 'never follow redirects' );
        $this->assertTrue( $request['args']['sslverify'] );
    }

    public function testRecordsVersion()
    {
        $this->reply( 200, [ 'data' => [] ] );
        $client = $this->client();
        $client->get( 'ping' );
        $this->assertSame( '5.13.52', $client->appVersion() );
    }

    /** @dataProvider statuses */
    public function testMapsStatuses( int $code, array $body, array $headers, ErrorKind $kind )
    {
        $this->reply( $code, $body, $headers );
        $this->assertSame( $kind, $this->kindOf( fn() => $this->client()->post( 'invoices', [ 'a' => 1 ] ) ) );
    }

    public function statuses(): array
    {
        return [
            'invalid token'   => [ 403, [ 'message' => 'Invalid token' ], [], ErrorKind::Auth ],
            'user inactive'   => [ 403, [ 'message' => 'User inactive' ], [], ErrorKind::Auth ],
            'locked'          => [ 403, [ 'message' => 'User access locked' ], [], ErrorKind::Auth ],
            'free plan'       => [ 403, [ 'message' => 'Feature not available with free / unpaid account.' ], [], ErrorKind::PlanRequired ],
            'other 403'       => [ 403, [ 'message' => 'This action is unauthorized.' ], [], ErrorKind::Forbidden ],
            'not found'       => [ 404, [], [], ErrorKind::NotFound ],
            'validation'      => [ 422, [ 'message' => 'The given data was invalid.', 'errors' => [ 'email' => [ 'bad' ] ] ], [], ErrorKind::Validation ],
            'duplicate'       => [ 429, [ 'message' => 'Duplicate request' ], [], ErrorKind::Duplicate ],
            'throttled'       => [ 429, [ 'message' => 'Too Many Attempts.' ], [ 'retry-after' => '30' ], ErrorKind::RateLimited ],
            'server'          => [ 500, [], [], ErrorKind::Server ],
            'redirect'        => [ 301, [], [ 'location' => 'https://www.in.test/api/v1/x' ], ErrorKind::Redirect ],
        ];
    }

    public function testNonJsonSuccessIsInvalid()
    {
        $this->reply( 200, '<html>not ninja</html>' );
        $this->assertSame( ErrorKind::InvalidResponse, $this->kindOf( fn() => $this->client()->get( 'x' ) ) );
    }

    /** @dataProvider transportErrors */
    public function testMapsTransportErrors( string $message, ErrorKind $kind )
    {
        $this->responder = fn() => new \WP_Error( 'http_request_failed', $message );
        $this->assertSame( $kind, $this->kindOf( fn() => $this->client()->post( 'x' ) ) );
    }

    public function transportErrors(): array
    {
        return [
            [ 'cURL error 6: Could not resolve host: in.test', ErrorKind::Dns ],
            [ 'cURL error 60: SSL certificate problem: certificate has expired', ErrorKind::Tls ],
            [ 'cURL error 28: Operation timed out after 15000 milliseconds', ErrorKind::Timeout ],
            [ 'cURL error 7: Failed to connect to in.test port 443', ErrorKind::Transport ],
        ];
    }

    public function testRetriesIdempotentCallsOnly()
    {
        $calls           = 0;
        $this->responder = function () use ( &$calls ) {
            $calls++;
            return $calls === 1
                ? [ 'response' => [ 'code' => 503 ], 'body' => '{}', 'headers' => [] ]
                : [ 'response' => [ 'code' => 200 ], 'body' => '{"data":[]}', 'headers' => [] ];
        };
        $this->client()->get( 'products' );
        $this->assertSame( 2, $calls, 'GET is retried once after a 503' );

        $calls = 0;
        $this->kindOf( fn() => $this->client()->post( 'invoices', [ 'x' => 1 ] ) );
        $this->assertSame( 1, $calls, 'POST is never retried' );
    }

    public function testRefusesPrivateHostsUnlessAllowed()
    {
        $this->assertSame( ErrorKind::PrivateHost, $this->kindOf( fn() => ( new HttpClient( 'http://127.0.0.1:8000', 't' ) )->get( 'x' ) ) );
        $this->assertSame( [], $this->requests );

        $this->reply( 200, [ 'data' => [] ] );
        ( new HttpClient( 'http://127.0.0.1:8000', 't', true ) )->get( 'x' );
        $this->assertCount( 1, $this->requests );
    }

    public function testNotConfigured()
    {
        $this->assertSame( ErrorKind::NotConfigured, $this->kindOf( fn() => ( new HttpClient( 'https://in.test', '' ) )->get( 'x' ) ) );
    }

    public function testVersionAtLeast()
    {
        $this->assertTrue( HttpClient::versionAtLeast( '5.13.25' ) );
        $this->assertTrue( HttpClient::versionAtLeast( 'v5.14.0' ) );
        $this->assertFalse( HttpClient::versionAtLeast( '5.13.24' ) );
        $this->assertFalse( HttpClient::versionAtLeast( '' ) );
    }
}
