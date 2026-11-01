<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Api\Money;
use InvoiceNinja\Tests\TestCase;

class MoneyTest extends TestCase
{
    private const USD = [ 'code' => 'USD', 'symbol' => '$', 'precision' => 2, 'thousand_separator' => ',', 'decimal_separator' => '.', 'swap_currency_symbol' => false ];
    private const EUR = [ 'code' => 'EUR', 'symbol' => '€', 'precision' => 2, 'thousand_separator' => '.', 'decimal_separator' => ',', 'swap_currency_symbol' => true ];
    private const CHF = [ 'code' => 'CHF', 'symbol' => 'CHF ', 'precision' => 2, 'thousand_separator' => "'", 'decimal_separator' => '.', 'swap_currency_symbol' => false ];
    private const JPY = [ 'code' => 'JPY', 'symbol' => '¥', 'precision' => 0, 'thousand_separator' => ',', 'decimal_separator' => '.', 'swap_currency_symbol' => false ];
    private const SEK = [ 'code' => 'SEK', 'symbol' => 'kr', 'precision' => 2, 'thousand_separator' => ' ', 'decimal_separator' => ',', 'swap_currency_symbol' => true ];

    public function testFormats()
    {
        $this->assertSame( '$1,234.50', Money::format( 1234.5, self::USD ) );
        $this->assertSame( '-$5.00', Money::format( -5, self::USD ) );
        $this->assertSame( '1.234,50 €', Money::format( 1234.5, self::EUR ) );
        $this->assertSame( '1,234.50 €', Money::format( 1234.5, self::EUR, [ 'thousand_separator' => ',', 'decimal_separator' => '.', 'swap_currency_symbol' => false ] ) );
        $this->assertSame( "CHF 1'234.50", Money::format( 1234.5, self::CHF, [], true ) );
        $this->assertSame( '99.00 USD', Money::format( 99, self::USD, [], true ) );
        $this->assertSame( '¥1,500', Money::format( 1500, self::JPY ) );
        $this->assertSame( '1 234,50 kr', Money::format( 1234.5, self::SEK ) );
        $this->assertSame( '10.00', Money::format( '10', [] ) );
    }
}
