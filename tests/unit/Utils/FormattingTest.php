<?php

namespace InvoiceNinja\Tests\Utils;

use InvoiceNinja\Tests\TestCase;
use InvoiceNinja\Utils\Formatting;

/**
 * Prices must match what Invoice Ninja prints on the invoice (App\Utils\Number::formatMoney).
 */
class FormattingTest extends TestCase
{
    protected function set_up(): void
    {
        parent::set_up();
        $this->options['invoiceninja_currencies'] = json_encode( [
            1 => [ 'id' => 1, 'code' => 'USD', 'symbol' => '$', 'precision' => 2, 'thousand_separator' => ',', 'decimal_separator' => '.', 'swap_currency_symbol' => false ],
            3 => [ 'id' => 3, 'code' => 'EUR', 'symbol' => '€', 'precision' => 2, 'thousand_separator' => '.', 'decimal_separator' => ',', 'swap_currency_symbol' => true ],
            17 => [ 'id' => 17, 'code' => 'CHF', 'symbol' => 'CHF ', 'precision' => 2, 'thousand_separator' => "'", 'decimal_separator' => '.', 'swap_currency_symbol' => false ],
            11 => [ 'id' => 11, 'code' => 'JPY', 'symbol' => '¥', 'precision' => 0, 'thousand_separator' => ',', 'decimal_separator' => '.', 'swap_currency_symbol' => false ],
        ] );
        $this->options['invoiceninja_countries'] = json_encode( [
            840 => [ 'swap_currency_symbol' => false, 'thousand_separator' => '', 'decimal_separator' => '' ],
            372 => [ 'swap_currency_symbol' => false, 'thousand_separator' => ',', 'decimal_separator' => '.' ], // Ireland
            276 => [ 'swap_currency_symbol' => true, 'thousand_separator' => '', 'decimal_separator' => '' ],  // Germany
        ] );
    }

    private function company( $currency_id, $country_id, $show_code = false ): void
    {
        $this->options['invoiceninja_profile'] = json_encode( [ 'settings' => [
            'currency_id' => (string) $currency_id, 'country_id' => (string) $country_id, 'show_currency_code' => $show_code,
        ] ] );
    }

    /** @dataProvider amounts */
    public function testMatchesInvoiceNinja( $currency, $country, $show_code, $value, $expected )
    {
        $this->company( $currency, $country, $show_code );
        $this->assertSame( $expected, Formatting::formatMoney( $value ) );
    }

    public function amounts(): array
    {
        return [
            'USD'                     => [ 1, 840, false, 1234.5, '$1,234.50' ],
            'USD from a string meta'  => [ 1, 840, false, '10', '$10.00' ],
            'USD negative'            => [ 1, 840, false, -5, '-$5.00' ],
            'EUR in Germany'          => [ 3, 276, false, 1234.5, '1.234,50 €' ],
            'EUR in Ireland'          => [ 3, 372, false, 1234.5, '1,234.50 €' ],
            'show currency code'      => [ 1, 840, true, 99, '99.00 USD' ],
            'CHF code goes first'     => [ 17, 840, true, 1234.5, "CHF 1'234.50" ],
            'JPY has no decimals'     => [ 11, 840, false, 1500, '¥1,500' ],
        ];
    }

    /** The profile is cleared when a token change fails; the shop still renders products */
    public function testPlainAmountWithoutCompanyProfile()
    {
        $this->options['invoiceninja_profile'] = '';
        $this->assertSame( '10.00', Formatting::formatMoney( '10' ) );
    }

    public function testPlainAmountWithoutStatics()
    {
        $this->company( 1, 840 );
        unset( $this->options['invoiceninja_currencies'], $this->options['invoiceninja_countries'] );
        $this->assertSame( '25.50', Formatting::formatMoney( 25.5 ) );
    }

    public function testUnknownCountryUsesCurrencyDefaults()
    {
        $this->company( 3, 999 );
        $this->assertSame( '1.234,50 €', Formatting::formatMoney( 1234.5 ) );
    }
}
