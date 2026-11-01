<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Api\BaseUrl;
use InvoiceNinja\Tests\TestCase;

class BaseUrlTest extends TestCase
{
    /** @dataProvider addresses */
    public function testNormalize( $typed, $expected )
    {
        $this->assertSame( $expected, BaseUrl::normalize( $typed ) );
    }

    public function addresses(): array
    {
        return [
            'bare host'                => [ 'billing.example.com', 'https://billing.example.com' ],
            'trailing slash'           => [ 'billing.example.com/', 'https://billing.example.com' ],
            'api path'                 => [ 'https://billing.example.com/api/v1/', 'https://billing.example.com' ],
            'client portal link'       => [ 'https://billing.example.com/client/login', 'https://billing.example.com' ],
            'upper case host'          => [ 'HTTPS://Billing.Example.COM', 'https://billing.example.com' ],
            'http kept'                => [ 'http://192.168.1.5:8000/ninja', 'http://192.168.1.5:8000/ninja' ],
            'subdirectory api'         => [ 'https://example.com/ninja/api/v1', 'https://example.com/ninja' ],
            '.shop TLD (#3)'           => [ 'invoices.example.shop', 'https://invoices.example.shop' ],
            '.co.za (#14)'             => [ 'https://billing.example.co.za', 'https://billing.example.co.za' ],
            'surrounding spaces'       => [ '  invoicing.co  ', 'https://invoicing.co' ],
            'empty'                    => [ '', null ],
            'spaces inside'            => [ 'billing example.com', null ],
            'credentials in url'       => [ 'https://user:pass@example.com', null ],
            'not a host'               => [ 'https://-bad-.example', null ],
        ];
    }

    public function testApiAndHosted()
    {
        $this->assertSame( 'https://invoicing.co/api/v1/', BaseUrl::api( 'https://invoicing.co' ) );
        $this->assertTrue( BaseUrl::isHosted( 'https://invoicing.co' ) );
        $this->assertTrue( BaseUrl::isHosted( 'https://acme.invoicing.co' ) );
        $this->assertFalse( BaseUrl::isHosted( 'https://notinvoicing.co' ) );
    }
}
