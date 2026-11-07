<?php

namespace InvoiceNinja\Tests\V2;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use InvoiceNinja\Catalog\ProductSync;
use InvoiceNinja\Connection\ConnectionState;
use InvoiceNinja\Connection\Credentials;
use InvoiceNinja\Core\Logger;
use InvoiceNinja\Core\Mailer;
use InvoiceNinja\Core\Notifications;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Queue;
use InvoiceNinja\Core\Secrets;
use InvoiceNinja\Plugin;
use InvoiceNinja\Sync\Mapping;
use InvoiceNinja\Tests\TestCase;

/**
 * The circuit breaker counts only changes shoppers notice; large catalogs sync in steps;
 * the lock belongs to the run that took it.
 */
class ProductSyncTest extends TestCase
{
    /** @var list<array<string, mixed>> Invoice Ninja's products */
    private array $catalog = [];

    /** @var array<string, array{wp_id: int, hash: string}> */
    private array $mapped = [];

    /** @var array<int, array{status: string, title: string, meta: array<string, string>}> */
    private array $posts = [];

    /** @var list<array{hook: string, args: array, at: int}> */
    private array $queued = [];

    private int $nextId = 100;

    protected function set_up(): void
    {
        parent::set_up();
        if ( ! defined( 'ARRAY_A' ) ) {
            define( 'ARRAY_A', 'ARRAY_A' );
        }
        $this->catalog = [];
        $this->mapped  = [];
        $this->posts   = [];
        $this->queued  = [];
        $this->nextId  = 100;
        $this->options['invoiceninja_api_url']   = 'https://in.test';
        $this->options['invoiceninja_api_token'] = 'tok';
        $this->responder = function ( $url ) {
            parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
            $per   = (int) ( $q['per_page'] ?? 250 );
            $page  = (int) ( $q['page'] ?? 1 );
            $items = array_slice( $this->catalog, ( $page - 1 ) * $per, $per );
            $body  = [ 'data' => $items, 'meta' => [ 'pagination' => [ 'total_pages' => max( 1, (int) ceil( count( $this->catalog ) / $per ) ) ] ] ];
            return [ 'response' => [ 'code' => 200 ], 'body' => json_encode( $body ), 'headers' => [ 'x-app-version' => '5.13.52' ] ];
        };

        global $wpdb;
        $test = $this;
        $wpdb = new class( $test ) {
            public $prefix   = 'wp_';
            public $posts    = 'wp_posts';
            public $postmeta = 'wp_postmeta';
            private $test;
            public function __construct( $test ) { $this->test = $test; }
            public function insert() { return 1; }
            public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
            public function query( $p ) {
                if ( is_array( $p ) && str_contains( $p[0], 'INSERT INTO wp_invoiceninja_map' ) ) {
                    $this->test->recordMapping( (string) $p[1][1], (int) $p[1][3], (string) $p[1][4] );
                }
                return 1;
            }
            public function get_results( $p ) { return $this->test->results( (string) $p[0] ); }
            public function get_var( $p ) { return $this->test->countMapped(); }
            public function get_col( $p ) { return $this->test->hiddenIds(); }
        };

        Functions\when( 'is_multisite' )->justReturn( false );
        Functions\when( 'wp_slash' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'has_post_thumbnail' )->justReturn( false );
        Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'https://shop.example.com/wp-admin/' . $p );
        Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->posts[ (int) $id ]['meta'][ $key ] ?? '' );
        Functions\when( 'update_post_meta' )->justReturn( true );
        Functions\when( 'wp_insert_post' )->alias( function ( $data ) {
            $id                 = $this->nextId++;
            $this->posts[ $id ] = [ 'status' => $data['post_status'], 'title' => $data['post_title'], 'meta' => $data['meta_input'] ];
            return $id;
        } );
        Functions\when( 'wp_update_post' )->alias( function ( $data ) {
            $id                          = (int) $data['ID'];
            $this->posts[ $id ]['title'] = $data['post_title'];
            $this->posts[ $id ]['meta']  = $data['meta_input'] + ( $this->posts[ $id ]['meta'] ?? [] );
            if ( isset( $data['post_status'] ) ) {
                $this->posts[ $id ]['status'] = $data['post_status'];
            }
            return $id;
        } );
        Functions\when( 'as_enqueue_async_action' )->alias( function ( $hook, $args ) {
            $this->queued[] = [ 'hook' => $hook, 'args' => $args, 'at' => 0 ];
            return 1;
        } );
        Functions\when( 'as_schedule_single_action' )->alias( function ( $at, $hook, $args ) {
            $this->queued[] = [ 'hook' => $hook, 'args' => $args, 'at' => $at ];
            return 1;
        } );
        Functions\when( 'as_get_scheduled_actions' )->justReturn( [] );
    }

    protected function tear_down(): void
    {
        global $wpdb;
        $wpdb = null;
        parent::tear_down();
    }

    // ---------- fake database ----------

    public function recordMapping( string $in_id, int $wp_id, string $hash ): void
    {
        $this->mapped[ $in_id ] = [ 'wp_id' => $wp_id, 'hash' => $hash ];
    }

    public function hiddenIds(): array
    {
        return array_keys( array_filter( $this->posts, fn( $p ) => '' !== (string) ( $p['meta'][ ProductSync::HIDDEN_META ] ?? '' ) ) );
    }

    public function countMapped(): int
    {
        return count( $this->mapped );
    }

    public function results( string $sql ): array
    {
        if ( str_contains( $sql, 'invoiceninja_map' ) ) {
            return array_map( fn( $in, $m ) => [ 'in_id' => $in, 'wp_id' => $m['wp_id'], 'hash' => $m['hash'] ], array_keys( $this->mapped ), $this->mapped );
        }
        if ( str_contains( $sql, 'SELECT ID, post_status' ) ) {
            return array_map( fn( $id, $p ) => [ 'ID' => $id, 'post_status' => $p['status'], 'post_title' => $p['title'], 'price' => $p['meta']['price'] ?? null ], array_keys( $this->posts ), $this->posts );
        }
        if ( str_contains( $sql, '_invoiceninja_sync_key' ) ) {
            $rows = [];
            foreach ( $this->posts as $id => $p ) {
                if ( isset( $p['meta']['_invoiceninja_sync_key'] ) ) {
                    $rows[] = [ 'post_id' => $id, 'meta_value' => $p['meta']['_invoiceninja_sync_key'] ];
                }
            }
            return $rows;
        }
        return [];
    }

    // ---------- helpers ----------

    private function products( int $count, float $price = 10, int $stock = 50 ): void
    {
        $this->catalog = [];
        for ( $i = 1; $i <= $count; $i++ ) {
            $this->catalog[] = [ 'id' => 'P' . $i, 'product_key' => 'Product ' . $i, 'notes' => 'About ' . $i, 'price' => $price, 'in_stock_quantity' => $stock, 'updated_at' => 1700000000 + $i ];
        }
    }

    private function sync(): ProductSync
    {
        $options = new Options();
        $plugin  = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
        $secrets = new Secrets( $options );
        ( function () use ( $options, $secrets ) {
            $this->options       = $options;
            $this->secrets       = $secrets;
            $this->credentials   = new Credentials( $options, $secrets );
            $this->state         = new ConnectionState( $options );
            $this->logger        = new Logger( $options );
            $this->queue         = new Queue( $this->logger );
            $this->notifications = new Notifications( $options, new Mailer( $options ), $this->logger );
        } )->call( $plugin );
        return new ProductSync( $plugin, new Mapping() );
    }

    // ---------- S4: the breaker counts what shoppers notice ----------

    public function testStockChangesNeverTripTheBreaker()
    {
        $this->products( 12 );
        $this->assertSame( 12, $this->sync()->run()['created'] );

        $this->products( 12, 10, 3 ); // Every product sold: Invoice Ninja's stock changed
        $result = $this->sync()->run();

        $this->assertSame( 'synced', $result['status'] );
        $this->assertSame( 12, $result['updated'] );
        $this->assertSame( '3', $this->posts[100]['meta']['in_stock_quantity'] );
        $this->assertArrayNotHasKey( 'invoiceninja_sync_paused', $this->options );
    }

    public function testStockChangesOnPostsSyncedBefore202DontTripItEither()
    {
        $this->products( 12 );
        $this->sync()->run();
        foreach ( $this->posts as &$post ) {
            unset( $post['meta']['_invoiceninja_sync_key'] ); // Written by 2.0.1
        }
        unset( $post );
        $this->products( 12, 10, 3 );

        $this->assertSame( 'synced', $this->sync()->run()['status'] );
        $this->assertNotEmpty( $this->posts[100]['meta']['_invoiceninja_sync_key'], 'the key is saved now' );
    }

    public function testPriceChangesTripItOnceAndAlertAdminsOnce()
    {
        $this->products( 12 );
        $this->sync()->run();
        $this->products( 12, 15 );

        $result = $this->sync()->run();
        $this->assertSame( 'paused', $result['status'] );
        $this->assertSame( '10', $this->posts[100]['meta']['price'], 'nothing changed' );
        $this->assertCount( 1, $this->mails );
        $this->assertStringContainsString( 'paused', $this->mails[0]['subject'] );
        $first = $this->options['invoiceninja_sync_paused']['at'];

        $this->options['invoiceninja_sync_paused']['at'] = $first - 3600;
        $this->assertSame( 'paused', $this->sync()->run()['status'], 'the hourly run checks again' );
        $this->assertCount( 1, $this->mails, 'one email per incident' );
        $this->assertSame( $first - 3600, $this->options['invoiceninja_sync_paused']['at'], 'the incident keeps its start' );

        $result = $this->sync()->run( true, true );
        $this->assertSame( 'synced', $result['status'] );
        $this->assertSame( '15', $this->posts[100]['meta']['price'] );
        $this->assertArrayNotHasKey( 'invoiceninja_sync_paused', $this->options, 'incident closed' );

        $this->products( 12, 20 );
        $this->sync()->run();
        $this->assertCount( 2, $this->mails, 'a new incident emails again' );
    }

    public function testSomePriceChangesAmongManyStockChangesPass()
    {
        $this->products( 12 );
        $this->sync()->run();
        $this->products( 12, 10, 1 );
        $this->catalog[0]['price'] = 11;
        $this->catalog[1]['product_key'] = 'Renamed';

        $this->assertSame( 'synced', $this->sync()->run()['status'], '2 of 12 noticeable changes' );
    }

    public function testNoticeableComparesNamePriceDescriptionAndPublishing()
    {
        $base = ProductSync::desired( [ 'id' => 'P1', 'product_key' => 'A', 'notes' => 'x', 'price' => 5, 'in_stock_quantity' => 1 ] );
        $this->assertFalse( ProductSync::noticeable( ProductSync::desired( [ 'id' => 'P1', 'product_key' => 'A', 'notes' => 'x', 'price' => 5, 'in_stock_quantity' => 9, 'cost' => 3 ] ), $base['key'], null ) );
        $this->assertTrue( ProductSync::noticeable( ProductSync::desired( [ 'id' => 'P1', 'product_key' => 'A', 'notes' => 'y', 'price' => 5 ] ), $base['key'], null ), 'description' );
        $this->assertTrue( ProductSync::noticeable( ProductSync::desired( [ 'id' => 'P1', 'product_key' => 'A', 'notes' => 'x', 'price' => 6 ] ), $base['key'], null ), 'price' );
        $this->assertTrue( ProductSync::noticeable( ProductSync::desired( [ 'id' => 'P1', 'product_key' => 'A', 'notes' => 'x', 'price' => 5, 'archived_at' => 1 ] ), $base['key'], null ), 'unpublished' );
        $post = [ 'status' => 'publish', 'title' => 'A', 'price' => '5' ];
        $this->assertFalse( ProductSync::noticeable( $base, '', $post ), 'no saved key: name, price and status match' );
        $this->assertTrue( ProductSync::noticeable( $base, '', [ 'price' => '4' ] + $post ) );
        $this->assertTrue( ProductSync::noticeable( $base, '', [ 'status' => 'draft' ] + $post ), 'published again' );
    }

    // ---------- S6: large catalogs sync in steps ----------

    public function testALargeCatalogSyncsInBatchesThatContinueInTheBackground()
    {
        Filters\expectApplied( 'invoiceninja_product_sync_batch' )->andReturn( 5 );
        $this->products( 12 );

        $result = $this->sync()->run( true );
        $this->assertSame( [ 'synced', 5, 7 ], [ $result['status'], $result['created'], $result['remaining'] ] );
        $this->assertSame( 1700000005, $this->options['invoiceninja_sync_cursor_products'], 'the cursor stops before the first product left' );
        $this->queued = array_values( array_filter( $this->queued, fn( $j ) => ProductSync::HOOK === $j["hook"] ) ); // not image downloads
        $this->assertCount( 1, $this->queued );
        $this->assertSame( ProductSync::HOOK, $this->queued[0]['hook'] );
        $this->assertSame( [ 'full' => true, 'continue' => true ], $this->queued[0]['args'][0]['payload'] );

        $busy = $this->sync()->run();
        $this->assertSame( 'busy', $busy['status'], 'the hourly run waits for the steps to finish' );
        $this->assertSame( 5, count( $this->mapped ) );

        // New products in the follow-ups count as changes, but the sync was already allowed
        $this->assertSame( 5, $this->sync()->run( true, false, true )['created'] );
        $last = $this->sync()->run( true, false, true );
        $this->assertSame( [ 'synced', 2, 0, 10 ], [ $last['status'], $last['created'], $last['remaining'], $last['unchanged'] ] );
        $this->assertArrayNotHasKey( 'invoiceninja_sync_continue', $this->options );
        $this->assertSame( 1700000012, $this->options['invoiceninja_sync_cursor_products'] );
        $this->assertTrue( $this->sync()->large(), 'more products than one run writes: Sync now queues it' );
        $this->assertArrayNotHasKey( 'invoiceninja_sync_paused', $this->options );
    }

    public function testALostFollowUpIsForgottenAfterAnHour()
    {
        $this->options['invoiceninja_sync_continue'] = [ 'at' => time() - 3700, 'full' => true, 'force' => false ];
        $this->products( 3 );
        $this->assertSame( 'synced', $this->sync()->run()['status'] );
    }

    // ---------- S6: the lock belongs to its run ----------

    public function testAFreshLockMeansBusyAndAStaleOneIsTakenOver()
    {
        $this->products( 2 );
        $this->options['invoiceninja_sync_lock'] = time() . ':other';
        $this->assertSame( 'busy', $this->sync()->run()['status'] );
        $this->assertSame( time() . ':other', $this->options['invoiceninja_sync_lock'], "another run's lock is left alone" );

        $this->options['invoiceninja_sync_lock'] = ( time() - ProductSync::LOCK_TTL - 1 ) . ':dead';
        $this->assertSame( 'synced', $this->sync()->run()['status'] );
        $this->assertArrayNotHasKey( 'invoiceninja_sync_lock', $this->options, 'released after the run' );
    }

    public function testARunOnlyReleasesItsOwnLock()
    {
        $this->products( 2 );
        // While this run works, its lock is taken over by another (it looked abandoned)
        Functions\when( 'wp_insert_post' )->alias( function ( $data ) {
            $this->options['invoiceninja_sync_lock'] = time() . ':second';
            $id                 = $this->nextId++;
            $this->posts[ $id ] = [ 'status' => $data['post_status'], 'title' => $data['post_title'], 'meta' => $data['meta_input'] ];
            return $id;
        } );
        $this->sync()->run();
        $this->assertSame( time() . ':second', $this->options['invoiceninja_sync_lock'] ?? null, "the second run's lock is kept" );
    }

    // ---------- products hidden from the site ----------

    public function testAHiddenProductStaysADraftAndRemembersItsStatus()
    {
        $this->products( 2 );
        $this->sync()->run();
        $this->posts[100]['status'] = 'draft';
        $this->posts[100]['meta'][ ProductSync::HIDDEN_META ] = 'publish';

        $this->catalog[0]['notes'] = 'New description';
        $this->sync()->run();
        $this->assertSame( 'draft', $this->posts[100]['status'], 'an update from Invoice Ninja keeps it hidden' );
        $this->assertSame( 'publish', $this->posts[100]['meta'][ ProductSync::HIDDEN_META ] );
        $this->assertSame( 'publish', $this->posts[101]['status'], 'others are untouched' );

        $this->catalog[0]['archived_at'] = 1700000100;
        $this->sync()->run();
        $this->assertSame( 'draft', $this->posts[100]['status'] );
        $this->assertSame( 'draft', $this->posts[100]['meta'][ ProductSync::HIDDEN_META ], 'archived in Invoice Ninja: shown again it stays a draft' );
    }

    public function testChangesToHiddenProductsDontTripTheBreaker()
    {
        $this->products( 12 );
        $this->sync()->run();
        foreach ( $this->posts as &$post ) {
            unset( $post['meta']['_invoiceninja_sync_key'] ); // Written by 2.0.1: compared by status too
            $post['status']                           = 'draft';
            $post['meta'][ ProductSync::HIDDEN_META ] = 'publish';
        }
        unset( $post );
        $this->products( 12, 15 );

        $this->assertSame( 'synced', $this->sync()->run()['status'] );
        $this->assertSame( '15', $this->posts[100]['meta']['price'] );
        $this->assertSame( 'draft', $this->posts[100]['status'] );
    }

    public function testShownStatusKeepsAnAdminsPrivateOrScheduledChoice()
    {
        $this->assertSame( 'publish', ProductSync::shownStatus( 'publish', 'publish' ) );
        $this->assertSame( 'private', ProductSync::shownStatus( 'publish', 'private' ) );
        $this->assertSame( 'draft', ProductSync::shownStatus( 'draft', 'private' ), 'archived wins' );
        $this->assertSame( 'publish', ProductSync::shownStatus( 'publish', 'draft' ), 'unarchived in Invoice Ninja' );
    }

    // ---------- discounts and the 1.x switch ----------

    public function testNegativePriceProductsAreNeverPublished()
    {
        $this->assertSame( 'draft', ProductSync::desired( [ 'id' => 'D1', 'product_key' => 'Discount', 'price' => -10 ] )['post']['post_status'] );
        $this->assertSame( 'publish', ProductSync::desired( [ 'id' => 'F1', 'product_key' => 'Free sample', 'price' => 0 ] )['post']['post_status'], 'free is fine' );

        $this->products( 2 );
        $this->catalog[] = [ 'id' => 'D1', 'product_key' => 'Discount', 'price' => -10, 'updated_at' => 1700000099 ];
        $this->assertSame( 2, $this->sync()->run()['created'], 'a new discount item is not created' );
        $this->catalog[0]['price'] = -5;
        $this->assertSame( 1, $this->sync()->run()['drafted'], 'one that becomes negative is unpublished' );
        $this->assertSame( 'draft', $this->posts[100]['status'] );
    }

    public function testSyncTurnedOffByTheLegacyPageIsReportedAndRepaired()
    {
        $this->options['invoiceninja_settings'] = [ 'modules' => [ 'store' => true ] ];
        $this->options['invoiceninja_sync_products'] = 0; // What saving the 1.x page wrote
        $sync = $this->sync();
        $this->assertFalse( $sync->enabled() );
        $this->assertTrue( $sync->offByLegacy() );

        $this->assertTrue( $sync->clearLegacySwitch() );
        $this->assertArrayNotHasKey( 'invoiceninja_sync_products', $this->options );
        $this->assertTrue( $sync->enabled(), 'the Store feature decides again' );
        $this->assertContains( ProductSync::HOOK, array_column( $this->queued, 'hook' ), 'and a sync runs' );
        $this->assertFalse( $sync->clearLegacySwitch(), 'nothing to do the second time' );

        $this->options['invoiceninja_sync_products'] = '1';
        $this->assertFalse( $sync->offByLegacy(), 'a 1.x site that syncs is left alone' );
        $this->options['invoiceninja_sync_products'] = 0;
        $this->options['invoiceninja_settings']      = [ 'modules' => [ 'store' => false ] ];
        $this->assertFalse( $sync->offByLegacy(), 'with the Store feature off, nothing should sync anyway' );
    }
}
