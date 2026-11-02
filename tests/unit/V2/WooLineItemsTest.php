<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Tests\TestCase;
use InvoiceNinja\WooCommerce\LineItems;

class WooLineItemsTest extends TestCase
{
    private const RATES = [
        1 => [ 'label' => 'VAT', 'percent' => 10, 'compound' => false ],
        2 => [ 'label' => 'City tax', 'percent' => 5, 'compound' => true ],
        3 => [ 'label' => 'Eco', 'percent' => 0, 'compound' => false ],
        4 => [ 'label' => 'T', 'percent' => 2, 'compound' => false ],
    ];

    private function order( array $parts ): array
    {
        return $parts + [ 'precision' => 2, 'rates' => self::RATES, 'labels' => [ 'shipping' => 'Shipping', 'fee' => 'Fee' ] ];
    }

    public function testProductWithSkuUsesItAsTheKeyAndTheNameAsNotes()
    {
        $lines = LineItems::build( $this->order( [ 'items' => [
            [ 'name' => 'T-shirt', 'sku' => 'TSHIRT-M', 'quantity' => 2, 'subtotal' => 40, 'total' => 40, 'taxes' => [ 1 => 4.0 ], 'notes' => 'Size: M' ],
        ] ] ) );
        $this->assertCount( 1, $lines );
        $this->assertSame( 'TSHIRT-M', $lines[0]['product_key'] );
        $this->assertSame( "T-shirt\nSize: M", $lines[0]['notes'] );
        $this->assertSame( 20, $lines[0]['cost'] );
        $this->assertSame( 2, $lines[0]['quantity'] );
        $this->assertSame( 0.0, $lines[0]['discount'] );
        $this->assertSame( 'VAT', $lines[0]['tax_name1'] );
        $this->assertSame( 10, $lines[0]['tax_rate1'] );
        $this->assertSame( '', $lines[0]['tax_name2'] );
        $this->assertSame( 0, $lines[0]['tax_rate2'] );
        $this->assertSame( '1', $lines[0]['type_id'] );
    }

    public function testProductWithoutSkuUsesTheName()
    {
        $lines = LineItems::build( $this->order( [ 'items' => [
            [ 'name' => 'Mug', 'sku' => '', 'quantity' => 1, 'subtotal' => 12.5, 'total' => 12.5, 'taxes' => [] ],
        ] ] ) );
        $this->assertSame( 'Mug', $lines[0]['product_key'] );
        $this->assertSame( '', $lines[0]['notes'] );
        $this->assertSame( 12.5, $lines[0]['cost'] );
        $this->assertSame( '', $lines[0]['tax_name1'] );
    }

    public function testCouponBecomesALineDiscountSoTaxIsOnTheDiscountedAmount()
    {
        $lines = LineItems::build( $this->order( [ 'items' => [
            [ 'name' => 'Lamp', 'sku' => 'LAMP', 'quantity' => 3, 'subtotal' => 30, 'total' => 27, 'taxes' => [ 1 => 2.7 ] ],
        ] ] ) );
        $this->assertSame( 10, $lines[0]['cost'] );
        $this->assertSame( 3.0, $lines[0]['discount'] );
        $this->assertSame( 10, $lines[0]['tax_rate1'] );
    }

    public function testDiscountIsRoundedToThePrecisionAndNeverNegative()
    {
        $lines = LineItems::build( $this->order( [ 'items' => [
            [ 'name' => 'A', 'quantity' => 1, 'subtotal' => 10, 'total' => 8.3333333 ],
            [ 'name' => 'B', 'quantity' => 1, 'subtotal' => 10, 'total' => 10.5 ],
        ] ] ) );
        $this->assertSame( 1.67, $lines[0]['discount'] );
        $this->assertSame( 0.0, $lines[1]['discount'] );
    }

    public function testUnitCostKeepsSixDecimals()
    {
        $lines = LineItems::build( $this->order( [ 'items' => [
            [ 'name' => 'Third', 'quantity' => 3, 'subtotal' => 10, 'total' => 10 ],
        ] ] ) );
        $this->assertSame( 3.333333, $lines[0]['cost'] );
        $this->assertSame( 3, $lines[0]['quantity'] );
    }

    public function testItemsWithoutQuantityAreLeftOut()
    {
        $lines = LineItems::build( $this->order( [ 'items' => [
            [ 'name' => 'Gone', 'quantity' => 0, 'subtotal' => 0, 'total' => 0 ],
            'not an item',
            [ 'name' => 'Kept', 'quantity' => 1, 'subtotal' => 1, 'total' => 1 ],
        ] ] ) );
        $this->assertCount( 1, $lines );
        $this->assertSame( 'Kept', $lines[0]['product_key'] );
    }

    public function testShippingIsOneTaxedLineAndFreeShippingIsLeftOut()
    {
        $lines = LineItems::build( $this->order( [
            'shipping' => [
                [ 'name' => 'Flat rate', 'total' => 5, 'taxes' => [ 1 => 0.5 ] ],
                [ 'name' => 'Free shipping', 'total' => 0, 'taxes' => [] ],
            ],
        ] ) );
        $this->assertCount( 1, $lines );
        $this->assertSame( 'Shipping', $lines[0]['product_key'] );
        $this->assertSame( 'Flat rate', $lines[0]['notes'] );
        $this->assertSame( 5, $lines[0]['cost'] );
        $this->assertSame( 1, $lines[0]['quantity'] );
        $this->assertSame( 'VAT', $lines[0]['tax_name1'] );
    }

    public function testFeesIncludingNegativeOnes()
    {
        $lines = LineItems::build( $this->order( [
            'fees' => [
                [ 'name' => 'Gift wrap', 'total' => 2, 'taxes' => [ 1 => 0.2 ] ],
                [ 'name' => 'Loyalty discount', 'total' => -3.5, 'taxes' => [ 1 => -0.35 ] ],
                [ 'name' => '', 'total' => 1, 'taxes' => [] ],
            ],
        ] ) );
        $this->assertSame( 'Gift wrap', $lines[0]['product_key'] );
        $this->assertSame( 2, $lines[0]['cost'] );
        $this->assertSame( -3.5, $lines[1]['cost'] );
        $this->assertSame( 10, $lines[1]['tax_rate1'] );
        $this->assertSame( 'Fee', $lines[2]['product_key'] );
    }

    public function testCompoundAndUnknownRatesAreSentAsTheEffectiveRate()
    {
        $taxes = LineItems::taxes( [ 1 => 10, 2 => 5.5, 3 => 1.25 ], self::RATES, 100 );
        $this->assertSame( 'VAT', $taxes['tax_name1'] );
        $this->assertSame( 10, $taxes['tax_rate1'] );
        // Compound: 5% of (100 + 10) = 5.50, which is 5.5% of the line.
        $this->assertSame( 'City tax', $taxes['tax_name2'] );
        $this->assertSame( 5.5, $taxes['tax_rate2'] );
        // Percentage unknown: worked out from the amount.
        $this->assertSame( 'Eco', $taxes['tax_name3'] );
        $this->assertSame( 1.25, $taxes['tax_rate3'] );
    }

    public function testAtMostThreeTaxesAndZeroTaxesSkipped()
    {
        $taxes = LineItems::taxes( [ 1 => 0, 2 => 5, 3 => 1, 4 => 2, 9 => 3 ], self::RATES, 100 );
        $this->assertSame( 'City tax', $taxes['tax_name1'] );
        $this->assertSame( 'Eco', $taxes['tax_name2'] );
        $this->assertSame( 'Tax T', $taxes['tax_name3'] );
        $this->assertSame( 2, $taxes['tax_rate3'] );
        $this->assertArrayNotHasKey( 'tax_name4', $taxes );
    }

    public function testUnknownRateIdOnAZeroLineKeepsTheRateAtZero()
    {
        $taxes = LineItems::taxes( [ 7 => 1 ], [], 0 );
        $this->assertSame( 'Tax', $taxes['tax_name1'] );
        $this->assertSame( 0, $taxes['tax_rate1'] );
    }

    public function testTaxNamesInvoiceNinjaWillTotal()
    {
        $this->assertSame( 'VAT', LineItems::taxName( ' VAT ' ) );
        $this->assertSame( 'Tax', LineItems::taxName( '' ) );
        $this->assertSame( 'Tax T', LineItems::taxName( 'T' ) );
        $this->assertSame( 'GST  PST', LineItems::taxName( 'GST, PST' ) );
    }

    public function testNumbersHaveNoFloatNoise()
    {
        $this->assertSame( 10, LineItems::number( 10.0 ) );
        $this->assertSame( -2, LineItems::number( -2.0000000001 ) );
        $this->assertSame( 0.3, LineItems::number( 0.1 + 0.2 ) );
        $this->assertSame( 3.333333, LineItems::cost( 10, 3 ) );
        $this->assertSame( 7, LineItems::cost( 7, 0 ) );
    }

    public function testHashChangesOnlyWhenTheLinesDo()
    {
        $order = $this->order( [ 'items' => [ [ 'name' => 'A', 'quantity' => 1, 'subtotal' => 5, 'total' => 5 ] ] ] );
        $same  = LineItems::hash( LineItems::build( $order ) );
        $this->assertSame( $same, LineItems::hash( LineItems::build( $order ) ) );
        $order['items'][0]['quantity'] = 2;
        $order['items'][0]['subtotal'] = 10;
        $order['items'][0]['total']    = 10;
        $this->assertNotSame( $same, LineItems::hash( LineItems::build( $order ) ) );
    }

    public function testOrderOfLinesIsProductsShippingFees()
    {
        $lines = LineItems::build( $this->order( [
            'fees'     => [ [ 'name' => 'Fee 1', 'total' => 1 ] ],
            'shipping' => [ [ 'name' => 'Courier', 'total' => 4 ] ],
            'items'    => [ [ 'name' => 'Thing', 'quantity' => 1, 'subtotal' => 2, 'total' => 2 ] ],
        ] ) );
        $this->assertSame( [ 'Thing', 'Shipping', 'Fee 1' ], array_column( $lines, 'product_key' ) );
    }
}
