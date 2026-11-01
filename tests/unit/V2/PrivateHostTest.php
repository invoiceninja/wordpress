<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Api\ApiException;
use InvoiceNinja\Api\ErrorKind;
use InvoiceNinja\Api\HttpClient;
use InvoiceNinja\Connection\ConnectionService;
use InvoiceNinja\Connection\ConnectionState;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Tests\TestCase;

class PrivateHostTest extends TestCase
{
    /** @dataProvider addresses */
    public function testPrivateIp( string $ip, bool $private )
    {
        $this->assertSame( $private, HttpClient::privateIp( $ip ), $ip );
    }

    public function addresses(): array
    {
        return [
            'loopback'            => [ '127.0.0.1', true ],
            'rfc1918'             => [ '10.0.0.1', true ],
            'mapped loopback'     => [ '::ffff:127.0.0.1', true ],
            'mapped private'      => [ '[::ffff:10.0.0.1]', true ],
            'shared CGNAT'        => [ '100.64.1.1', true ],
            'benchmark'           => [ '198.18.0.5', true ],
            'this network'        => [ '0.1.2.3', true ],
            'ipv6 loopback'       => [ '::1', true ],
            'unique local'        => [ 'fd00:ec2::254', true ],
            'link local'          => [ 'fe80::1', true ],
            'nat64'               => [ '64:ff9b::a00:1', true ],
            'documentation v6'    => [ '2001:db8::1', true ],
            'garbage'             => [ 'not-an-ip', true ],
            'public v4'           => [ '8.8.8.8', false ],
            'public v6'           => [ '2606:4700:4700::1111', false ],
            'public mapped'       => [ '::ffff:8.8.8.8', false ],
        ];
    }

    public function testLocalhostNamesArePrivate()
    {
        $this->assertTrue( HttpClient::isPrivate( 'localhost' ) );
        $this->assertTrue( HttpClient::isPrivate( 'in.localhost' ) );
        $this->assertTrue( HttpClient::isPrivate( '[::ffff:127.0.0.1]' ) );
    }

    public function testPrivatePermissionIsPerHost()
    {
        $state = new ConnectionState( new Options() );
        $state->markConnected( [ 'id' => 'C1' ], '5.13.52', true, true, [], 'billing.lan' );

        $this->assertTrue( $state->allowsPrivate( 'http://billing.lan:8000' ) );
        $this->assertTrue( $state->allowsPrivate( 'http://BILLING.lan' ) );
        $this->assertFalse( $state->allowsPrivate( 'http://other.lan' ) );
        $this->assertFalse( $state->allowsPrivate( '' ) );
    }

    public function testCompanyChangeIsRefused()
    {
        $this->expectException( ApiException::class );
        try {
            ( new \ReflectionClass( ConnectionService::class ) )->newInstanceWithoutConstructor()
                ->guardCompany( [ 'company' => [ 'id' => 'C1' ] ], [ 'id' => 'C2' ] );
        } catch ( ApiException $e ) {
            $this->assertSame( ErrorKind::CompanyChanged, $e->kind );
            throw $e;
        }
    }

    public function testSameCompanyIsFine()
    {
        ( new \ReflectionClass( ConnectionService::class ) )->newInstanceWithoutConstructor()
            ->guardCompany( [ 'company' => [ 'id' => 'C1' ] ], [ 'id' => 'C1' ] );
        $this->addToAssertionCount( 1 );
    }
}
