<?php

/**
 * Plugin Name:        Invoice Ninja
 * Plugin URI:         https://github.com/invoiceninja/wordpress
 * Description:        WordPress plugin for Invoice Ninja
 * Version:            1.0.0
 * Author:             Inovice Ninja
 * Author URI:         https://invoiceninja.com
 * GitHub Plugin URI:  https://github.com/invoiceninja/wordpress
 * Primary Branch:     main
 *
 * License:            AAL License
 * License URI:        https://opensource.org/license/attribution-php
 */

// Security check
defined( 'ABSPATH' ) or die( 'Unauthorized' );

if ( file_exists( dirname( __FILE__ ) . '/vendor/autoload.php' ) )
{
   require_once dirname( __FILE__ ) . '/vendor/autoload.php';
}

/*
function activate_invoiceninja_plugin()
{
   Lib\Base\Activate::activate();
}
register_activation_hook( __FILE__, 'activate_invoiceninja_plugin' );

function deactivate_invoiceninja_plugin()
{
   Lib\Base\Deactivate::deactivate();
}
register_deactivation_hook( __FILE__, 'deactivate_invoiceninja_plugin' );
*/

if ( class_exists( 'App\\Init') )
{
   App\Init::register_services();
}