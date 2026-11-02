<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Tests\TestCase;
use InvoiceNinja\Webhooks\Events;

class WebhookEventsTest extends TestCase
{
    public function testEveryEventWeUseIsMappedWithInvoiceNinjasIds()
    {
        $expected = [
            'client'  => [ 1 => 'created', 10 => 'updated', 11 => 'deleted', 37 => 'archived', 45 => 'restored' ],
            'invoice' => [ 2 => 'created', 8 => 'updated', 9 => 'deleted', 33 => 'archived', 41 => 'restored', 60 => 'sent', 24 => 'reminder', 22 => 'late' ],
            'payment' => [ 4 => 'created', 31 => 'updated', 12 => 'deleted', 32 => 'archived', 40 => 'restored' ],
            'quote'   => [ 21 => 'approved' ],
            'product' => [ 50 => 'created', 51 => 'updated', 52 => 'deleted', 53 => 'restored', 54 => 'archived' ],
        ];
        $count = 0;
        foreach ( $expected as $entity => $events ) {
            foreach ( $events as $id => $name ) {
                $this->assertSame( $entity, Events::entity( $id ), "event $id" );
                $this->assertSame( $name, Events::name( $id ), "event $id" );
                ++$count;
            }
        }
        $this->assertCount( $count, Events::ids(), 'no other events are registered' );
        $this->assertFalse( Events::known( 3 ), 'quote created is not used' );
    }

    public function testTheEntityIsFetchedAgainByIdAndPaymentsIncludeTheirInvoices()
    {
        $this->assertSame( [ 'products/abc', [] ], Events::fetch( 51, 'abc' ) );
        $this->assertSame( [ 'quotes/Q1', [] ], Events::fetch( 21, 'Q1' ) );
        $this->assertSame( [ 'payments/P9', [ 'include' => 'invoices' ] ], Events::fetch( 4, 'P9' ) );
    }

    public function testIdsAreCleanedOfAnythingButHashCharacters()
    {
        $this->assertSame( 'abc123', Events::cleanId( 'abc/../123' ) );
        $this->assertSame( '', Events::cleanId( [ 'x' ] ) );
        $this->assertSame( 64, strlen( Events::cleanId( str_repeat( 'a', 100 ) ) ) );
    }

    public function testPaymentInvoiceIdsComeFromInvoicesAndPaymentables()
    {
        $payment = [
            'invoices'     => [ [ 'id' => 'INV1' ], [ 'id' => 'INV2' ] ],
            'paymentables' => [ [ 'invoice_id' => 'INV2' ], [ 'credit_id' => 'CR1' ], [ 'invoice_id' => 'INV3' ] ],
        ];
        $this->assertSame( [ 'INV1', 'INV2', 'INV3' ], Events::paymentInvoiceIds( $payment ) );
        $this->assertSame( [], Events::paymentInvoiceIds( [] ) );
    }
}
