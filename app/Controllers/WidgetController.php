<?php

/**
 * @package Invoice Ninja
 */

namespace App\Controllers;

use \App\WordPress\Widgets\ProductWidget;

class WidgetController extends BaseController
{
    public function register()
    {
        $widget = new ProductWidget();
        $widget->register();
    }
}