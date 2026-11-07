<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Connection\ConnectionService;
use InvoiceNinja\Connection\ConnectionState;
use InvoiceNinja\Connection\Credentials;
use InvoiceNinja\Core\Logger;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Secrets;
use InvoiceNinja\Tests\TestCase;

class ConnectionServiceTest extends TestCase
{
    /** @var array<string, array{0:int,1:array}> */
    private array $routes = [];

    private string $version = '5.13.52';

    protected function set_up(): void
    {
        parent::set_up();
        $this->routes = [
            'POST companies/current' => [ 200, [ 'data' => [ 'id' => 'C1', 'settings' => [ 'name' => 'Larch & Pine', 'postmark_secret' => 'nope', 'enable_client_portal_password' => false ] ] ] ],
            'POST webhooks'          => [ 422, [ 'message' => 'The given data was invalid.' ] ],
            'GET company_gateways'   => [ 200, [ 'data' => [ [ 'label' => 'Stripe' ] ] ] ],
        ];
        $this->responder = function ( $url, $args ) {
            $path = preg_replace( '#^https://in\.test/api/v1/|\?.*$#', '', $url );
            [ $code, $body ] = $this->routes[ $args['method'] . ' ' . $path ] ?? [ 404, [] ];
            return [ 'response' => [ 'code' => $code ], 'body' => json_encode( $body ), 'headers' => [ 'x-app-version' => $this->version ] ];
        };
    }

    private function service(): ConnectionService
    {
        $options = new Options();
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public function insert() { return 1; }
        };
        return new ConnectionService( new Credentials( $options, new Secrets( $options ) ), new ConnectionState( $options ), new Logger( $options ) );
    }

    private function statuses( array $result ): array
    {
        return array_column( $result['checks'], 'status', 'id' );
    }

    public function testHappyPath()
    {
        $result = $this->service()->test( 'in.test', 'tok' );

        $this->assertTrue( $result['ok'] );
        $this->assertSame( [ 'server' => 'pass', 'tls' => 'pass', 'token' => 'pass', 'admin' => 'pass', 'version' => 'pass', 'webhooks' => 'pass' ], $this->statuses( $result ) );
        $this->assertSame( 'Larch & Pine', $result['company']['name'] );
        $this->assertArrayNotHasKey( 'settings', $result['company'], 'only the allowlist is kept' );
        $this->assertSame( [ 'Stripe' ], $result['gateways'] );
    }

    /** @dataProvider nonAdminReplies */
    public function testNonAdminToken( int $code )
    {
        $this->routes['POST webhooks'] = [ $code, [ 'message' => 'This action is unauthorized.' ] ];
        $result = $this->service()->test( 'in.test', 'tok' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'non_admin', $result['error']['kind'], 'not "invalid token"' );
        $this->assertSame( 'fail', $this->statuses( $result )['admin'] );
        $this->assertSame( 'pass', $this->statuses( $result )['token'] );
    }

    public function nonAdminReplies(): array
    {
        return [
            'Invoice Ninja 5.13 (401)' => [ 401 ],
            'older (403)'              => [ 403 ],
        ];
    }

    public function testOldVersion()
    {
        $this->version = '5.12.8';
        $result        = $this->service()->test( 'in.test', 'tok' );

        $this->assertSame( 'version_too_old', $result['error']['kind'] );
        $this->assertSame( 'fail', $this->statuses( $result )['version'] );
    }

    public function testFreePlan()
    {
        $this->routes['POST companies/current'] = [ 403, [ 'message' => 'Feature not available with free / unpaid account.' ] ];
        $result = $this->service()->test( 'in.test', 'tok' );

        $this->assertSame( 'plan_required', $result['error']['kind'] );
        $this->assertSame( 'pass', $this->statuses( $result )['server'] );
        $this->assertSame( 'fail', $this->statuses( $result )['token'] );
    }

    public function testNoGatewayAndPortalPasswordAreWarnings()
    {
        $this->routes['GET company_gateways'] = [ 200, [ 'data' => [] ] ];
        $this->routes['POST companies/current'][1]['data']['settings']['enable_client_portal_password'] = true;
        $result = $this->service()->test( 'in.test', 'tok' );

        $this->assertTrue( $result['ok'] );
        $this->assertContains( 'no_gateway', $result['warnings'] );
        $this->assertContains( 'portal_password', $result['warnings'] );
    }

    public function testConnectSavesEncryptedAndAsksBeforeSwitchingCompany()
    {
        $service = $this->service();
        $result  = $service->connect( 'in.test', 'tok-1234567890' );

        $this->assertTrue( $result['saved'] );
        $this->assertStringStartsWith( 'in1:', $this->options['invoiceninja_api_token'] );
        $this->assertSame( 'https://in.test', $this->options['invoiceninja_api_url'] );

        $this->routes['POST companies/current'][1]['data']['id'] = 'C2';
        $switch = $service->connect( 'in.test', 'tok-other' );
        $this->assertFalse( $switch['saved'] );
        $this->assertSame( 'Larch & Pine', $switch['needs_switch_confirmation']['from'] );

        $this->assertTrue( $service->connect( 'in.test', 'tok-other', false, true )['saved'] );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testATokenInWpConfigAloneLocksTheConnection()
    {
        // Otherwise an address typed in Settings is paired with the token from wp-config.php
        define( 'INVOICENINJA_API_TOKEN', 'token-from-wp-config' );
        $options = new Options();
        $this->assertTrue( ( new Credentials( $options, new Secrets( $options ) ) )->locked() );
        $this->assertFalse( $this->service()->connect( 'https://evil.example', 'typed-token-1234' )['saved'] );
        $this->assertArrayNotHasKey( 'invoiceninja_api_url', $this->options );
    }
}
