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

function activate_invoiceninja_plugin()
{
   flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'activate_invoiceninja_plugin' );

function deactivate_invoiceninja_plugin()
{
   flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'deactivate_invoiceninja_plugin' );

define( 'INVOICENINJA_DEFAULT_PRODUCT_TEMPLATE', '<div class="wp-block-column wp-invoiceninja">
  <a href="$post_url">
    <h3>$title</h3>
    <div class="divider"></div>
    <h5 title="$content" class="truncated">$content</h5>
    <h5><b>$price</b></h5>
    $image
  </a>
</div>' );

define( 'INVOICENINJA_DEFAULT_PRODUCT_IMAGE_TEMPLATE', '<figure class="wp-block-post-featured-image">
  <img src="$image_url" alt="$title" decoding="async"/>
</figure>' );

if ( class_exists( 'App\\Init') )
{
   App\Init::start();
}