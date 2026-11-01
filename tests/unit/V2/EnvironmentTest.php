<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Core\Environment;
use InvoiceNinja\Core\Options;
use InvoiceNinja\Tests\TestCase;

class EnvironmentTest extends TestCase
{
    public function testIdentityIgnoresSchemeWwwAndTrailingSlash()
    {
        $this->assertSame( 'shop.example.com', Environment::identity( 'https://www.shop.example.com/' ) );
        $this->assertSame( 'shop.example.com', Environment::identity( 'http://shop.example.com' ) );
        $this->assertSame( 'example.com/store', Environment::identity( 'https://example.com/store/' ) );
    }

    public function testProductionIsConfirmedAutomatically()
    {
        $env = new Environment( new Options() );
        $this->assertTrue( $env->writesAllowed() );
        $this->assertNotNull( $env->state() );

        $this->home_url = 'http://www.shop.example.com';
        $this->assertTrue( $env->writesAllowed(), 'http/https and www changes are the same site' );
    }

    /** @dataProvider copies */
    public function testCopiesArePaused( string $url, string $type )
    {
        $this->home_url         = $url;
        $this->environment_type = $type;
        $env                    = new Environment( new Options() );

        $this->assertFalse( $env->looksLive() );
        $this->assertFalse( $env->writesAllowed() );
    }

    public function copies(): array
    {
        return [
            'staging type'    => [ 'https://shop.example.com', 'staging' ],
            'local host'      => [ 'http://localhost:8089', 'production' ],
            '.localhost'      => [ 'http://wp.localhost:8089', 'production' ],
            '.test'           => [ 'https://shop.test', 'production' ],
            'staging sub'     => [ 'https://staging.shop.example.com', 'production' ],
            'private IP'      => [ 'http://192.168.1.20', 'production' ],
        ];
    }

    public function testMovedSiteIsPausedUntilConfirmed()
    {
        $env = new Environment( new Options() );
        $this->assertTrue( $env->writesAllowed() );

        $this->home_url = 'https://clone.example.net';
        $this->assertTrue( $env->changed() );
        $this->assertFalse( $env->writesAllowed() );

        $env->confirm( 1 );
        $this->assertTrue( $env->writesAllowed() );
    }

    public function testPause()
    {
        $env = new Environment( new Options() );
        $env->pause();
        $this->assertFalse( $env->writesAllowed() );
    }
}
