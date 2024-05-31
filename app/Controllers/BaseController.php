<?php

/**
 * @package Invoice Ninja
 */

namespace App\Controllers;

class BaseController
{
    public $plugin_path;

    public function __construct()
    {
        $this->plugin_path = plugin_dir_path( dirname( __FILE__, 1 ) );
    }
}