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

    /** A wpdb stand-in for one option row; the replica answers 0 to SELECT LAST_INSERT_ID(). */
    private function splitDb( array $rows )
    {
        return new class( $rows ) {
            public $options = 'wp_options';
            public $rows;
            public $rows_affected = 0;
            public $selects = [];
            public function __construct( $rows ) { $this->rows = $rows; }
            public function prepare( $q, ...$a ) { return [ $q, $a ]; }
            public function query( $p ) {
                [ $q, $a ] = $p;
                $this->rows_affected = 0;
                if ( str_starts_with( $q, 'INSERT IGNORE' ) && ! isset( $this->rows[ $a[0] ] ) ) {
                    $this->rows[ $a[0] ]  = $a[1];
                    $this->rows_affected = 1;
                } elseif ( str_contains( $q, 'LAST_INSERT_ID(option_value + 1)' ) && isset( $this->rows[ $a[0] ] ) ) {
                    $this->rows[ $a[0] ]  = (string) ( (int) $this->rows[ $a[0] ] + 1 );
                    $this->rows_affected = 1;
                } elseif ( str_starts_with( $q, 'UPDATE' ) && 3 === count( $a ) && ( $this->rows[ $a[1] ] ?? null ) === $a[2] ) {
                    $this->rows[ $a[1] ]  = $a[0];
                    $this->rows_affected = 1;
                } elseif ( str_starts_with( $q, 'DELETE' ) && ( $this->rows[ $a[0] ] ?? null ) === $a[1] ) {
                    unset( $this->rows[ $a[0] ] );
                    $this->rows_affected = 1;
                }
                return $this->rows_affected;
            }
            public function get_var( $p ) {
                $this->selects[] = is_array( $p ) ? $p[0] : $p;
                if ( 'SELECT LAST_INSERT_ID()' === $p ) {
                    return '0'; // Routed to a replica: another connection.
                }
                return $this->rows[ $p[1][0] ] ?? null;
            }
        };
    }

    private function stubCache( array &$cache ): void
    {
        Functions\when( 'wp_cache_get' )->alias( function ( $k, $g ) use ( &$cache ) { return $cache[ $g . ':' . $k ] ?? false; } );
        Functions\when( 'wp_cache_set' )->alias( function ( $k, $v, $g ) use ( &$cache ) { $cache[ $g . ':' . $k ] = $v; return true; } );
        Functions\when( 'wp_cache_delete' )->alias( function ( $k, $g ) use ( &$cache ) { unset( $cache[ $g . ':' . $k ] ); return true; } );
    }

    public function testCountersStillCountWhenLastInsertIdGoesToAReplica()
    {
        global $wpdb;
        $cache = [];
        $this->stubCache( $cache );
        $wpdb = $this->splitDb( [] );
        try {
            $options = new Options();
            $this->assertSame( 1, $options->increment( 'ctr_x_1' ) );
            $this->assertSame( 2, $options->increment( 'ctr_x_1' ) );
            $this->assertSame( 3, $options->increment( 'ctr_x_1' ), 'not stuck at 1 when the replica says 0' );
            $this->assertSame( 4, Options::bump( 'invoiceninja_ctr_x_1' ) );
            $this->assertSame( 0, Options::bump( 'invoiceninja_missing' ), 'no row, no value' );
        } finally {
            $wpdb = null;
        }
    }

    public function testInsertIdIsReadFromTheConnectionThatRanTheUpdate()
    {
        $this->assertSame( 0, Options::insertId( new \stdClass() ), 'no connection' );
        $db      = new \stdClass();
        $db->dbh = 'not mysqli';
        $this->assertSame( 0, Options::insertId( $db ) );
    }

    public function testAddingACounterKeepsTheAutoloadedOptionsCache()
    {
        global $wpdb;
        $cache = [
            'options:alloptions' => [ 'blogname' => 'Shop' ],
            'options:notoptions' => [ 'invoiceninja_ctr_y_1' => true, 'other' => true ],
        ];
        $this->stubCache( $cache );
        $wpdb = $this->splitDb( [] );
        try {
            ( new Options() )->increment( 'ctr_y_1' );
        } finally {
            $wpdb = null;
        }
        $this->assertSame( [ 'blogname' => 'Shop' ], $cache['options:alloptions'], 'counters are not autoloaded' );
        $this->assertSame( [ 'other' => true ], $cache['options:notoptions'], 'only the new option is no longer "missing"' );
    }

    public function testLocksAreReplacedAndReleasedOnlyByTheirOwner()
    {
        global $wpdb;
        $cache = [];
        $this->stubCache( $cache );
        $wpdb = $this->splitDb( [ 'invoiceninja_lock' => 'a' ] );
        try {
            $options = new Options();
            $this->assertFalse( $options->replaceAtomic( 'lock', 'b', 'c' ) );
            $this->assertTrue( $options->replaceAtomic( 'lock', 'a', 'c' ) );
            $this->assertFalse( $options->deleteIf( 'lock', 'a' ), 'the old owner no longer holds it' );
            $this->assertSame( 'c', $wpdb->rows['invoiceninja_lock'] );
            $this->assertTrue( $options->deleteIf( 'lock', 'c' ) );
            $this->assertArrayNotHasKey( 'invoiceninja_lock', $wpdb->rows );
        } finally {
            $wpdb = null;
        }
    }
}
