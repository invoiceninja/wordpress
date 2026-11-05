<?php

namespace InvoiceNinja\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

abstract class TestCase extends PolyfillTestCase
{
    /** @var array<string, mixed> */
    protected $options = [];

    /** @var array<int, array{url: string, args: array}> */
    protected $requests = [];

    /** @var callable|null Returns a WP-style response array (or WP_Error) for a request */
    protected $responder = null;

    /** @var string */
    protected $home_url = 'https://shop.example.com';

    /** @var array<string, mixed> */
    protected $transients = [];

    /** @var array<int, array{to: mixed, subject: string, body: string}> */
    protected $mails = [];

    /** @var string */
    protected $environment_type = 'production';

    protected function set_up(): void
    {
        parent::set_up();
        Monkey\setUp();

        $this->options = [];
        $this->requests = [];
        $this->responder = null;

        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
        } );
        Functions\when( 'update_option' )->alias( function ( $name, $value ) {
            $this->options[ $name ] = $value;
            return true;
        } );
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'site_url' )->justReturn( 'https://wp.example' );
        Functions\when( 'is_admin' )->justReturn( false );
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        } );
        Functions\when( 'wp_remote_request' )->alias( function ( $url, $args ) {
            $this->requests[] = [ 'url' => $url, 'args' => $args ];
            $responder = $this->responder;
            return $responder ? $responder( $url, $args ) : [ 'response' => [ 'code' => 200 ], 'body' => '{"data":[]}' ];
        } );
        Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $response ) {
            return $response['response']['code'];
        } );
        Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $response ) {
            return $response['body'];
        } );
        Functions\when( 'wp_remote_retrieve_headers' )->alias( function ( $response ) {
            return $response['headers'] ?? array();
        } );
        Functions\when( 'add_option' )->alias( function ( $name, $value ) {
            if ( array_key_exists( $name, $this->options ) ) {
                return false;
            }
            $this->options[ $name ] = $value;
            return true;
        } );
        Functions\when( 'delete_option' )->alias( function ( $name ) {
            unset( $this->options[ $name ] );
            return true;
        } );
        Functions\stubTranslationFunctions();
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'sanitize_text_field' )->alias( 'trim' );
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
        } );
        Functions\when( 'sanitize_email' )->alias( 'trim' );
        Functions\when( 'esc_url_raw' )->returnArg();
        Functions\when( 'home_url' )->alias( function () {
            return $this->home_url;
        } );
        Functions\when( 'wp_salt' )->justReturn( 'test-salt-auth-key-and-salt' );
        Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
        Functions\when( 'wp_get_environment_type' )->alias( function () {
            return $this->environment_type;
        } );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'is_multisite' )->justReturn( false );
        $this->transients = [];
        $this->mails      = [];
        Functions\when( 'get_transient' )->alias( function ( $name ) {
            return $this->transients[ $name ] ?? false;
        } );
        Functions\when( 'set_transient' )->alias( function ( $name, $value ) {
            $this->transients[ $name ] = $value;
            return true;
        } );
        Functions\when( 'delete_transient' )->alias( function ( $name ) {
            unset( $this->transients[ $name ] );
            return true;
        } );
        Functions\when( 'wp_mail' )->alias( function ( $to, $subject, $body ) {
            $this->mails[] = compact( 'to', 'subject', 'body' );
            return true;
        } );
        Functions\when( 'is_email' )->alias( function ( $email ) {
            return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
        } );
        Functions\when( 'wp_specialchars_decode' )->returnArg();
        Functions\stubEscapeFunctions();
        Functions\when( 'esc_html__' )->returnArg();
        // No real DNS in unit tests: names resolve to nothing (IP literals are checked directly)
        \Brain\Monkey\Filters\expectApplied( 'invoiceninja_pre_resolve_host' )->zeroOrMoreTimes()->andReturn( [] );
    }

    protected function tear_down(): void
    {
        Monkey\tearDown();
        parent::tear_down();
    }

    /** Respond to every API request with this JSON body */
    protected function respondWith( array $body, int $code = 200 ): void
    {
        $this->responder = function () use ( $body, $code ) {
            return [ 'response' => [ 'code' => $code ], 'body' => json_encode( $body ), 'headers' => [ 'x-app-version' => '5.13.52' ] ];
        };
    }

    /** @return string[] */
    protected function requestedUrls(): array
    {
        return array_column( $this->requests, 'url' );
    }
}
