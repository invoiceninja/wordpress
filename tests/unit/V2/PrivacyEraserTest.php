<?php

namespace InvoiceNinja\Tests\V2;

use Brain\Monkey\Functions;
use InvoiceNinja\Commerce\Orders;
use InvoiceNinja\Plugin;
use InvoiceNinja\Privacy\Privacy;
use InvoiceNinja\Tests\TestCase;

/**
 * A11: the eraser finds the account's orders under older emails, pages through them,
 * and removes the payment links.
 */
class PrivacyEraserTest extends TestCase
{
    /** In-memory orders table. */
    public static array $rows = [];

    private function privacy(): Privacy
    {
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            private array $args = [];

            public function prepare( $query, ...$args )
            {
                $this->args = $args;
                return $query;
            }

            public function get_col( $query )
            {
                [ $email, $user_id, $limit ] = $this->args;
                $ids = [];
                foreach ( PrivacyEraserTest::$rows as $row ) {
                    if ( str_contains( $query, 'user_id = %d' ) && ( $row['email'] === $email || ( $user_id > 0 && $row['user_id'] === $user_id ) ) ) {
                        $ids[] = $row['id'];
                    }
                }
                return array_slice( $ids, 0, $limit );
            }

            public function get_row( $query )
            {
                return PrivacyEraserTest::$rows[ (int) $this->args[0] ] ?? null;
            }

            public function update( $table, $data, $where )
            {
                PrivacyEraserTest::$rows[ $where['id'] ] = $data + PrivacyEraserTest::$rows[ $where['id'] ];
                return 1;
            }
        };
        if ( ! defined( 'ARRAY_A' ) ) {
            define( 'ARRAY_A', 'ARRAY_A' );
        }
        $plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
        ( function () {
            $this->orders = new Orders();
        } )->call( $plugin );
        return new Privacy( $plugin );
    }

    private function order( int $id, string $email, int $user_id ): array
    {
        return [
            'id' => $id, 'number' => 'WEB-' . $id, 'email' => $email, 'user_id' => $user_id, 'total' => 10, 'attempts' => 0,
            'items' => '[]', 'customer' => '{"first_name":"Jane"}', 'pay_link' => 'https://in.test/client/invoice/KEY' . $id,
        ];
    }

    public function testErasesOrdersUnderOlderEmailsAndTheirPayLinks()
    {
        Functions\when( 'get_user_by' )->justReturn( (object) [ 'ID' => 4 ] );
        Functions\when( 'delete_user_meta' )->justReturn( false );
        self::$rows = [
            1 => $this->order( 1, 'jane@x.com', 0 ),
            2 => $this->order( 2, 'old.jane@x.com', 4 ),
            3 => $this->order( 3, 'someone@x.com', 0 ),
        ];

        $result = $this->privacy()->erase( 'jane@x.com' );

        $this->assertTrue( $result['done'] );
        foreach ( [ 1, 2 ] as $id ) {
            $this->assertSame( 'deleted-' . $id . '@example.invalid', self::$rows[ $id ]['email'] );
            $this->assertSame( '', self::$rows[ $id ]['pay_link'], 'the invitation link is removed' );
            $this->assertSame( 0, self::$rows[ $id ]['user_id'] );
        }
        $this->assertSame( 'someone@x.com', self::$rows[3]['email'], "other people's orders stay" );
    }

    public function testAFullBatchIsNotReportedDone()
    {
        Functions\when( 'get_user_by' )->justReturn( false );
        self::$rows = [];
        for ( $i = 1; $i <= Privacy::ERASE_BATCH + 5; $i++ ) {
            self::$rows[ $i ] = $this->order( $i, 'jane@x.com', 0 );
        }
        $privacy = $this->privacy();

        $first = $privacy->erase( 'jane@x.com', 1 );
        $this->assertFalse( $first['done'], 'more orders are left' );
        $second = $privacy->erase( 'jane@x.com', 2 );
        $this->assertTrue( $second['done'] );
        $this->assertSame( [], array_filter( self::$rows, fn( $row ) => 'jane@x.com' === $row['email'] ) );
    }
}
