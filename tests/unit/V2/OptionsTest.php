<?php

namespace InvoiceNinja\Tests\V2;

use Brain\Monkey\Functions;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Tests\TestCase;

class OptionsTest extends TestCase
{
    public function testDefaultsWhenNothingIsStored()
    {
        $options = new Options();
        $this->assertSame( 'info', $options->setting( 'log_level' ) );
        $this->assertSame( 'instant', $options->setting( 'notifications.leads' ) );
        $this->assertNull( $options->setting( 'nope.nothing' ) );
    }

    public function testSaveKeepsKnownKeysAndTypes()
    {
        $options = new Options();
        $saved   = $options->saveSettings( [
            'log_retention_days' => '45',
            'delete_data_on_uninstall' => 'true',
            'modules'            => [ 'store' => 0, 'unknown' => true ],
            'notifications'      => [ 'recipients' => '  a@x.com ' ],
            'evil'               => 'drop me',
        ] );

        $this->assertSame( 45, $saved['log_retention_days'] );
        $this->assertTrue( $saved['delete_data_on_uninstall'] );
        $this->assertFalse( $saved['modules']['store'] );
        $this->assertArrayNotHasKey( 'unknown', $saved['modules'] );
        $this->assertArrayNotHasKey( 'evil', $saved );
        $this->assertSame( 'a@x.com', $saved['notifications']['recipients'] );
        $this->assertTrue( $saved['modules']['account'], 'untouched keys keep their values' );
    }

    public function testPruneCountersWalksEveryBatchNotJustTheFirst5000()
    {
        global $wpdb;
        $hour  = (int) floor( time() / HOUR_IN_SECONDS );
        $names = [ 'invoiceninja_settings', 'invoiceninja_ctr_keep_' . $hour, 'invoiceninja_ctrl_other_1' ];
        for ( $i = 0; $i < 2600; $i++ ) {
            $names[] = sprintf( 'invoiceninja_ctr_a%05d_%d', $i, $hour - 5 );
        }
        // The current hour's counters sort first, so an unordered LIMIT would never reach the old ones.
        for ( $i = 0; $i < 1200; $i++ ) {
            $names[] = sprintf( 'invoiceninja_ctr_0now%05d_%d', $i, $hour );
        }
        $wpdb = new class( $names ) {
            public $options = 'wp_options';
            public $rows;
            public $queries = 0;
            public function __construct( $names ) { $this->rows = array_fill_keys( $names, '1' ); }
            public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
            public function prepare( $query, ...$args ) { return [ 'q' => $query, 'a' => ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args ]; }
            public function get_col( $p ) {
                ++$this->queries;
                [ $like, $after ] = $p['a'];
                $prefix = stripcslashes( rtrim( $like, '%' ) );
                $names  = array_filter( array_keys( $this->rows ), fn( $n ) => str_starts_with( $n, $prefix ) && strcmp( $n, $after ) > 0 );
                sort( $names, SORT_STRING );
                return array_slice( $names, 0, 1000 );
            }
            public function query( $p ) {
                foreach ( $p['a'] as $name ) {
                    unset( $this->rows[ $name ] );
                }
                return true;
            }
        };
        Functions\when( 'wp_cache_delete' )->justReturn( true );

        $fake = $wpdb;
        try {
            ( new Options() )->pruneCounters();
        } finally {
            $wpdb = null;
        }

        $left = array_keys( $fake->rows );

        $this->assertSame( [], preg_grep( '/_' . ( $hour - 5 ) . '$/', $left ), 'every old counter is gone' );
        $this->assertCount( 1203, $left, 'current counters and other options stay' );
        $this->assertContains( 'invoiceninja_settings', $left );
        $this->assertGreaterThanOrEqual( 4, $fake->queries, 'in batches' );
    }
}
