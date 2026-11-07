<?php

namespace InvoiceNinja\Tests\V2;

use Brain\Monkey\Functions;
use InvoiceNinja\Api\BaseUrl;
use InvoiceNinja\Catalog\ProductSync;
use InvoiceNinja\Connection\ConnectionService;
use InvoiceNinja\Connection\ConnectionState;
use InvoiceNinja\Connection\Credentials;
use InvoiceNinja\Core\Environment;
use InvoiceNinja\Core\Installer;
use InvoiceNinja\Core\Logger;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Core\Queue;
use InvoiceNinja\Core\Secrets;
use InvoiceNinja\Frontend\Store;
use InvoiceNinja\Plugin;
use InvoiceNinja\Portal\ConfirmEmail;
use InvoiceNinja\Sync\Mapping;
use InvoiceNinja\Tests\TestCase;

/**
 * Updating live 1.0.16 sites to 2.0.1: hosted address, private hosts, failed upgrades,
 * staging guesses, products re-created by 1.x, display before the upgrade ran.
 */
class UpgradePathTest extends TestCase
{
    /** @var array<string, array{0:int,1:array}> "METHOD path" => [code, body] */
    private array $routes = [];

    /** @var array<string, string> in_id => wp_id written to the mapping table */
    private array $saved = [];

    /** @var array<int, string> post id => status */
    private array $posts = [];

    /** @var array<int, string> post id => product_id meta */
    private array $productIds = [];

    /** @var array<string, int> mapping in_id => wp_id */
    private array $mapped = [];

    /** @var list<array> wp_update_post calls */
    private array $updated = [];

    /** @var list<array> wp_insert_post calls */
    private array $inserted = [];

    private bool $multisite = false;

    protected function set_up(): void
    {
        parent::set_up();
        if ( ! defined( 'ARRAY_A' ) ) {
            define( 'ARRAY_A', 'ARRAY_A' );
        }
        $this->routes = [
            'POST companies/current' => [ 200, [ 'data' => [ 'id' => 'C1', 'settings' => [ 'name' => 'Larch & Pine', 'currency_id' => '1' ] ] ] ],
            'POST webhooks'          => [ 422, [ 'message' => 'The given data was invalid.' ] ],
            'GET company_gateways'   => [ 200, [ 'data' => [ [ 'label' => 'Stripe' ] ] ] ],
        ];
        $this->responder = function ( $url, $args ) {
            $path            = preg_replace( '#^https://(in\.test|invoicing\.co)/api/v1/|\?.*$#', '', $url );
            [ $code, $body ] = $this->routes[ $args['method'] . ' ' . $path ] ?? [ 404, [] ];
            return [ 'response' => [ 'code' => $code ], 'body' => json_encode( $body ), 'headers' => [ 'x-app-version' => '5.13.52' ] ];
        };
        $this->multisite = false;
        Functions\when( 'is_multisite' )->alias( fn() => $this->multisite );

        $this->saved      = [];
        $this->posts      = [];
        $this->productIds = [];
        $this->mapped     = [];
        $this->updated    = [];
        $this->inserted   = [];
        global $wpdb;
        $test  = $this;
        $wpdb = new class( $test ) {
            public $prefix   = 'wp_';
            public $posts    = 'wp_posts';
            public $postmeta = 'wp_postmeta';
            private $test;
            public function __construct( $test ) { $this->test = $test; }
            public function insert() { return 1; }
            public function get_charset_collate() { return ''; }
            public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
            public function query( $prepared ) {
                if ( is_array( $prepared ) && str_contains( $prepared[0], 'INSERT INTO wp_invoiceninja_map' ) ) {
                    $this->test->recordMapping( (string) $prepared[1][1], (int) $prepared[1][3] );
                }
                return 1;
            }
            public function get_results( $prepared ) { return $this->test->results( (string) $prepared[0] ); }
            public function get_col() { return []; }
            public function get_var() { return 1; }
        };
    }

    // ---------- fake database ----------

    public function recordMapping( string $in_id, int $wp_id ): void
    {
        $this->saved[ $in_id ]  = $wp_id;
        $this->mapped[ $in_id ] = $wp_id;
    }

    public function results( string $sql ): array
    {
        if ( str_contains( $sql, 'invoiceninja_map' ) ) {
            return array_map( fn( $in, $wp ) => [ 'in_id' => $in, 'wp_id' => $wp, 'hash' => 'h' ], array_keys( $this->mapped ), $this->mapped );
        }
        if ( str_contains( $sql, "meta_key = 'product_id'" ) ) {
            $newest = [];
            foreach ( $this->productIds as $post => $in ) {
                $newest[ $in ] = max( $newest[ $in ] ?? 0, $post );
            }
            return array_map( fn( $in, $id ) => [ 'in_id' => $in, 'id' => $id ], array_keys( $newest ), $newest );
        }
        if ( str_contains( $sql, 'SELECT ID, post_status' ) ) {
            return array_map( fn( $id, $status ) => [ 'ID' => $id, 'post_status' => $status ], array_keys( $this->posts ), $this->posts );
        }
        return [];
    }

    private function stubPosts(): void
    {
        Functions\when( 'get_post_status' )->alias( fn( $id ) => $this->posts[ (int) $id ] ?? false );
        Functions\when( 'get_post_type' )->alias( fn( $id ) => isset( $this->posts[ (int) $id ] ) ? ProductSync::POST_TYPE : false );
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
            return 'product_id' === $key ? ( $this->productIds[ (int) $id ] ?? '' ) : '';
        } );
        Functions\when( 'wp_update_post' )->alias( function ( $data ) {
            $this->updated[] = $data;
            if ( isset( $data['post_status'] ) ) {
                $this->posts[ (int) $data['ID'] ] = $data['post_status'];
            }
            return (int) $data['ID'];
        } );
        Functions\when( 'wp_insert_post' )->alias( function ( $data ) {
            $this->inserted[] = $data;
            return 99;
        } );
        Functions\when( 'wp_slash' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'has_post_thumbnail' )->justReturn( false );
        Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
        Functions\when( 'as_schedule_single_action' )->justReturn( 1 );
        Functions\when( 'as_get_scheduled_actions' )->justReturn( [] );
        Functions\when( 'get_posts' )->alias( function () {
            $out = [];
            foreach ( $this->posts as $id => $status ) {
                if ( 'trash' !== $status ) {
                    $out[] = (object) [ 'ID' => $id, 'post_status' => $status ];
                }
            }
            usort( $out, fn( $a, $b ) => $b->ID <=> $a->ID );
            return $out;
        } );
    }

    private function plugin( Options $options ): Plugin
    {
        $plugin  = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
        $secrets = new Secrets( $options );
        ( function () use ( $options, $secrets ) {
            $this->options     = $options;
            $this->secrets     = $secrets;
            $this->credentials = new Credentials( $options, $secrets );
            $this->state       = new ConnectionState( $options );
            $this->logger      = new Logger( $options );
            $this->queue       = new Queue( $this->logger );
        } )->call( $plugin );
        return $plugin;
    }

    private function installer( Options $options ): Installer
    {
        $secrets = new Secrets( $options );
        return new Installer( $options, $secrets, new Credentials( $options, $secrets ), new Logger( $options ) );
    }

    private function stubUpgrade(): void
    {
        // createTables() loads WordPress's upgrade.php; an empty stand-in is enough here.
        if ( ! is_file( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
            @mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
            file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', "<?php\n" );
        }
        Functions\when( 'dbDelta' )->justReturn( [] );
        Functions\when( 'delete_option' )->alias( function ( $name ) {
            unset( $this->options[ $name ] );
            return true;
        } );
    }

    /** A 1.0.16 site: plain-text token, migrations up to 9 done by an earlier 2.0 build. */
    private function legacySite( string $url = '' ): void
    {
        $this->options['invoiceninja_api_token']      = 'plain-1x-token';
        $this->options['invoiceninja_api_url']        = $url;
        $this->options['invoiceninja_profile']        = '{}';
        $this->options['invoiceninja_schema_version'] = 9;
    }

    // ---------- U1: blank address means hosted ----------

    public function testBlankAddressWithATokenMeansHosted()
    {
        $options = new Options();
        $creds   = new Credentials( $options, new Secrets( $options ) );
        $this->assertSame( '', $creds->url(), 'nothing saved: not configured' );
        $this->assertSame( 'none', $creds->source() );

        $this->options['invoiceninja_api_token'] = 'plain-1x-token';
        $this->assertSame( BaseUrl::HOSTED, $creds->url() );
        $this->assertSame( 'options', $creds->source() );

        $this->options['invoiceninja_api_url'] = 'https://billing.example.com';
        $this->assertSame( 'https://billing.example.com', $creds->url(), 'a saved address wins' );
    }

    public function testAPlainTextTokenIsEncryptedWhenRead()
    {
        $options = new Options();
        $creds   = new Credentials( $options, new Secrets( $options ) );
        $this->options['invoiceninja_api_token'] = 'plain-1x-token';

        $this->assertSame( 'plain-1x-token', $creds->token() );
        $this->assertStringStartsWith( 'in1:', $this->options['invoiceninja_api_token'], 're-entered in 1.0.16 after a downgrade: encrypted again' );
        $this->assertSame( 'plain-1x-token', $creds->token() );
    }

    public function testUpgradeFrom1xKeepsHostedAndEncryptsTheToken()
    {
        $this->stubUpgrade();
        $this->legacySite();
        $installer = $this->installer( new Options() );
        $installer->maybeUpgrade();

        $this->assertSame( BaseUrl::HOSTED, $this->options['invoiceninja_api_url'] );
        $this->assertStringStartsWith( 'in1:', $this->options['invoiceninja_api_token'] );
        $this->assertSame( '1.x', $this->options['invoiceninja_upgraded_from'] );
        $this->assertSame( Installer::SCHEMA, $this->options['invoiceninja_schema_version'] );
        $this->assertSame( INVOICENINJA_VERSION, $this->options['invoiceninja_version'] );
    }

    public function testResumeConnectsASiteThatNeverRanTheWizard()
    {
        $this->options['invoiceninja_api_token'] = 'plain-1x-token';
        $options = new Options();
        $state   = new ConnectionState( $options );
        $service = new ConnectionService( new Credentials( $options, new Secrets( $options ) ), $state, new Logger( $options ) );

        $this->assertSame( 'connected', $service->resume() );
        $this->assertTrue( $state->connected() );
        $this->assertSame( 'C1', $state->get()['company']['id'] );
        $this->assertStringStartsWith( 'https://invoicing.co/api/v1/', $this->requestedUrls()[0], 'blank address: hosted' );

        $this->assertSame( 'refreshed', $service->resume(), 'a known company is only refreshed' );
    }

    public function testResumeRecordsWhyItCouldNotConnect()
    {
        $this->options['invoiceninja_api_token'] = 'plain-1x-token';
        $this->routes['POST companies/current']  = [ 401, [ 'message' => 'Invalid token' ] ];
        $options = new Options();
        $state   = new ConnectionState( $options );
        $service = new ConnectionService( new Credentials( $options, new Secrets( $options ) ), $state, new Logger( $options ) );

        $this->assertSame( 'failed', $service->resume() );
        $this->assertSame( 'disconnected', $state->get()['status'] );
        $this->assertSame( 'auth', $state->get()['error']['kind'] );
    }

    // ---------- U2: private hosts ----------

    public function testUpgradeAllowsThePrivateHostChosenIn1x()
    {
        $this->stubUpgrade();
        $this->legacySite( 'http://192.168.1.5:8000/' );
        $this->installer( new Options() )->maybeUpgrade();

        $state = new ConnectionState( new Options() );
        $this->assertSame( '192.168.1.5', $state->get()['private_host'] );
        $this->assertTrue( $state->allowsPrivate( 'http://192.168.1.5:8000' ) );
    }

    public function testOnMultisiteOnlyANetworkAdminAllowsAPrivateHost()
    {
        $this->multisite = true;
        $this->stubUpgrade();
        $this->legacySite( 'http://192.168.1.5:8000/' );
        $this->installer( new Options() )->maybeUpgrade();

        $state = ( new ConnectionState( new Options() ) )->get();
        $this->assertSame( '', $state['private_host'] );
        $this->assertSame( 'private_host', $state['error']['kind'], 'admins see why' );
    }

    public function testAPublicHostIsNotAllowedPrivate()
    {
        $this->stubUpgrade();
        $this->legacySite( 'https://billing.example.com' );
        $this->installer( new Options() )->maybeUpgrade();

        $this->assertSame( '', ( new ConnectionState( new Options() ) )->get()['private_host'] );
    }

    public function testLegacyRequestsToABlockedPrivateHostAreReported()
    {
        $this->options['invoiceninja_api_url']   = 'http://192.168.1.5:8000';
        $this->options['invoiceninja_api_token'] = 'tok';
        $plugin = $this->plugin( new Options() );
        $instance = new \ReflectionProperty( Plugin::class, 'instance' );
        $instance->setAccessible( true );
        $instance->setValue( null, $plugin );
        try {
            $this->assertNull( \InvoiceNinja\Api\BaseApi::sendRequest( 'products' ) );
            $this->assertSame( [], $this->requests, 'nothing sent' );
            $this->assertSame( 'private_host', ( new ConnectionState( new Options() ) )->get()['error']['kind'], 'admins see why' );
            $this->assertArrayHasKey( 'invoiceninja_private_host_blocked', $this->transients, 'reported once an hour' );
        } finally {
            $instance->setValue( null, null );
        }
    }

    // ---------- U3: a failed upgrade never takes the site down ----------

    public function testAFailedUpgradeIsRecordedAndRetriedLater()
    {
        $this->stubUpgrade();
        $this->legacySite();
        $calls = 0;
        Functions\when( 'dbDelta' )->alias( function () use ( &$calls ) {
            ++$calls;
            throw new \RuntimeException( 'Table is full' );
        } );
        $installer = $this->installer( new Options() );

        $installer->maybeUpgrade();
        $this->assertSame( 1, $calls );
        $this->assertStringContainsString( 'Table is full', $installer->failure()['message'] );
        $this->assertArrayNotHasKey( 'invoiceninja_install_lock', $this->options, 'the lock is released' );
        $this->assertArrayNotHasKey( 'invoiceninja_version', $this->options );

        $installer->maybeUpgrade();
        $this->assertSame( 1, $calls, 'not tried again within ten minutes' );

        $this->options['invoiceninja_upgrade_failed']['at'] = time() - Installer::RETRY_AFTER - 1;
        Functions\when( 'dbDelta' )->justReturn( [] );
        $installer->maybeUpgrade();
        $this->assertSame( INVOICENINJA_VERSION, $this->options['invoiceninja_version'] );
        $this->assertNull( $installer->failure() );
        $this->assertArrayNotHasKey( 'invoiceninja_upgrade_failed', $this->options );
    }

    // ---------- U4: staging guess ----------

    /** @dataProvider hosts */
    public function testStagingHostLooksInFrontOfTheSitesOwnDomain( string $host, bool $staging )
    {
        $this->assertSame( $staging, Environment::stagingHost( $host ), $host );
    }

    public function hosts(): array
    {
        return [
            'staging sub'          => [ 'staging.example.com', true ],
            'dev sub'              => [ 'dev.example.com', true ],
            'nested staging'       => [ 'shop.staging.example.com', true ],
            'test sub co.uk'       => [ 'test.shop.example.co.uk', true ],
            'staging- first'       => [ 'staging-shop.example.com', true ],
            '-dev label'           => [ 'shop-dev.example.com', true ],
            'numbered'             => [ 'staging2.example.com', true ],
            'pantheon dev'         => [ 'dev-site.pantheonsite.io', true ],
            'pantheon test'        => [ 'test-site.pantheonsite.io', true ],
            'kinsta staging'       => [ 'staging-site-abc123.kinsta.cloud', true ],
            'wpengine staging'     => [ 'site.staging.wpengine.com', true ],
            'flywheel staging'     => [ 'shop.flywheelstaging.com', true ],
            'uppercase'            => [ 'STAGING.Example.com', true ],
            'word in a label'      => [ 'my-test-kitchen.com', false ],
            'word in www label'    => [ 'www.my-test-kitchen.com', false ],
            'dev- domain'          => [ 'dev-tools.com', false ],
            'dev- domain co.uk'    => [ 'dev-tools.co.uk', false ],
            'www dev- domain'      => [ 'www.dev-tools.com', false ],
            'domain is dev'        => [ 'shop.dev.com', false ],
            'stage in a label'     => [ 'backstage.example.com', false ],
            'test inside a word'   => [ 'contest.example.com', false ],
            'pantheon live'        => [ 'live-site.pantheonsite.io', false ],
            'plain'                => [ 'shop.example.com', false ],
            'bare domain'          => [ 'example.com', false ],
            'ip'                   => [ '203.0.113.5', false ],
        ];
    }

    public function testLiveShopsWithStagingWordsInTheirNameTakeOrders()
    {
        $this->home_url = 'https://dev-tools.com';
        $this->assertTrue( ( new Environment( new Options() ) )->writesAllowed() );
        $this->home_url = 'https://www.my-test-kitchen.com';
        $this->assertTrue( ( new Environment( new Options() ) )->looksLive() );
    }

    public function testUpgradeFrom1xConfirmsALiveSite()
    {
        $this->stubUpgrade();
        $this->legacySite();
        $this->home_url = 'https://shop.example.com';
        $this->installer( new Options() )->maybeUpgrade();

        $env = new Environment( new Options() );
        $this->assertNotNull( $env->state() );
        $this->assertTrue( $env->writesAllowed() );

        $this->home_url = 'https://staging.example.com';
        $this->assertFalse( $env->writesAllowed(), 'a staging clone is still paused' );
    }

    public function testUpgradeFrom1xLeavesAStagingAddressPaused()
    {
        $this->stubUpgrade();
        $this->legacySite();
        $this->home_url = 'https://staging.example.com';
        $this->installer( new Options() )->maybeUpgrade();

        $env = new Environment( new Options() );
        $this->assertNull( $env->state() );
        $this->assertFalse( $env->writesAllowed(), 'an admin allows orders' );
    }

    public function testALaterUpdateOfA1xSiteDoesNotAllowOrders()
    {
        // A 2.0.0 site that came from 1.x; its staging copy was paused by 2.0.0
        $this->stubUpgrade();
        $this->options['invoiceninja_version']        = '2.0.0';
        $this->options['invoiceninja_upgraded_from']  = '1.x';
        $this->options['invoiceninja_schema_version'] = 9;
        $this->options['invoiceninja_api_token']      = 'plain-1x-token';
        $this->home_url                               = 'https://staging-shop.example.com';
        $this->installer( new Options() )->maybeUpgrade();

        $this->assertNull( ( new Environment( new Options() ) )->state() );
    }

    public function testThe201ConfirmationOfAStagingCopyIsUndone()
    {
        $this->stubUpgrade();
        $this->options['invoiceninja_version']        = '2.0.1';
        $this->options['invoiceninja_upgraded_from']  = '1.x';
        $this->options['invoiceninja_schema_version'] = 10;
        $this->home_url                               = 'https://staging.example.com';
        ( new Environment( new Options() ) )->confirm( 0 ); // what 2.0.1's update step did
        $this->installer( new Options() )->maybeUpgrade();

        $env = new Environment( new Options() );
        $this->assertNull( $env->state() );
        $this->assertFalse( $env->writesAllowed() );
    }

    /** @dataProvider keptConfirmations */
    public function testOtherConfirmationsAreKept( string $url, int $by, string $from )
    {
        $this->stubUpgrade();
        $this->options['invoiceninja_version']        = '2.0.1';
        $this->options['invoiceninja_upgraded_from']  = $from;
        $this->options['invoiceninja_schema_version'] = 10;
        $this->home_url                               = $url;
        ( new Environment( new Options() ) )->confirm( $by );
        $this->installer( new Options() )->maybeUpgrade();

        $this->assertTrue( ( new Environment( new Options() ) )->writesAllowed() );
    }

    public function keptConfirmations(): array
    {
        return [
            'an admin allowed staging' => [ 'https://staging.example.com', 5, '1.x' ],
            'a live address'           => [ 'https://shop.example.com', 0, '1.x' ],
            'not from 1.x'             => [ 'https://staging.example.com', 0, '' ],
        ];
    }

    /** @dataProvider notLive */
    public function testUpgradeFrom1xLeavesCopiesPaused( string $url, string $type )
    {
        $this->stubUpgrade();
        $this->legacySite();
        $this->home_url         = $url;
        $this->environment_type = $type;
        $this->installer( new Options() )->maybeUpgrade();

        $this->assertNull( ( new Environment( new Options() ) )->state() );
    }

    public function notLive(): array
    {
        return [
            'staging type' => [ 'https://shop.example.com', 'staging' ],
            'localhost'    => [ 'http://localhost:8080', 'production' ],
            'private IP'   => [ 'http://192.168.1.20', 'production' ],
        ];
    }

    public function testAFresh2xSiteIsNotConfirmedByTheUpgrade()
    {
        $this->stubUpgrade();
        $this->options['invoiceninja_version']        = '2.0.0';
        $this->options['invoiceninja_schema_version'] = 9;
        $this->home_url                               = 'https://staging.example.com';
        $this->installer( new Options() )->maybeUpgrade();

        $this->assertNull( ( new Environment( new Options() ) )->state() );
    }

    // ---------- U5: products 1.x re-created after a downgrade ----------

    public function testSyncRelinksThePostThat1xRecreated()
    {
        $this->stubPosts();
        $this->options['invoiceninja_api_url']   = 'https://in.test';
        $this->options['invoiceninja_api_token'] = 'tok';
        $this->routes['GET products']            = [ 200, [ 'data' => [ [ 'id' => 'P1', 'product_key' => 'Widget', 'price' => 5 ] ], 'meta' => [ 'pagination' => [ 'total_pages' => 1 ] ] ] ];
        $this->mapped     = [ 'P1' => 10 ];      // 2.x's post, deleted by 1.0.16.
        $this->posts      = [ 25 => 'publish' ]; // 1.0.16's new post for the same product.
        $this->productIds = [ 25 => 'P1' ];

        $plugin = $this->plugin( new Options() );
        $result = ( new ProductSync( $plugin, new Mapping() ) )->run( true );

        $this->assertSame( 'synced', $result['status'] );
        $this->assertSame( [], $this->inserted, 'no duplicate post' );
        $this->assertSame( 25, $this->updated[0]['ID'] );
        $this->assertSame( 25, $this->saved['P1'] );
    }

    public function testSyncOneRelinksThePostThat1xRecreated()
    {
        $this->stubPosts();
        $this->mapped     = [ 'P1' => 10 ];
        $this->posts      = [ 25 => 'publish' ];
        $this->productIds = [ 25 => 'P1' ];
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
            public function query( $prepared ) {
                if ( str_contains( $prepared[0], 'INSERT INTO wp_invoiceninja_map' ) ) {
                    $this->test->recordMapping( (string) $prepared[1][1], (int) $prepared[1][3] );
                }
                return 1;
            }
            public function get_row( $prepared ) { return [ 'wp_id' => 10, 'hash' => 'h', 'wp_type' => 'post' ]; }
            public function get_results( $prepared ) { return $this->test->results( (string) $prepared[0] ); }
            public function get_col() { return []; }
        };

        $plugin = $this->plugin( new Options() );
        $result = ( new ProductSync( $plugin, new Mapping() ) )->syncOne( [ 'id' => 'P1', 'product_key' => 'Widget', 'price' => 5 ] );

        $this->assertSame( 'updated', $result );
        $this->assertSame( [], $this->inserted );
        $this->assertSame( 25, $this->saved['P1'] );
    }

    public function testLinkLegacyPostsRelinksMissingPostsAndLeavesGoodLinksAlone()
    {
        $this->stubPosts();
        $this->mapped     = [ 'P1' => 10, 'P2' => 20 ];
        $this->posts      = [ 20 => 'publish', 21 => 'publish', 23 => 'publish', 25 => 'publish' ];
        $this->productIds = [ 20 => 'P2', 21 => 'P2', 23 => 'P1', 25 => 'P1' ];

        $plugin = $this->plugin( new Options() );
        $linked = ( new ProductSync( $plugin, new Mapping() ) )->linkLegacyPosts();

        $this->assertSame( 1, $linked );
        $this->assertSame( [ 'P1' => 25 ], $this->saved, 'the newest 1.x post' );
        $this->assertSame( 'draft', $this->posts[23], 'an older 1.x copy is unpublished' );
        $this->assertSame( 'publish', $this->posts[21], 'a product that is still linked is left alone' );
    }

    // ---------- U6: confirm the email without an Account page ----------

    public function testConfirmEmailLinkKeepsOnlyPagesOnThisSite()
    {
        Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
        Functions\when( 'wp_validate_redirect' )->alias( fn( $url, $fallback ) => str_starts_with( $url, 'https://shop.example.com/' ) ? $url : $fallback );

        $this->assertStringContainsString( 'invoiceninja=verify', ConfirmEmail::url( 'https://shop.example.com/product/widget/' ) );
        $this->assertSame( 'https://shop.example.com/product/widget/', ConfirmEmail::back( 'https://shop.example.com/product/widget/' ) );
        $this->assertSame( '', ConfirmEmail::back( 'https://evil.example.net/' ) );
        $this->assertSame( '', ConfirmEmail::back( '' ) );
    }

    // ---------- U7: 1.x display before the upgrade ran ----------

    public function testA1xSiteKeepsItsDisplayBeforeTheUpgradeRuns()
    {
        Functions\when( 'apply_filters' )->returnArg( 2 );
        $plugin = $this->plugin( new Options() );
        $store  = new Store( $plugin );
        $this->assertFalse( $store->legacy(), 'a fresh site uses blocks' );

        $this->options['invoiceninja_online_purchases'] = 'multiple';
        $this->assertTrue( $store->legacy(), 'a 1.x site whose upgrade has not run yet' );

        $this->options['invoiceninja_version'] = '2.0.1';
        $this->assertFalse( $store->legacy(), 'after the upgrade the setting decides' );
    }
}
