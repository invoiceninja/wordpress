<?php

/**
 * @package Invoice Ninja
 */

namespace App\Controllers;

class BaseController
{
    public $plugin_basename;

    public $plugin_path;

    public $plugin_url;

    public function __construct()
    {
        $this->plugin_basename = plugin_basename( dirname( __FILE__, 3 ) ) . '/invoiceninja.php';
        $this->plugin_path = plugin_dir_path( dirname( __FILE__, 2 ) );
        $this->plugin_url = plugin_dir_url( dirname( __FILE__, 2 ) );
    }
}