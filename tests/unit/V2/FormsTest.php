<?php

namespace InvoiceNinja\Tests\V2;

use Brain\Monkey\Functions;
use InvoiceNinja\Forms\FormConfig;
use InvoiceNinja\Forms\QuoteCreator;
use InvoiceNinja\Forms\QuotePayload;
use InvoiceNinja\Forms\TagManager;
use InvoiceNinja\Tests\TestCase;

class FormsTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );
        Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
    }

    public function testEmailIsAlwaysShownAndRequired()
    {
        $config = FormConfig::fromAttributes( [ 'showPhone' => false, 'requireLastName' => 'true', 'requireMessage' => false ], [ 'P1' ] );
        $this->assertTrue( $config['fields']['email']['show'] );
        $this->assertTrue( $config['fields']['email']['required'] );
        $this->assertFalse( $config['fields']['phone']['show'] );
        $this->assertFalse( $config['fields']['phone']['required'] );
        $this->assertTrue( $config['fields']['last_name']['required'] );
        $this->assertFalse( $config['fields']['message']['required'] );
        $this->assertSame( [ 'P1' ], $config['products'] );
    }

    public function testBlockProductsWinOverDefaultsAndNoProductsHidesTheField()
    {
        $this->assertSame( [ 'P2', 'P3' ], FormConfig::fromAttributes( [ 'products' => 'P2, P3,P2,bad id' ], [ 'P1' ] )['products'] );
        $none = FormConfig::fromAttributes( [ 'requireProduct' => true ], [] );
        $this->assertFalse( $none['fields']['product']['show'] );
        $this->assertFalse( $none['fields']['product']['required'] );
    }

    public function testConsentNeedsAPageToBeRequired()
    {
        $config = FormConfig::fromAttributes( [ 'privacyPage' => '12', 'termsPage' => 0, 'requireTerms' => true ] );
        $this->assertSame( [ 'page' => 12, 'required' => true ], $config['privacy'] );
        $this->assertSame( [ 'page' => 0, 'required' => false ], $config['terms'] );
    }

    public function testSignedTokensRejectTamperingAndOtherSecrets()
    {
        $config = FormConfig::fromAttributes( [ 'products' => [ 'P1' ] ] );
        $token  = FormConfig::sign( $config, 1700000000, 'secret-one' );
        $this->assertSame( [ 'config' => $config, 'time' => 1700000000 ], FormConfig::verify( $token, 'secret-one' ) );
        $this->assertNull( FormConfig::verify( $token, 'secret-two' ) );
        [ $data, $mac ] = explode( '.', $token );
        $forged = rtrim( strtr( base64_encode( json_encode( [ FormConfig::fromAttributes( [ 'products' => [ 'P1', 'P9' ] ] ), 1700000000 ] ) ), '+/', '-_' ), '=' );
        $this->assertNull( FormConfig::verify( $forged . '.' . $mac, 'secret-one' ) );
        $this->assertNull( FormConfig::verify( 'garbage', 'secret-one' ) );
    }

    public function testValidationReportsEachFieldAndChecksProductsAgainstTheConfig()
    {
        $config = FormConfig::fromAttributes( [ 'products' => [ 'P1' ], 'privacyPage' => 5, 'requirePhone' => true ] );
        $result = FormConfig::validate( $config, [ 'email' => 'nope', 'first_name' => '', 'phone' => 'call me', 'product' => 'P2', 'message' => 'Hi' ] );
        $this->assertSame( [ 'first_name', 'email', 'phone', 'product', 'privacy' ], array_keys( $result['errors'] ) );

        $ok = FormConfig::validate( $config, [ 'email' => ' Jane@Example.com ', 'first_name' => 'Jane', 'phone' => '+1 (555) 010-0', 'product' => FormConfig::NOT_SURE, 'message' => 'Hi', 'privacy' => 'true', 'terms' => true ] );
        $this->assertSame( [], $ok['errors'] );
        $this->assertSame( 'jane@example.com', $ok['values']['email'] );
        $this->assertSame( [ 'privacy' => true ], $ok['consents'], 'no terms page, so no terms consent' );
    }

    public function testHiddenFieldsAreIgnoredAndLongValuesRefused()
    {
        $config = FormConfig::fromAttributes( [ 'showPhone' => false ] );
        $result = FormConfig::validate( $config, [ 'email' => 'a@b.co', 'first_name' => str_repeat( 'x', 101 ), 'phone' => '123', 'message' => 'Hi' ] );
        $this->assertSame( '', $result['values']['phone'] );
        $this->assertArrayHasKey( 'first_name', $result['errors'] );
    }

    public function testRedirectMustBeAFullWebAddress()
    {
        $this->assertSame( 'https://shop.example.com/thanks/', FormConfig::redirectUrl( ' https://shop.example.com/thanks/ ' ) );
        $this->assertSame( 'http://wp.localhost:8089/thanks', FormConfig::redirectUrl( 'http://wp.localhost:8089/thanks' ) );
        $this->assertSame( '', FormConfig::redirectUrl( 'javascript:alert(1)' ) );
        $this->assertSame( '', FormConfig::redirectUrl( '/thanks' ) );
        $this->assertSame( '', FormConfig::redirectUrl( '' ) );
    }

    public function testTagNamesLoseCommasAndRepeats()
    {
        $this->assertSame( 'Web site', QuotePayload::tagName( ' Web,site ' ) );
        $this->assertSame( 191, mb_strlen( QuotePayload::tagName( str_repeat( 'é', 300 ) ) ) );
        $this->assertSame( [ 'Quote request', 'web' ], QuotePayload::tagNames( 'Quote request, web, Web,, ' ), 'the first spelling wins' );
        $this->assertSame( [ 'A B' ], QuotePayload::tagNames( [ 'A,B', 'a b' ] ) );
    }

    public function testQuoteBodyHasTheLineItemAndTagsOnlyWhenThereAreSome()
    {
        $body = QuotePayload::quoteBody( 'C1', 'Message', 'Ref: x', [ 'product_key' => 'Widget', 'notes' => 'n', 'price' => 10.5 ], [ 'T1', 'T1', 'T2' ] );
        $this->assertSame( 'C1', $body['client_id'] );
        $this->assertSame( 'Message', $body['public_notes'] );
        $this->assertSame( [ [ 'product_key' => 'Widget', 'notes' => 'n', 'cost' => 10.5, 'quantity' => 1 ] ], $body['line_items'] );
        $this->assertSame( [ 'T1', 'T2' ], $body['tags'] );

        $bare = QuotePayload::quoteBody( 'C1', '', 'Ref: x', null, [] );
        $this->assertSame( [], $bare['line_items'] );
        $this->assertArrayNotHasKey( 'tags', $bare, 'sending tags replaces the list, so none are sent when there are none' );
    }

    public function testExistingQuoteIsFoundByItsWholeMarkerOnly()
    {
        $marker = QuotePayload::marker( 'K7', 1, 'abcd' );
        $this->assertSame( 'wp_k7_lead_1_abcd', $marker );
        $quotes = [
            [ 'id' => 'Q12', 'client_id' => 'C1', 'private_notes' => "Form\nRef: wp_k7_lead_1_abcdef" ],
            [ 'id' => 'QX', 'client_id' => 'C2', 'private_notes' => "Ref: $marker" ],
            [ 'id' => 'QD', 'client_id' => 'C1', 'private_notes' => "Ref: $marker", 'is_deleted' => true ],
            [ 'id' => 'Q1', 'client_id' => 'C1', 'private_notes' => "Quote Request Form\nRef: $marker" ],
        ];
        $this->assertSame( 'Q1', QuotePayload::findByMarker( $quotes, $marker, 'C1' )['id'] );
        $this->assertNull( QuotePayload::findByMarker( $quotes, $marker, 'C3' ) );
    }

    public function testPrivateNotesCarryPhoneConsentAndMarker()
    {
        $notes = QuotePayload::privateNotes( 'wp_k7_lead_3_ff', [ 'phone' => '555', 'product' => FormConfig::NOT_SURE ], [ 'privacy' => [ 'agreed' => true, 'at' => 0, 'title' => 'Privacy Policy' ], 'terms' => [ 'agreed' => false, 'title' => 'Terms' ] ], 'shop.example.com' );
        $this->assertStringContainsString( 'shop.example.com', $notes );
        $this->assertStringContainsString( 'Phone: 555', $notes );
        $this->assertStringContainsString( 'Not sure yet', $notes );
        $this->assertStringContainsString( 'Agreed to Privacy Policy at 1970-01-01 00:00 UTC', $notes );
        $this->assertStringNotContainsString( 'Terms', $notes );
        $this->assertStringEndsWith( "\nRef: wp_k7_lead_3_ff", $notes );
    }

    public function testQuoteStatuses()
    {
        $this->assertSame( 'draft', QuotePayload::status( [ 'status_id' => '1' ] ) );
        $this->assertSame( 'sent', QuotePayload::status( [ 'status_id' => 2 ] ) );
        $this->assertSame( 'approved', QuotePayload::status( [ 'status_id' => '3' ] ) );
        $this->assertSame( 'cancelled', QuotePayload::status( [ 'status_id' => '6' ] ) );
        $this->assertSame( 'expired', QuotePayload::status( [ 'status_id' => '-1' ] ) );
        $this->assertSame( 'deleted', QuotePayload::status( [ 'status_id' => '3', 'is_deleted' => true ] ) );
    }

    public function testTagMatchIgnoresCaseOtherTypesAndDeletedTags()
    {
        $tags = [
            [ 'id' => 'T0', 'name' => 'Web', 'entity_type' => 'App\\Models\\Invoice' ],
            [ 'id' => 'T1', 'name' => 'Web', 'entity_type' => 'App\\Models\\Quote', 'is_deleted' => true ],
            [ 'id' => 'T2', 'name' => 'Website', 'entity_type' => 'App\\Models\\Quote' ],
            [ 'id' => 'T3', 'name' => 'web', 'entity_type' => 'App\\Models\\Quote' ],
        ];
        $this->assertSame( 'T3', TagManager::match( $tags, 'Web' ) );
        $this->assertNull( TagManager::match( $tags, 'Other' ) );
    }

    public function testTagValidationErrorsAreRecognised()
    {
        $this->assertTrue( QuoteCreator::tagError( [ 'tags.0' => [ 'invalid' ] ] ) );
        $this->assertFalse( QuoteCreator::tagError( [ 'client_id' => [ 'required' ] ] ) );
    }

    public function testTagsAreSkippedBelowTheTagsVersion()
    {
        $manager = new TagManager( new \InvoiceNinja\Core\Options() );
        $client  = new \InvoiceNinja\Api\HttpClient( 'https://in.example.com', 'token' );
        $this->assertSame( [], $manager->ids( $client, [ 'Web' ], '5.13.24' ) );
        $this->assertSame( [], $this->requests );
    }
}
