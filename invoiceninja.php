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

// Security checks
defined( 'ABSPATH' ) or die( 'Unauthorized' );
function_exists( 'add_action' ) or die( 'Unauthorized' );

class InvoiceNinjaPlugin
{
   public $plugin;

   function __construct()
   {
      $this->plugin = plugin_basename( __FILE__ );
   }

   function register()
   {
      add_action( 'admin_menu', [ $this, 'add_admin_pages' ] );

      add_filter( "plugin_action_links_$this->plugin", [ $this, 'settings_link' ] );
   }

   public function settings_link( $links )
   {
      $settings_link = '<a href="admin.php?page=invoiceninja">Settings</a>';

      array_push( $links, $settings_link );

      return $links;
   }

   public function add_admin_pages() 
   {
      add_menu_page( 
         'Invoice Ninja', 
         'Invoice Ninja', 
         'manage_options', 
         'invoiceninja', 
         [ $this, 'admin_index' ], 
         'dashicons-money-alt'
      );
   }

   public function admin_index()
   {
      require_once plugin_dir_path( __FILE__ ) . 'templates/settings.php';
   }

   function activate()
   {

   }

   function deactivate()
   {

   }
}

$plugin = new InvoiceNinjaPlugin();
$plugin->register();

register_activation_hook( __FILE__, [ $plugin, 'activate' ] );
register_deactivation_hook( __FILE__, [ $plugin, 'deactivate' ] );