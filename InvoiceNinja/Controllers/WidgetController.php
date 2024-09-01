<?php

/**
 * @package Invoice Ninja
 */

namespace InvoiceNinja\Controllers;

use \InvoiceNinja\WordPress\Widgets\ProductWidget;

class WidgetController extends BaseController
{
    public function register()
    {
        $widget = new ProductWidget();
        $widget->register();
    }
}