<?php

namespace InvoiceNinja\Tests\Controllers;

use Brain\Monkey\Functions;
use InvoiceNinja\Controllers\ProductController;
use InvoiceNinja\Tests\TestCase;

/**
 * The product import used to delete every product before fetching, so an
 * Invoice Ninja outage (or a bad token) emptied the shop. It must fetch first.
 */
class ProductControllerTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        Functions\expect( 'wp_delete_post' )->never();
        Functions\expect( 'wp_delete_attachment' )->never();
        Functions\expect( 'wp_insert_post' )->never();
    }

    public function testKeepsProductsWhenNotConnected()
    {
        $this->assertSame( 0, ProductController::loadProducts() );
    }

    public function testKeepsProductsWhenApiFails()
    {
        $this->options['invoiceninja_api_token'] = 'secret-token';
        $this->responder = function () {
            return new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
        };

        $this->assertSame( 0, ProductController::loadProducts() );
        $this->assertCount( 1, $this->requests );
    }

    public function testKeepsProductsWhenApiReturnsAnError()
    {
        $this->options['invoiceninja_api_token'] = 'secret-token';
        $this->respondWith( [ 'message' => 'Server Error' ], 500 );

        $this->assertSame( 0, ProductController::loadProducts() );
    }

    public function testKeepsProductsWhenApiReturnsNoProducts()
    {
        $this->options['invoiceninja_api_token'] = 'secret-token';
        $this->respondWith( [ 'data' => [] ] );

        $this->assertSame( 0, ProductController::loadProducts() );
    }

    public function testKeepsProductsWithEncryptedToken()
    {
        $this->options['invoiceninja_api_token'] = 'in1:abcd1234:ZW5jcnlwdGVk';

        $this->assertSame( 0, ProductController::loadProducts() );
        $this->assertSame( [], $this->requests );
    }
}
