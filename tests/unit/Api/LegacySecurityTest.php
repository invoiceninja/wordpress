<?php

namespace InvoiceNinja\Tests\Api;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use InvoiceNinja\Api\ClientApi;
use InvoiceNinja\Api\InvoiceApi;
use InvoiceNinja\Api\ProfileApi;
use InvoiceNinja\Core\Installer;
use InvoiceNinja\Tests\TestCase;
use InvoiceNinja\WordPress\PostApi;

/**
 * A2, A3, A5, A6, A8: the 1.x code under 2.0's rules.
 */
class LegacySecurityTest extends TestCase
{
    /** A 1×1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function set_up(): void
    {
        parent::set_up();
        $this->options['invoiceninja_api_token'] = 'secret-token';
        Functions\when( 'sanitize_hex_color' )->alias( function ( $color ) {
            return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $color ) ? $color : null;
        } );
    }

    private function user( $id, $email )
    {
        return (object) [
            'ID' => $id, 'user_email' => $email, 'first_name' => 'Mal', 'last_name' => 'Lory', 'billing_phone' => '',
            'nickname' => 'mal', 'billing_address_1' => '', 'billing_address_2' => '', 'billing_city' => '',
            'billing_state' => '', 'billing_postcode' => '', 'roles' => [ 'customer' ],
        ];
    }

    private function verified( array $ids ): void
    {
        Filters\expectApplied( 'invoiceninja_is_verified' )->zeroOrMoreTimes()->andReturnUsing( function ( $verified, $user_id ) use ( $ids ) {
            return in_array( $user_id, $ids, true );
        } );
    }

    // ---------- A2: Buy Now and cart checkout ----------

    public function testUnverifiedBuyersAreSentToVerifyWithNoInvoice()
    {
        $this->verified( [] );
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'wp_get_current_user' )->justReturn( $this->user( 5, 'victim@x.com' ) );

        $this->assertSame( [ 'status' => 'verify', 'link' => '' ], InvoiceApi::purchase( [ 'P1' => 1 ] ) );
        $this->assertNull( InvoiceApi::create( [ 'P1' => 1 ] ) );
        $this->assertSame( [], $this->requests, "no client lookup, so nothing reveals or bills the email's client" );
    }

    public function testLoggedOutBuyersLogIn()
    {
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'wp_get_current_user' )->justReturn( (object) [ 'ID' => 0, 'user_email' => '' ] );

        $this->assertSame( 'login', InvoiceApi::purchase( [ 'P1' => 1 ] )['status'] );
    }

    public function testTheInvitationIsTheBuyersOwnContacts()
    {
        $invoice = json_decode( '{"invitations":[{"client_contact_id":"BOSS","link":"https://in/boss"},{"client_contact_id":"C2","link":"https://in/mine"}]}' );
        $client  = json_decode( '{"id":"CL","contacts":[{"id":"BOSS","email":"boss@x.com"},{"id":"C2","email":"Me@X.com"}]}' );

        $this->assertSame( 'https://in/mine', InvoiceApi::invitationLink( $invoice, $client, 'me@x.com' ) );
        $this->assertSame( '', InvoiceApi::invitationLink( $invoice, $client, 'stranger@x.com' ), 'never invitations[0]' );
        $client->contacts[1]->is_deleted = true;
        $this->assertSame( '', InvoiceApi::invitationLink( $invoice, $client, 'me@x.com' ) );
    }

    // ---------- A8: client sync never updates someone else's client ----------

    private function order( $email )
    {
        return new class( $email ) {
            private $email;
            public function __construct( $email ) { $this->email = $email; }
            public function get_billing_email() { return $this->email; }
            public function get_id() { return 12; }
            public function __call( $name, $args ) { return 'Mallory'; }
        };
    }

    private function puts(): array
    {
        return array_values( array_filter( $this->requests, function ( $r ) {
            return 'PUT' === $r['args']['method'];
        } ) );
    }

    public function testGuestOrdersNeverUpdateAnExistingClient()
    {
        $this->options['invoiceninja_match_found'] = 'update';
        $this->respondWith( [ 'data' => [ [ 'id' => 'CL_VICTIM', 'contacts' => [ [ 'id' => 'CV', 'email' => 'victim@x.com' ] ] ] ] ] );

        $client = ClientApi::exportOrder( $this->order( 'victim@x.com' ) );

        $this->assertSame( 'CL_VICTIM', $client->id );
        $this->assertSame( [], $this->puts() );
    }

    public function testOnlyAVerifiedOwnerUpdatesTheClient()
    {
        $this->options['invoiceninja_match_found'] = 'update';
        $this->respondWith( [ 'data' => [ [ 'id' => 'CL_VICTIM', 'contacts' => [ [ 'id' => 'CV', 'email' => 'victim@x.com' ] ] ] ] ] );
        $this->verified( [ 9 ] );

        ClientApi::exportUserFromOrder( $this->user( 5, 'victim@x.com' ), $this->order( 'victim@x.com' ) );
        $this->assertSame( [], $this->puts(), 'an account registered with the email but never verified' );

        ClientApi::exportUserFromOrder( $this->user( 9, 'mallory@x.com' ), $this->order( 'victim@x.com' ) );
        $this->assertSame( [], $this->puts(), "a verified account billing someone else's email" );

        ClientApi::exportUserFromOrder( $this->user( 9, 'victim@x.com' ), $this->order( 'victim@x.com' ) );
        $this->assertCount( 1, $this->puts(), 'the verified owner' );
    }

    public function testRegistrationNeverUpdatesTheClientUntilVerified()
    {
        $this->options['invoiceninja_match_found']   = 'update';
        $this->options['invoiceninja_included_roles'] = [ 'customer' ];
        $this->respondWith( [ 'data' => [ [ 'id' => 'CL_VICTIM', 'contacts' => [] ] ] ] );
        $this->verified( [ 9 ] );

        ClientApi::exportUser( $this->user( 5, 'victim@x.com' ) );
        $this->assertSame( [], $this->puts() );
        ClientApi::exportUser( $this->user( 9, 'victim@x.com' ) );
        $this->assertCount( 1, $this->puts() );
    }

    // ---------- A5: the stored company profile ----------

    public function testTheProfileKeepsOnlyWhatThe1xCodeReads()
    {
        $company = json_decode( json_encode( [
            'id' => 'CO1', 'company_key' => 'ck_secret', 'track_inventory' => true, 'portal_domain' => 'x',
            'settings' => [
                'name' => 'Larch & Pine', 'website' => 'https://larch.test', 'primary_color' => '#AABBCC',
                'currency_id' => '1', 'country_id' => '840', 'show_currency_code' => false,
                'postmark_secret' => 'pm_secret', 'mailgun_secret' => 'mg_secret', 'company_logo' => 'https://in/logo.png',
            ],
        ] ) );

        $profile = ProfileApi::reduce( $company );
        $json    = json_encode( $profile );

        $this->assertTrue( $profile->track_inventory );
        $this->assertSame( 'Larch & Pine', $profile->settings->name );
        $this->assertSame( '840', $profile->settings->country_id );
        foreach ( [ 'ck_secret', 'pm_secret', 'mg_secret', 'company_key', 'portal_domain', 'CO1' ] as $gone ) {
            $this->assertStringNotContainsString( $gone, $json );
        }
        $this->assertNull( ProfileApi::reduce( 'nope' ) );
    }

    public function testTheMigrationRewritesTheStoredProfile()
    {
        $this->options['invoiceninja_profile'] = json_encode( [ 'company_key' => 'ck_secret', 'settings' => [ 'name' => 'Old', 'mailgun_secret' => 'mg' ] ] );

        $this->assertTrue( Installer::reduceLegacyProfile() );
        $this->assertStringNotContainsString( 'secret', $this->options['invoiceninja_profile'] );
        $this->assertStringNotContainsString( 'company_key', $this->options['invoiceninja_profile'] );
        $this->assertSame( 'Old', json_decode( $this->options['invoiceninja_profile'] )->settings->name );
        $this->assertFalse( Installer::reduceLegacyProfile(), 'running it again changes nothing' );

        $this->options['invoiceninja_profile'] = '';
        $this->assertFalse( Installer::reduceLegacyProfile() );
    }

    // ---------- A6: the primary colour ----------

    public function testThePrimaryColourMustBeAHexColour()
    {
        $profile = json_decode( '{"settings":{"primary_color":"#123abc"}}' );
        $this->assertSame( '#123abc', PostApi::primaryColor( $profile ) );
        $profile->settings->primary_color = 'red;" onmouseover="alert(1)';
        $this->assertSame( '#0000EE', PostApi::primaryColor( $profile ) );
        $this->assertSame( '#0000EE', PostApi::primaryColor( null ) );
    }

    // ---------- A3: the company logo ----------

    public function testOnlyRealImagesAreLogos()
    {
        $this->assertSame( 'image/png', ProfileApi::imageType( base64_decode( self::PNG ) ) );
        $this->assertSame( '', ProfileApi::imageType( '<html><script>alert(1)</script></html>' ) );
        $this->assertSame( '', ProfileApi::imageType( '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>' ) );
        $this->assertSame( '', ProfileApi::imageType( '' ) );
    }

    private function logoStubs( $body, array &$fetched, array &$saved ): void
    {
        Functions\when( 'wp_remote_get' )->alias( function () {
            throw new \RuntimeException( 'only the allowed Invoice Ninja host may be fetched without the safe check' );
        } );
        Functions\when( 'wp_safe_remote_get' )->alias( function ( $url, $args ) use ( $body, &$fetched ) {
            $fetched[] = [ $url, $args ];
            return [ 'response' => [ 'code' => 200 ], 'body' => $body, 'headers' => [] ];
        } );
        Functions\when( 'wp_upload_bits' )->alias( function ( $name, $deprecated, $bits ) use ( &$saved ) {
            $saved[] = $name;
            return [ 'file' => '/tmp/uploads/' . $name, 'error' => false ];
        } );
        Functions\when( 'wp_check_filetype_and_ext' )->alias( function ( $file, $name ) {
            return [ 'type' => substr( $name, -4 ) === '.png' ? 'image/png' : false ];
        } );
        Functions\when( 'sanitize_file_name' )->returnArg();
        Functions\when( 'get_posts' )->justReturn( [] );
        Functions\when( 'wp_insert_attachment' )->justReturn( 31 );
        Functions\when( 'wp_delete_file' )->justReturn( true );
    }

    public function testTheLogoIsFetchedSafelyAndNamedByItsRealType()
    {
        $fetched = [];
        $saved   = [];
        $this->logoStubs( base64_decode( self::PNG ), $fetched, $saved );

        $this->assertSame( 31, ProfileApi::saveLogo( 'https://cdn.example/logo.html?x=.php' ) );
        $this->assertSame( 0, $fetched[0][1]['redirection'], 'redirects are not followed' );
        $this->assertSame( [ 'invoiceninja_plugin.png' ], $saved, 'the extension comes from the bytes, not the URL' );
    }

    public function testALogoThatIsntAnImageIsNotSaved()
    {
        $fetched = [];
        $saved   = [];
        $this->logoStubs( '<html><body>internal admin page</body></html>', $fetched, $saved );

        $this->assertSame( 0, ProfileApi::saveLogo( 'http://169.254.169.254/latest/meta-data/' ) );
        $this->assertSame( [], $saved );
        $this->assertSame( 0, ProfileApi::saveLogo( 'javascript:alert(1)' ) );
    }
}
