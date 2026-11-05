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

    public function testModuleRoutesNeedTheAllowlistToBeOpen()
    {
        $registered = [];
        Functions\when( 'register_rest_route' )->alias( function ( $ns, $path ) use ( &$registered ) {
            $registered[] = $path;
        } );
        $plugin = ( new \ReflectionClass( \InvoiceNinja\Plugin::class ) )->newInstanceWithoutConstructor();
        $routes = new Routes( $plugin );
        $routes->add( '/webhook', 'POST', '__return_true', 'public' );
        $routes->add( '/admin/anything', 'GET', '__return_true', 'manage' );
        $this->assertSame( [ '/webhook', '/admin/anything' ], $registered );

        // An add-on's mistake is skipped and reported, never thrown: this runs on rest_api_init,
        // so an exception would break every REST request (the block editor too).
        $wrong = [];
        Functions\when( '_doing_it_wrong' )->alias( function ( $function, $message ) use ( &$wrong ) {
            $wrong[] = $message;
        } );
        Functions\when( 'esc_html' )->returnArg();
        foreach ( [ 'public', 'same_site', 'logged_in', 'nobody' ] as $access ) {
            $routes->add( '/admin/secret', 'POST', '__return_true', $access );
        }
        $this->assertSame( [ '/webhook', '/admin/anything' ], $registered, 'and never registered' );
        $this->assertCount( 4, $wrong );
        $this->assertStringContainsString( 'PUBLIC_ROUTES', $wrong[0] );
    }

    public function testASkippedRouteIsLogged()
    {
        Functions\when( 'register_rest_route' )->justReturn( true );
        Functions\when( '_doing_it_wrong' )->justReturn( null );
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public $rows = [];
            public function insert( $table, $row ) {
                $this->rows[] = $row;
                return 1;
            }
        };
        $plugin = ( new \ReflectionClass( \InvoiceNinja\Plugin::class ) )->newInstanceWithoutConstructor();
        ( function () {
            $this->logger = new \InvoiceNinja\Core\Logger( new \InvoiceNinja\Core\Options() );
        } )->call( $plugin );
        ( new Routes( $plugin ) )->add( '/addon/open', 'POST', '__return_true', 'public' );
        $this->assertCount( 1, $wpdb->rows );
        $this->assertSame( 'error', $wpdb->rows[0]['level'] );
        $this->assertStringContainsString( '/addon/open', $wpdb->rows[0]['message'] );
    }

    /**
     * Every Routes::add() call in the modules: the open ones must be allowlisted (a module
     * route needs its whole plugin to register, so the calls are read from the source).
     */
    public function testEveryOpenModuleRouteIsAllowlisted()
    {
        $root  = dirname( __DIR__, 3 ) . '/src';
        $found = 0;
        $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
        foreach ( $files as $file ) {
            if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
                continue;
            }
            $source = (string) file_get_contents( $file->getPathname() );
            preg_match_all( "/->add\(\s*'([^']+)',\s*[^;]*?'(public|same_site|logged_in|view|manage|manage_site)'\s*\)/s", $source, $matches, PREG_SET_ORDER );
            foreach ( $matches as [ , $path, $access ] ) {
                ++$found;
                if ( in_array( $access, [ 'public', 'same_site', 'logged_in' ], true ) ) {
                    $path = str_replace( '\\\\', '\\', $path );
                    $this->assertContains( $path, Routes::PUBLIC_ROUTES, basename( $file->getPathname() ) . ": $path is $access but not allowlisted" );
                }
            }
        }
        $this->assertGreaterThan( 20, $found, 'the module routes were found' );
    }
}
