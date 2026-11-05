<?php

namespace InvoiceNinja\Tests\V2;

use Brain\Monkey\Functions;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Secrets;
use InvoiceNinja\Plugin;
use InvoiceNinja\Portal\Links;
use InvoiceNinja\Portal\UserData;
use InvoiceNinja\Portal\Verification;
use InvoiceNinja\Tests\TestCase;

/**
 * User meta is shared by a multisite network; links and verification are per site.
 */
class UserDataTest extends TestCase
{
    /** @var array<int, array<string, list<string>>> user id => key => values */
    private array $meta = [];

    private bool $multisite = false;

    private int $blog = 1;

    protected function set_up(): void
    {
        parent::set_up();
        $this->meta      = [];
        $this->multisite = false;
        $this->blog      = 1;
        Functions\when( 'is_multisite' )->alias( fn() => $this->multisite );
        Functions\when( 'get_user_meta' )->alias( function ( $id, $key, $single = false ) {
            $values = $this->meta[ $id ][ $key ] ?? [];
            return $single ? ( $values[0] ?? '' ) : $values;
        } );
        Functions\when( 'update_user_meta' )->alias( function ( $id, $key, $value ) {
            $this->meta[ $id ][ $key ] = [ (string) $value ];
            return true;
        } );
        Functions\when( 'delete_user_meta' )->alias( function ( $id, $key ) {
            $had = isset( $this->meta[ $id ][ $key ] );
            unset( $this->meta[ $id ][ $key ] );
            return $had;
        } );
        Functions\when( 'get_userdata' )->alias( fn( $id ) => 7 === (int) $id ? (object) [ 'ID' => 7, 'user_email' => 'jo@example.com' ] : false );
        global $wpdb;
        $test = $this;
        $wpdb = new class( $test ) {
            private $test;
            public function __construct( $test ) { $this->test = $test; }
            public function get_blog_prefix() { return $this->test->prefix(); }
        };
    }

    protected function tear_down(): void
    {
        global $wpdb;
        $wpdb = null;
        parent::tear_down();
    }

    public function prefix(): string
    {
        return 1 === $this->blog ? 'wp_' : 'wp_' . $this->blog . '_';
    }

    /** A site: its own options (and so its own secret). */
    private function site( int $blog ): Verification
    {
        $this->blog = $blog;
        static $secrets = [];
        $secrets[ $blog ] = $secrets[ $blog ] ?? str_repeat( dechex( $blog ), 64 );
        $this->options['invoiceninja_secret'] = $secrets[ $blog ];
        $options = new Options();
        $plugin  = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
        ( function () use ( $options ) {
            $this->options = $options;
            $this->secrets = new Secrets( $options );
        } )->call( $plugin );
        return new Verification( $plugin );
    }

    public function testASingleSiteKeepsItsKeys()
    {
        $this->site( 1 )->mark( 7, 'code' );
        $this->assertArrayHasKey( Verification::AT, $this->meta[7], 'no migration on a single site' );
        $this->assertTrue( $this->site( 1 )->isVerified( 7 ) );
        $this->site( 1 )->clear( 7 );
        $this->assertSame( [], $this->meta[7] );
    }

    public function testEachSiteOfANetworkKeepsItsOwnVerification()
    {
        $this->multisite = true;
        $this->site( 2 )->mark( 7, 'code' );
        $this->site( 3 )->mark( 7, 'code' );

        $this->assertTrue( $this->site( 2 )->isVerified( 7 ), 'site 3 no longer overwrites site 2' );
        $this->assertTrue( $this->site( 3 )->isVerified( 7 ) );
        $this->assertFalse( $this->site( 4 )->isVerified( 7 ) );
        $this->assertArrayHasKey( 'wp_2_' . Verification::AT, $this->meta[7] );

        $this->site( 2 )->clear( 7 );
        $this->assertFalse( $this->site( 2 )->isVerified( 7 ) );
        $this->assertTrue( $this->site( 3 )->isVerified( 7 ) );
    }

    public function testDataFromBefore202StillWorksOnTheSiteThatWroteIt()
    {
        $this->multisite = false;
        $this->site( 2 )->mark( 7, 'code' ); // 2.0.1 wrote unprefixed keys
        $this->multisite = true;

        $this->assertTrue( $this->site( 2 )->isVerified( 7 ) );
        $this->assertArrayHasKey( 'wp_2_' . Verification::EMAIL, $this->meta[7], 'adopted as this site\'s' );
        $this->assertFalse( $this->site( 3 )->isVerified( 7 ), "another site's hash doesn't match" );

        $this->site( 2 )->clear( 7 );
        $this->assertFalse( $this->site( 2 )->isVerified( 7 ), 'cleared: the old value is not read again' );
    }

    public function testErasureRemovesBothKeys()
    {
        $this->multisite = true;
        $this->blog      = 2;
        $this->meta[7]   = [ Links::CLIENT => [ 'C1' ], 'wp_2_' . Links::CLIENT => [ 'C2' ] ];
        $this->assertTrue( UserData::erase( 7, Links::CLIENT ) );
        $this->assertSame( [], $this->meta[7] );
    }

    public function testUserQueriesLookAtThisSitesKey()
    {
        $this->multisite = true;
        $this->blog      = 3;
        $query           = UserData::metaQuery( Links::CLIENT, 'C1' );
        $this->assertSame( 'OR', $query['relation'] );
        $this->assertSame( 'wp_3_' . Links::CLIENT, $query[0]['key'] );
        $this->assertSame( 'wp_3_' . Links::CLIENT, UserData::missingQuery( Links::CLIENT )[0]['key'] );
        $this->multisite = false;
        $this->assertSame( [ [ 'key' => Links::CLIENT, 'value' => 'C1' ] ], UserData::metaQuery( Links::CLIENT, 'C1' ) );
    }
}
