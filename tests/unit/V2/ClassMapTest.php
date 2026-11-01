<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Tests\TestCase;

/**
 * src/ and InvoiceNinja/ share the InvoiceNinja\ namespace; a class in both would
 * silently load whichever directory comes first.
 */
class ClassMapTest extends TestCase
{
    public function testNoClassExistsInBothDirectories()
    {
        $root  = dirname( __DIR__, 3 );
        $names = function ( string $dir ) use ( $root ): array {
            $out = [];
            $it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( "$root/$dir" ) );
            foreach ( $it as $file ) {
                if ( $file->getExtension() === 'php' ) {
                    $out[] = substr( $file->getPathname(), strlen( "$root/$dir/" ) );
                }
            }
            return $out;
        };
        $this->assertSame( [], array_values( array_intersect( $names( 'src' ), $names( 'InvoiceNinja' ) ) ) );
    }
}
