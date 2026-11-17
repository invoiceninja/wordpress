<?php

namespace InvoiceNinja\Tests\V2;

use Brain\Monkey\Functions;
use InvoiceNinja\Portal\Account;
use InvoiceNinja\Tests\TestCase;

/**
 * The Account block's Balance due panel, row statuses and the "pay several" portal link.
 */
class AccountSummaryTest extends TestCase
{
    private function invoice( string $id, array $extra = [] ): array
    {
        return $extra + [ 'id' => $id, 'number' => $id, 'client_id' => 'CL1', 'status_id' => '2', 'balance' => 10, 'amount' => 10, 'due_date' => '' ];
    }

    public function testTheBalanceAddsUpOnlyTheClientsOpenInvoices()
    {
        $sum = Account::summarize(
            [
                $this->invoice( 'A', [ 'balance' => 68.5, 'due_date' => '2026-12-01' ] ),
                $this->invoice( 'B', [ 'status_id' => '3', 'balance' => 20, 'due_date' => '2026-10-01' ] ),
                $this->invoice( 'B', [ 'status_id' => '3', 'balance' => 20, 'due_date' => '2026-10-01' ] ), // Listed twice
                $this->invoice( 'PAID', [ 'status_id' => '4', 'balance' => 0 ] ),
                $this->invoice( 'DRAFT', [ 'status_id' => '1', 'balance' => 99 ] ),
                $this->invoice( 'GONE', [ 'is_deleted' => true ] ),
                $this->invoice( 'CANCELLED', [ 'status_id' => '5' ] ),
                $this->invoice( 'THEIRS', [ 'client_id' => 'CL2' ] ),
                'not an invoice',
            ],
            'CL1',
            '2026-11-17'
        );

        $this->assertSame( 88.5, $sum['total'] );
        $this->assertSame( 2, $sum['count'] );
        $this->assertSame( 1, $sum['overdue'], 'B was due in October' );
        $this->assertSame( '2026-10-01', $sum['next_due'] );
        $this->assertNull( $sum['single'], 'several invoices: no single one to pay' );
    }

    public function testOneOpenInvoiceIsTheOneToPay()
    {
        $sum = Account::summarize( [ $this->invoice( 'A' ), $this->invoice( 'P', [ 'status_id' => '4', 'balance' => 0 ] ) ], 'CL1', '2026-11-17' );

        $this->assertSame( 1, $sum['count'] );
        $this->assertSame( 'A', $sum['single']['id'] );
        $this->assertSame( 0, $sum['overdue'], 'no due date is never overdue' );
        $this->assertSame( '', $sum['next_due'] );
    }

    public function testNothingOwed()
    {
        $sum = Account::summarize( [], 'CL1', '2026-11-17' );
        $this->assertSame( [ 0.0, 0, null ], [ $sum['total'], $sum['count'], $sum['single'] ] );
    }

    public function testStatusesAndTheirTone()
    {
        $today = '2026-11-17';
        $this->assertSame( [ 'Overdue', 'alert' ], Account::status( 'invoices', '2', 5.0, '2026-11-16', $today ) );
        $this->assertSame( [ 'Unpaid', 'open' ], Account::status( 'invoices', '2', 5.0, '2026-11-17', $today ), 'due today is not overdue' );
        $this->assertSame( [ 'Unpaid', 'open' ], Account::status( 'invoices', '2', 5.0, '', $today ) );
        $this->assertSame( [ 'Paid', 'done' ], Account::status( 'invoices', '4', 0.0, '2026-01-01', $today ), 'a paid invoice is never overdue' );
        $this->assertSame( [ 'Expired', 'off' ], Account::status( 'quotes', '2', 0.0, '2026-11-01', $today ) );
        $this->assertSame( [ 'Waiting for you', 'open' ], Account::status( 'quotes', '2', 0.0, '2026-12-01', $today ) );
        $this->assertSame( [ 'Approved', 'done' ], Account::status( 'quotes', '3', 0.0, '2026-11-01', $today ) );
        $this->assertSame( [ 'Completed', 'done' ], Account::status( 'payments', '4', 0.0, '', $today ) );
        $this->assertSame( [ 'Available', 'open' ], Account::status( 'credits', '2', 3.0, '', $today ) );
    }

    public function testPayingSeveralOpensThePortalsInvoiceList()
    {
        Functions\when( 'add_query_arg' )->alias( fn( $key, $value, $url ) => $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value );
        $login = 'https://in.test/client/key_login/KEY1';

        $this->assertSame( $login . '?next=%2Fclient%2Finvoices', Account::portalTarget( $login, 'invoices' ) );
        $this->assertSame( $login, Account::portalTarget( $login, '' ), 'the plain portal link is unchanged' );
        $this->assertSame( 'https://in.test/other', Account::portalTarget( 'https://in.test/other', 'invoices' ), 'only a login link takes next' );
    }
}
