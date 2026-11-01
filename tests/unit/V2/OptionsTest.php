<?php

namespace InvoiceNinja\Tests\V2;

use InvoiceNinja\Core\Options;
use InvoiceNinja\Tests\TestCase;

class OptionsTest extends TestCase
{
    public function testDefaultsWhenNothingIsStored()
    {
        $options = new Options();
        $this->assertSame( 'info', $options->setting( 'log_level' ) );
        $this->assertSame( 'instant', $options->setting( 'notifications.leads' ) );
        $this->assertNull( $options->setting( 'nope.nothing' ) );
    }

    public function testSaveKeepsKnownKeysAndTypes()
    {
        $options = new Options();
        $saved   = $options->saveSettings( [
            'log_retention_days' => '45',
            'delete_data_on_uninstall' => 'true',
            'modules'            => [ 'store' => 0, 'unknown' => true ],
            'notifications'      => [ 'recipients' => '  a@x.com ' ],
            'evil'               => 'drop me',
        ] );

        $this->assertSame( 45, $saved['log_retention_days'] );
        $this->assertTrue( $saved['delete_data_on_uninstall'] );
        $this->assertFalse( $saved['modules']['store'] );
        $this->assertArrayNotHasKey( 'unknown', $saved['modules'] );
        $this->assertArrayNotHasKey( 'evil', $saved );
        $this->assertSame( 'a@x.com', $saved['notifications']['recipients'] );
        $this->assertTrue( $saved['modules']['account'], 'untouched keys keep their values' );
    }
}
