<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Tests\TestCase;
use InvoiceNinja\WooCommerce\LineItems;
use InvoiceNinja\WooCommerce\Totals;

class WooTotalsTest extends TestCase
{
    private static function line( float $cost, float $quantity, float $rate = 0, float $discount = 0, string $tax = 'VAT' ): array
    {
        return [
            'product_key' => 'X', 'cost' => $cost, 'quantity' => $quantity, 'discount' => $discount,
            'tax_name1' => $rate ? $tax : '', 'tax_rate1' => $rate, 'tax_name2' => '', 'tax_rate2' => 0, 'tax_name3' => '', 'tax_rate3' => 0,
        ];
    }

    public function testLineTotalRoundsLikeInvoiceNinja()
    {
        $this->assertSame( 20.0, Totals::lineTotal( self::line( 10, 2 ) ) );
        $this->assertSame( 27.0, Totals::lineTotal( self::line( 10, 3, 0, 3 ) ) );
        $this->assertSame( 10.0, Totals::lineTotal( self::line( 3.333333, 3 ) ) );
        $this->assertSame( 1.01, Totals::lineTotal( self::line( 1.005, 1 ) ) );
        $this->assertSame( 8.33, Totals::lineTotal( self::line( 10, 1, 0, 1.666 ) ) );
    }

    public function testInvoiceTotalAddsTaxesRoundedPerLine()
    {
        $this->assertSame( 22.0, Totals::invoiceTotal( [ self::line( 10, 2, 10 ) ] ) );
        $this->assertSame( 29.7, Totals::invoiceTotal( [ self::line( 10, 3, 10, 3 ) ] ) );
        // 7% of 0.35 is 0.0245: Invoice Ninja rounds each line's tax to 0.02.
        $three = [ self::line( 0.35, 1, 7 ), self::line( 0.35, 1, 7 ), self::line( 0.35, 1, 7 ) ];
        $this->assertSame( 1.11, Totals::invoiceTotal( $three ) );
    }

    public function testTaxesAreGroupedByNameAndRate()
    {
        $groups = Totals::taxGroups( [ self::line( 10, 1, 10 ), self::line( 20, 1, 10 ), self::line( 5, 1, 5, 0, 'GST' ), self::line( 0, 1, 10 ) ] );
        $this->assertSame( [ 'VAT10' => 3.0, 'GST5' => 0.25 ], $groups );
    }

    public function testZeroTotalLinesHaveNoTax()
    {
        $this->assertSame( 0.0, Totals::invoiceTotal( [ self::line( 10, 1, 10, 10 ) ] ) );
    }

    public function testExactMatchChangesNothing()
    {
        $lines  = [ self::line( 10, 2, 10 ) ];
        $result = Totals::reconcile( $lines, 22.0 );
        $this->assertSame( Totals::EXACT, $result['status'] );
        $this->assertSame( $lines, $result['lines'] );
        $this->assertSame( 0.0, $result['difference'] );
    }

    public function testRoundingDifferenceGetsAnAdjustmentLine()
    {
        // WooCommerce rounding taxes at the subtotal: 7% of 1.05 = 0.07, so it totals 1.12.
        $lines  = [ self::line( 0.35, 1, 7 ), self::line( 0.35, 1, 7 ), self::line( 0.35, 1, 7 ) ];
        $result = Totals::reconcile( $lines, 1.12, 2, 'Rounding' );
        $this->assertSame( Totals::ADJUSTED, $result['status'] );
        $this->assertSame( 0.01, $result['difference'] );
        $this->assertCount( 4, $result['lines'] );
        $adjust = $result['lines'][3];
        $this->assertSame( 'Rounding', $adjust['product_key'] );
        $this->assertSame( 0.01, $adjust['cost'] );
        $this->assertSame( 1, $adjust['quantity'] );
        $this->assertSame( 0, $adjust['tax_rate1'] );
        $this->assertSame( 1.12, Totals::invoiceTotal( $result['lines'] ) );
    }

    public function testNegativeRoundingDifference()
    {
        $result = Totals::reconcile( [ self::line( 10, 2, 10 ) ], 21.99 );
        $this->assertSame( Totals::ADJUSTED, $result['status'] );
        $this->assertSame( -0.01, end( $result['lines'] )['cost'] );
        $this->assertSame( 21.99, Totals::invoiceTotal( $result['lines'] ) );
    }

    public function testLargerDifferenceIsAMismatchAndNotHidden()
    {
        $lines  = [ self::line( 10, 2, 10 ) ];
        $result = Totals::reconcile( $lines, 30.0 );
        $this->assertSame( Totals::MISMATCH, $result['status'] );
        $this->assertSame( $lines, $result['lines'] );
        $this->assertSame( 8.0, $result['difference'] );
        $this->assertSame( 22.0, $result['computed'] );
        $this->assertSame( 30.0, $result['expected'] );
    }

    public function testToleranceIsOneUnitPerLineAndTaxGroup()
    {
        $this->assertSame( 0.01, Totals::tolerance( [] ) );
        $this->assertSame( 0.02, Totals::tolerance( [ self::line( 10, 1, 10 ) ] ) );
        $this->assertSame( 0.05, Totals::tolerance( [ self::line( 1, 1, 10 ), self::line( 1, 1, 10 ), self::line( 1, 1, 5, 0, 'GST' ) ] ) );
        $this->assertSame( 2.0, Totals::tolerance( [ self::line( 1000, 1, 10 ) ], 0 ) );
        // Just over the limit is a mismatch.
        $this->assertSame( Totals::MISMATCH, Totals::reconcile( [ self::line( 10, 1, 10 ) ], 11.03 )['status'] );
        $this->assertSame( Totals::ADJUSTED, Totals::reconcile( [ self::line( 10, 1, 10 ) ], 11.02 )['status'] );
    }

    public function testZeroDecimalCurrency()
    {
        // Each tax group is rounded to whole yen.
        $this->assertSame( 1466.0, Totals::invoiceTotal( [ self::line( 1000, 1, 10 ), self::line( 333, 1, 10 ) ], 0 ) );
        // 33.3 + 33.3 rounds to 67 as a group; WooCommerce rounded each line to 33.
        $lines  = [ self::line( 333, 1, 10 ), self::line( 333, 1, 10 ) ];
        $result = Totals::reconcile( $lines, 732, 0 );
        $this->assertSame( Totals::ADJUSTED, $result['status'] );
        $this->assertSame( -1.0, $result['difference'] );
        $this->assertSame( -1, end( $result['lines'] )['cost'] );
        $this->assertSame( Totals::MISMATCH, Totals::reconcile( $lines, 729, 0 )['status'] );
    }

    public function testRealisticOrderMatchesExactly()
    {
        $order = [
            'precision' => 2,
            'rates'     => [ 1 => [ 'label' => 'VAT', 'percent' => 10, 'compound' => false ] ],
            'labels'    => [ 'shipping' => 'Shipping', 'fee' => 'Fee' ],
            'items'     => [
                [ 'name' => 'T-shirt', 'sku' => 'TSHIRT', 'quantity' => 2, 'subtotal' => 40, 'total' => 36, 'taxes' => [ 1 => 3.6 ] ],
                [ 'name' => 'Mug', 'sku' => '', 'quantity' => 1, 'subtotal' => 12.5, 'total' => 11.25, 'taxes' => [ 1 => 1.13 ] ],
            ],
            'shipping'  => [ [ 'name' => 'Flat rate', 'total' => 5, 'taxes' => [ 1 => 0.5 ] ] ],
            'fees'      => [ [ 'name' => 'Gift wrap', 'total' => 2, 'taxes' => [ 1 => 0.2 ] ] ],
        ];
        $lines  = LineItems::build( $order );
        $result = Totals::reconcile( $lines, 59.68 );
        $this->assertSame( Totals::EXACT, $result['status'], 'computed ' . $result['computed'] );
        $this->assertCount( 4, $result['lines'] );
    }

    public function testInclusivePricesRoundedPerLineAreAdjusted()
    {
        // A shop entering prices with tax: 9.99 incl. 19% is 8.394958 ex, tax 1.594958.
        // WooCommerce stores the line rounded: 8.39 + 1.60 = 9.99, Invoice Ninja gets 8.39 + 1.59.
        $order  = [
            'precision' => 2,
            'rates'     => [ 1 => [ 'label' => 'MwSt', 'percent' => 19, 'compound' => false ] ],
            'items'     => [ [ 'name' => 'Book', 'quantity' => 1, 'subtotal' => 8.39, 'total' => 8.39, 'taxes' => [ 1 => 1.6 ] ] ],
        ];
        $result = Totals::reconcile( LineItems::build( $order ), 9.99 );
        $this->assertSame( Totals::ADJUSTED, $result['status'] );
        $this->assertSame( 9.99, Totals::invoiceTotal( $result['lines'] ) );
    }

    public function testCompoundTaxMatchesThroughTheEffectiveRate()
    {
        $order  = [
            'precision' => 2,
            'rates'     => [
                1 => [ 'label' => 'GST', 'percent' => 5, 'compound' => false ],
                2 => [ 'label' => 'QST', 'percent' => 9.975, 'compound' => true ],
            ],
            // QST compounds on price + GST: 9.975% of 105 = 10.47.
            'items'     => [ [ 'name' => 'Chair', 'quantity' => 1, 'subtotal' => 100, 'total' => 100, 'taxes' => [ 1 => 5, 2 => 10.47 ] ] ],
        ];
        $result = Totals::reconcile( LineItems::build( $order ), 115.47 );
        $this->assertSame( Totals::EXACT, $result['status'], 'computed ' . $result['computed'] );
    }
}
