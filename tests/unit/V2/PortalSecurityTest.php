<?php

namespace {
    // Minimal stand-ins for the WordPress classes the portal code touches.
    if ( ! class_exists( 'WP_User' ) ) {
        class WP_User
        {
            public $ID = 0;
            public $user_email = '';
            public $first_name = '';
            public $last_name = '';
            public $roles = array();

            public function __construct( $id = 0, $email = '' )
            {
                $this->ID         = $id;
                $this->user_email = $email;
            }
        }
    }
    if ( ! class_exists( 'WP_REST_Response' ) ) {
        class WP_REST_Response
        {
            private $data;
            private $status;

            public function __construct( $data = null, $status = 200 )
            {
                $this->data   = $data;
                $this->status = $status;
            }

            public function get_data()
            {
                return $this->data;
            }

            public function get_status()
            {
                return $this->status;
            }
        }
    }
    if ( ! class_exists( 'WP_REST_Request' ) ) {
        class WP_REST_Request
        {
            private $params;

            public function __construct( array $params = array() )
            {
                $this->params = $params;
            }

            public function get_param( $key )
            {
                return $this->params[ $key ] ?? null;
            }

            public function get_params()
            {
                return $this->params;
            }
        }
    }
}

namespace InvoiceNinja\Tests\V2 {

    use Brain\Monkey\Filters;
    use Brain\Monkey\Functions;
    use InvoiceNinja\Connection\ConnectionState;
    use InvoiceNinja\Connection\Credentials;
    use InvoiceNinja\Core\Logger;
    use InvoiceNinja\Core\Mailer;
    use InvoiceNinja\Core\Options;
    use InvoiceNinja\Core\Secrets;
    use InvoiceNinja\Customers\ClientResolver;
    use InvoiceNinja\Plugin;
    use InvoiceNinja\Portal\Account;
    use InvoiceNinja\Portal\Links;
    use InvoiceNinja\Portal\Users;
    use InvoiceNinja\Portal\Verification;
    use InvoiceNinja\Tests\TestCase;

    /**
     * A1, A4, A9, A10: links trusted only for the email they were made for, the bulk verify
     * nonce, Pay Invoice abuse protection and draft or deleted documents.
     */
    class PortalSecurityTest extends TestCase
    {
        /** @var array<int, \WP_User> */
        private array $users = [];

        /** @var array<int, array<string, mixed>> */
        private array $meta = [];

        /** @var array<string, array{0:int,1:array}> "METHOD path" => [code, body] */
        private array $routes = [];

        private Plugin $plugin;

        protected function set_up(): void
        {
            parent::set_up();
            global $wpdb;
            $wpdb = new class {
                public $prefix = 'wp_';
                public function insert() { return 1; }
            };
            $this->users = [ 7 => new \WP_User( 7, 'mallory@x.com' ) ];
            $this->meta  = [];
            Functions\when( 'get_userdata' )->alias( fn( $id ) => $this->users[ (int) $id ] ?? false );
            Functions\when( 'get_user_meta' )->alias( fn( $id, $key ) => $this->meta[ (int) $id ][ $key ] ?? '' );
            Functions\when( 'update_user_meta' )->alias( function ( $id, $key, $value ) {
                $this->meta[ (int) $id ][ $key ] = $value;
                return true;
            } );
            Functions\when( 'delete_user_meta' )->alias( function ( $id, $key ) {
                $had = isset( $this->meta[ (int) $id ][ $key ] );
                unset( $this->meta[ (int) $id ][ $key ] );
                return $had;
            } );
            Functions\when( 'nocache_headers' )->justReturn( null );
            Functions\when( 'wp_validate_auth_cookie' )->justReturn( false );

            $this->routes    = [];
            $this->responder = function ( $url, $args ) {
                $path = preg_replace( '#^https://in\.test/api/v1/|\?.*$#', '', $url );
                [ $code, $body ] = $this->routes[ $args['method'] . ' ' . $path ] ?? [ 404, [ 'message' => 'Not found' ] ];
                return [ 'response' => [ 'code' => $code ], 'body' => json_encode( $body ), 'headers' => [ 'x-app-version' => '5.13.52' ] ];
            };

            $this->plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
            $options      = new Options();
            $secrets      = new Secrets( $options );
            $credentials  = new Credentials( $options, $secrets );
            $credentials->save( 'https://in.test', 'token-123456789' );
            $plugin = $this->plugin;
            ( function () use ( $options, $secrets, $credentials, $plugin ) {
                $this->options     = $options;
                $this->secrets     = $secrets;
                $this->credentials = $credentials;
                $this->state       = new ConnectionState( $options );
                $this->logger      = new Logger( $options );
                $this->mailer      = new Mailer( $options );
                $this->clients     = new ClientResolver( $plugin );
            } )->call( $this->plugin );
        }

        private function client( string $id, string $contact_id, string $email, array $extra = [] ): array
        {
            return $extra + [ 'id' => $id, 'is_deleted' => false, 'contacts' => [ [ 'id' => $contact_id, 'email' => $email, 'link' => 'https://in.test/client/key_login/' . $contact_id ] ] ];
        }

        // ---------- A1: links ----------

        public function testALinkIsOnlyMadeForTheAccountsOwnEmail()
        {
            $links = new Links( $this->plugin );

            $this->assertFalse( $links->link( 7, 'victim@x.com', 'CL_VICTIM', 'CV' ), "a billing email that isn't the account's never links" );
            $this->assertNull( $links->get( 7 ) );
            $this->assertTrue( $links->link( 7, ' Mallory@X.com ', 'CL_M', 'CM' ) );
            $this->assertSame( [ 'client_id' => 'CL_M', 'contact_id' => 'CM' ], $links->get( 7 ) );
            $this->assertFalse( $links->link( 7, 'mallory@x.com', 'CL_OTHER', 'CO' ), 'a trusted link is never replaced' );
        }

        public function testALinkStopsCountingOnceTheEmailChanges()
        {
            // Registered with the victim's email (unverified), so a guest order linked it...
            $this->users[7]->user_email = 'victim@x.com';
            $links = new Links( $this->plugin );
            $links->link( 7, 'victim@x.com', 'CL_VICTIM', 'CV' );
            $this->assertNotNull( $links->get( 7 ) );

            // ...then the email is changed to one the attacker can verify.
            $old                        = clone $this->users[7];
            $this->users[7]->user_email = 'mallory@x.com';
            $this->assertNull( $links->get( 7 ), 'a link made for another email is ignored' );

            ( new Verification( $this->plugin ) )->emailChanged( 7, $old );
            $this->assertArrayNotHasKey( Links::CLIENT, $this->meta[7] ?? [], 'and cleared when the email changes' );
            $this->assertArrayNotHasKey( Links::CONTACT, $this->meta[7] ?? [] );
            $this->assertArrayNotHasKey( Links::EMAIL, $this->meta[7] ?? [] );
        }

        public function testRawLinksWithoutTheEmailHashAreNotTrusted()
        {
            $this->meta[7] = [ Links::CLIENT => 'CL_VICTIM', Links::CONTACT => 'CV' ];
            $links         = new Links( $this->plugin );

            $this->assertNull( $links->get( 7 ) );
            $this->assertTrue( $links->clearStale( 7 ) );
            $this->assertSame( [], $this->meta[7] );
        }

        public function testDiscoverLooksTheClientUpByTheCurrentEmail()
        {
            $this->meta[7]              = [ Links::CLIENT => 'CL_VICTIM', Links::CONTACT => 'CV' ];
            $this->routes['GET clients'] = [ 200, [ 'data' => [ $this->client( 'CL_M', 'CM', 'mallory@x.com' ) ] ] ];

            $link = ( new Links( $this->plugin ) )->discover( 7 );

            $this->assertSame( [ 'client_id' => 'CL_M', 'contact_id' => 'CM' ], $link, "the old client is replaced by the new email's" );
            $this->assertStringContainsString( 'email=mallory%40x.com', implode( ' ', $this->requestedUrls() ) );
        }

        public function testConfirmedChecksTheContactStillUsesTheEmail()
        {
            $links = new Links( $this->plugin );
            $links->link( 7, 'mallory@x.com', 'CL_M', 'CM' );
            $this->routes['GET clients/CL_M'] = [ 200, [ 'data' => $this->client( 'CL_M', 'CM', 'mallory@x.com' ) ] ];

            $confirmed = $links->confirmed( 7 );
            $this->assertSame( 'CL_M', $confirmed['client_id'] );
            $this->assertSame( 'https://in.test/client/key_login/CM', $confirmed['contact']['link'] );

            // The business changed the contact's email in Invoice Ninja: no longer this user's.
            $this->routes['GET clients/CL_M'] = [ 200, [ 'data' => $this->client( 'CL_M', 'CM', 'someone.else@x.com' ) ] ];
            $this->routes['GET clients']      = [ 200, [ 'data' => [] ] ];
            $this->assertNull( $links->confirmed( 7 ) );
            $this->assertArrayNotHasKey( Links::CLIENT, $this->meta[7], 'the link is dropped' );
        }

        public function testConfirmedRelinksWhenTheClientWasDeleted()
        {
            $links = new Links( $this->plugin );
            $links->link( 7, 'mallory@x.com', 'CL_GONE', 'CG' );
            $this->routes['GET clients'] = [ 200, [ 'data' => [ $this->client( 'CL_NEW', 'CN', 'MALLORY@x.com' ) ] ] ];

            $confirmed = $links->confirmed( 7 );

            $this->assertSame( 'CL_NEW', $confirmed['client_id'] );
            $this->assertSame( [ 'client_id' => 'CL_NEW', 'contact_id' => 'CN' ], $links->get( 7 ) );
        }

        public function testMatchingContactRefusesDeletedAndOtherContacts()
        {
            $client = $this->client( 'CL', 'C1', 'a@x.com' );
            $this->assertNotNull( Links::matchingContact( $client, 'C1', 'A@x.com' ) );
            $this->assertNull( Links::matchingContact( $client, 'C2', 'a@x.com' ), 'another contact id' );
            $this->assertNull( Links::matchingContact( $client, 'C1', 'b@x.com' ), 'another email' );
            $this->assertNull( Links::matchingContact( $client, '', 'a@x.com' ) );
            $this->assertNull( Links::matchingContact( [ 'is_deleted' => true ] + $client, 'C1', 'a@x.com' ), 'deleted client' );
            $client['contacts'][0]['is_deleted'] = true;
            $this->assertNull( Links::matchingContact( $client, 'C1', 'a@x.com' ), 'deleted contact' );
        }

        // ---------- A4: bulk verify ----------

        public function testBulkVerifyNeedsTheBulkUsersNonce()
        {
            Functions\when( 'current_user_can' )->justReturn( true );
            Functions\when( 'wp_verify_nonce' )->alias( fn( $nonce, $action ) => 'good' === $nonce && 'bulk-users' === $action );
            Functions\when( 'wp_die' )->alias( function () {
                throw new \RuntimeException( 'died' );
            } );
            Functions\when( 'admin_url' )->alias( fn( $path ) => 'https://shop.example.com/wp-admin/' . $path );
            Functions\when( 'add_query_arg' )->alias( fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );
            $users = new Users( $this->plugin, new Verification( $this->plugin ), new Links( $this->plugin ) );

            $this->assertNull( $users->bulk( [ 'action' => 'delete', 'users' => [ 7 ] ] ), 'other actions are left to core' );
            try {
                $users->bulk( [ 'action' => 'invoiceninja_verify', 'users' => [ 7 ] ] );
                $this->fail( 'a forged link must not verify anyone' );
            } catch ( \RuntimeException $e ) {
                $this->assertSame( 'died', $e->getMessage() );
            }
            $this->assertArrayNotHasKey( Verification::AT, $this->meta[7] ?? [] );

            $next = $users->bulk( [ 'action' => '-1', 'action2' => 'invoiceninja_verify', 'users' => [ '7' ], '_wpnonce' => 'good' ] );
            $this->assertSame( 'https://shop.example.com/wp-admin/users.php?invoiceninja_verified=1', $next );
            $this->assertTrue( ( new Verification( $this->plugin ) )->isVerified( 7 ) );
        }

        // ---------- A9: Pay Invoice ----------

        private function payInvoice( array $params ): \WP_REST_Response
        {
            $account = new Account( $this->plugin, new Verification( $this->plugin ), new Links( $this->plugin ) );
            return $account->payInvoice( new \WP_REST_Request( $params ) );
        }

        private function form( int $ago = 60 ): string
        {
            return Account::formToken( time() - $ago, $this->plugin->secrets->get( 'secret' ) );
        }

        private function payable(): void
        {
            $this->routes['GET invoices']      = [ 200, [ 'data' => [ [
                'id' => 'INV1', 'number' => '0042', 'client_id' => 'CL1', 'balance' => 10, 'status_id' => '2', 'is_deleted' => false,
                'invitations' => [
                    [ 'client_contact_id' => 'OTHER', 'link' => 'https://in.test/client/invoice/OTHER' ],
                    [ 'client_contact_id' => 'C1', 'link' => 'https://in.test/client/invoice/MINE' ],
                ],
            ] ] ] ];
            $this->routes['GET clients/CL1'] = [ 200, [ 'data' => $this->client( 'CL1', 'C1', 'jane@x.com' ) ] ];
        }

        public function testFormTokensAreSigned()
        {
            $secret = $this->plugin->secrets->get( 'secret' );
            $token  = Account::formToken( 1700000000, $secret );

            $this->assertSame( 1700000000, Account::formTime( $token, $secret ) );
            $this->assertNull( Account::formTime( '1700000001.' . explode( '.', $token )[1], $secret ), 'time changed' );
            $this->assertNull( Account::formTime( $token, str_repeat( '0', 64 ) ), 'other secret' );
            $this->assertNull( Account::formTime( 'junk', $secret ) );
        }

        public function testPayInvoiceDropsBotsAndTooFastSubmits()
        {
            $this->payable();

            $trap = $this->payInvoice( [ 'number' => '0042', 'email' => 'jane@x.com', 'website' => 'spam', 'form' => $this->form(), 'elapsed' => 10 ] );
            $this->assertSame( [ 'ok' => true ], $trap->get_data(), 'the spam trap gets the usual reply' );

            $missing = $this->payInvoice( [ 'number' => '0042', 'email' => 'jane@x.com', 'website' => '', 'elapsed' => 10 ] );
            $this->assertSame( 400, $missing->get_status() );

            $fast = $this->payInvoice( [ 'number' => '0042', 'email' => 'jane@x.com', 'website' => '', 'form' => $this->form(), 'elapsed' => 1 ] );
            $this->assertSame( 422, $fast->get_status() );

            $fresh = $this->payInvoice( [ 'number' => '0042', 'email' => 'jane@x.com', 'website' => '', 'form' => $this->form( 0 ), 'elapsed' => 10 ] );
            $this->assertSame( 422, $fresh->get_status(), 'a form rendered a moment ago' );

            $this->assertSame( [], $this->requests, 'nothing is looked up' );
            $this->assertSame( [], $this->mails );
        }

        public function testPayInvoiceUsesAnExactNumberAndEmailsTheContactsOwnLink()
        {
            $this->payable();

            $reply = $this->payInvoice( [ 'number' => '0042', 'email' => 'Jane@x.com', 'website' => '', 'form' => $this->form(), 'elapsed' => 10 ] );

            $this->assertSame( [ 'ok' => true ], $reply->get_data() );
            $this->assertStringContainsString( 'number=0042', $this->requestedUrls()[0] );
            $this->assertStringNotContainsString( 'filter=', $this->requestedUrls()[0] );
            $this->assertCount( 1, $this->mails );
            $this->assertStringContainsString( 'invoice/MINE', $this->mails[0]['body'] );
            $this->assertStringNotContainsString( 'invoice/OTHER', $this->mails[0]['body'] );
        }

        public function testWrongGuessesDontUseUpTheCustomersEmails()
        {
            $this->payable();
            for ( $i = 0; $i < 5; $i++ ) {
                $this->payInvoice( [ 'number' => '9999', 'email' => 'jane@x.com', 'website' => '', 'form' => $this->form(), 'elapsed' => 10 ] );
            }
            $this->assertSame( [], $this->mails );

            $this->payInvoice( [ 'number' => '0042', 'email' => 'jane@x.com', 'website' => '', 'form' => $this->form(), 'elapsed' => 10 ] );
            $this->assertCount( 1, $this->mails, 'the real customer still gets their link' );
        }

        // ---------- A10: drafts and deleted documents ----------

        public function testOnlyTheClientsOwnSentDocumentsOpen()
        {
            $this->assertTrue( Account::opensFor( [ 'client_id' => 'CL1', 'status_id' => '2' ], 'CL1' ) );
            $this->assertTrue( Account::opensFor( [ 'client_id' => 'CL1', 'status_id' => 4 ], 'CL1' ) );
            $this->assertFalse( Account::opensFor( [ 'client_id' => 'CL1', 'status_id' => '1' ], 'CL1' ), 'draft' );
            $this->assertFalse( Account::opensFor( [ 'client_id' => 'CL1', 'status_id' => '2', 'is_deleted' => true ], 'CL1' ), 'deleted' );
            $this->assertFalse( Account::opensFor( [ 'client_id' => 'CL2', 'status_id' => '2' ], 'CL1' ), 'another client' );
            $this->assertFalse( Account::opensFor( [ 'client_id' => '', 'status_id' => '2' ], '' ) );
            $this->assertFalse( Account::opensFor( [ 'client_id' => 'CL1' ], 'CL1' ), 'no status' );
        }
    }
}
