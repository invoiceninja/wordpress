<?php

namespace InvoiceNinja\Tests\V2;

use Brain\Monkey\Functions;
use InvoiceNinja\Core\Queue;
use InvoiceNinja\Tests\TestCase;

/**
 * Network activation and deactivation reach every site, not only the current one.
 */
class QueueSitesTest extends TestCase
{
    public function testNetworkWideWorkVisitsEverySiteAndReturns()
    {
        $current = 1;
        $visited = [];
        $query   = null;
        Functions\when( 'is_multisite' )->justReturn( true );
        Functions\when( 'get_sites' )->alias( function ( $args ) use ( &$query ) {
            $query = $args;
            return [ 1, 2, 3 ];
        } );
        Functions\when( 'switch_to_blog' )->alias( function ( $id ) use ( &$current ) { $current = $id; return true; } );
        Functions\when( 'restore_current_blog' )->alias( function () use ( &$current ) { $current = 1; return true; } );

        Queue::eachSite( true, function () use ( &$visited, &$current ) { $visited[] = $current; } );
        $this->assertSame( [ 1, 2, 3 ], $visited );
        $this->assertSame( Queue::MAX_SITES, $query['number'], 'bounded' );
        $this->assertSame( 1, $current, 'back on the current site' );

        $visited = [];
        Queue::eachSite( false, function () use ( &$visited, &$current ) { $visited[] = $current; } );
        $this->assertSame( [ 1 ], $visited, 'deactivated on one site only' );
    }

    public function testASingleSiteRunsOnce()
    {
        $runs = 0;
        Queue::eachSite( true, function () use ( &$runs ) { ++$runs; } );
        $this->assertSame( 1, $runs );
    }
}
