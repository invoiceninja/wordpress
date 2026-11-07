<?php

namespace InvoiceNinja\Tests\V2;

use Brain\Monkey\Functions;
use InvoiceNinja\Catalog\ProductSync;
use InvoiceNinja\Catalog\Visibility;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Plugin;
use InvoiceNinja\Subscriptions\SubscriptionSync;
use InvoiceNinja\Tests\TestCase;

/**
 * Products and subscriptions can be hidden from the site without touching Invoice Ninja.
 */
class VisibilityTest extends TestCase
{
    /** @var array<int, array{type: string, status: string, meta: array<string, string>}> */
    private array $posts = [];

    private Visibility $visibility;

    protected function set_up(): void
    {
        parent::set_up();
        $this->posts      = [
            1 => [ 'type' => ProductSync::POST_TYPE, 'status' => 'publish', 'meta' => [] ],
            2 => [ 'type' => ProductSync::POST_TYPE, 'status' => 'private', 'meta' => [] ],
            3 => [ 'type' => ProductSync::POST_TYPE, 'status' => 'trash', 'meta' => [] ],
            4 => [ 'type' => 'page', 'status' => 'publish', 'meta' => [] ],
        ];
        $this->visibility = new Visibility();
        Functions\when( 'get_post_status' )->alias( fn( $id ) => $this->posts[ $id ]['status'] ?? false );
        Functions\when( 'get_post_type' )->alias( fn( $id ) => $this->posts[ $id ]['type'] ?? false );
        Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->posts[ $id ]['meta'][ $key ] ?? '' );
        Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) {
            $this->posts[ $id ]['meta'][ $key ] = $value;
            return true;
        } );
        Functions\when( 'delete_post_meta' )->alias( function ( $id, $key ) {
            unset( $this->posts[ $id ]['meta'][ $key ] );
            return true;
        } );
        // Like WordPress, a status change fires transition_post_status.
        Functions\when( 'wp_update_post' )->alias( function ( $data ) {
            $id  = (int) $data['ID'];
            $old = $this->posts[ $id ]['status'];
            $this->posts[ $id ]['status'] = $data['post_status'];
            $this->visibility->statusChanged( $data['post_status'], $old, $this->post( $id ) );
            return $id;
        } );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'remove_query_arg' )->alias( fn( $keys, $url ) => $url );
        Functions\when( 'add_query_arg' )->alias( fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );
    }

    private function post( int $id ): \WP_Post
    {
        return new \WP_Post( [ 'ID' => $id, 'post_type' => $this->posts[ $id ]['type'], 'post_status' => $this->posts[ $id ]['status'] ] );
    }

    private function manually( int $id, string $status ): void
    {
        $old                          = $this->posts[ $id ]['status'];
        $this->posts[ $id ]['status'] = $status;
        $this->visibility->statusChanged( $status, $old, $this->post( $id ) );
    }

    public function testHideMakesADraftAndShowPutsTheStatusBack()
    {
        $this->assertTrue( $this->visibility->hide( 1 ) );
        $this->assertSame( 'draft', $this->posts[1]['status'] );
        $this->assertTrue( Visibility::hidden( 1 ) );
        $this->assertTrue( $this->visibility->hide( 1 ), 'hiding twice is fine' );
        $this->assertSame( 'publish', $this->posts[1]['meta'][ ProductSync::HIDDEN_META ] );

        $this->assertTrue( $this->visibility->show( 1 ) );
        $this->assertSame( 'publish', $this->posts[1]['status'] );
        $this->assertFalse( Visibility::hidden( 1 ) );

        $this->visibility->hide( 2 );
        $this->visibility->show( 2 );
        $this->assertSame( 'private', $this->posts[2]['status'], 'a private product comes back private' );
    }

    public function testTrashedProductsAndOtherPostTypesAreLeftAlone()
    {
        $this->assertFalse( $this->visibility->hide( 3 ) );
        $this->assertFalse( $this->visibility->hide( 4 ) );
        $this->assertSame( 'publish', $this->posts[4]['status'] );
        $this->assertSame( [], $this->posts[4]['meta'] );
    }

    public function testSettingDraftByHandHidesAndPublishingShows()
    {
        $this->manually( 1, 'draft' );
        $this->assertSame( 'publish', $this->posts[1]['meta'][ ProductSync::HIDDEN_META ], 'the sync now keeps it a draft' );
        $this->manually( 1, 'publish' );
        $this->assertFalse( Visibility::hidden( 1 ) );
    }

    public function testTheSyncsOwnStatusChangesDontCount()
    {
        ProductSync::quietly( fn() => wp_update_post( [ 'ID' => 1, 'post_status' => 'draft' ] ) ); // Archived in Invoice Ninja
        $this->assertFalse( Visibility::hidden( 1 ) );
        $this->assertFalse( ProductSync::isQuiet() );
    }

    public function testBulkActionsCountWhatTheyChanged()
    {
        $this->assertSame( 'edit.php?invoiceninja_hid=2', $this->visibility->handleBulk( 'edit.php', 'invoiceninja_hide', [ 1, 2, 3 ] ) );
        $this->assertSame( 'edit.php?invoiceninja_hid=0', $this->visibility->handleBulk( 'edit.php', 'invoiceninja_hide', [ 1 ] ), 'already hidden' );
        $this->assertSame( 'edit.php?invoiceninja_showed=2', $this->visibility->handleBulk( 'edit.php', 'invoiceninja_show', [ 1, 2, 3 ] ) );
        $this->assertSame( 'edit.php', $this->visibility->handleBulk( 'edit.php', 'trash', [ 1 ] ) );
    }

    public function testHiddenSubscriptionsAreLeftOutOfWhatTheSiteOffers()
    {
        $plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
        ( function () {
            $this->options = new Options();
        } )->call( $plugin );
        $sync = new SubscriptionSync( $plugin );
        $this->options['invoiceninja_subscriptions_cache'] = [
            'at'    => time(),
            'items' => [
                'A' => [ 'id' => 'A', 'archived' => false ],
                'B' => [ 'id' => 'B', 'archived' => false ],
                'C' => [ 'id' => 'C', 'archived' => true ],
            ],
        ];

        $sync->setHidden( 'B', true );
        $this->assertSame( [ 'A' ], array_keys( $sync->shown() ) );
        $this->assertNull( $sync->findShown( 'B' ) );
        $this->assertNotNull( $sync->find( 'B' ), "customers' own subscriptions still resolve" );

        $sync->setHidden( 'B', false );
        $this->assertSame( [ 'A', 'B' ], array_keys( $sync->shown() ) );
    }
}
