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
            return [ 'response' => [ 'code' => $code ], 'body' => json_encode( $body ) ];
        };
    }

    /** @return string[] */
    protected function requestedUrls(): array
    {
        return array_column( $this->requests, 'url' );
    }
}
