<?php

namespace InvoiceNinja\Tests\V2;

use Brain\Monkey\Functions;
use InvoiceNinja\Rest\Routes;
use InvoiceNinja\Tests\TestCase;

class RoutesTest extends TestCase
{
    public function testOnlyAllowlistedRoutesArePublic()
    {
        $registered = [];
        Functions\when( 'register_rest_route' )->alias( function ( $ns, $path, $args ) use ( &$registered ) {
            $registered[] = [ $path, $args['methods'], $args['permission_callback'] ];
        } );
        $plugin = ( new \ReflectionClass( \InvoiceNinja\Plugin::class ) )->newInstanceWithoutConstructor();
        ( new Routes( $plugin ) )->register();

        $this->assertNotEmpty( $registered );
        Functions\when( 'current_user_can' )->justReturn( false );
        foreach ( $registered as [ $path, $method, $permission ] ) {
            if ( in_array( $path, Routes::PUBLIC_ROUTES, true ) ) {
                continue;
            }
            $this->assertNotSame( '__return_true', $permission, "$method $path must check a capability" );
            $this->assertFalse( call_user_func( $permission ), "$method $path must refuse a user without the capability" );
        }
    }
}
