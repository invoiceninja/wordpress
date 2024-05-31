<?php

/**
 * @package Invoice Ninja
 */

namespace App\Controllers;

use \App\WordPress\SettingsApi;

class SettingsController extends BaseController
{
   public function __construct()
   {
      parent::__construct();

      $this->settings = new SettingsApi();
   }

   public function register()
   {
      $pages = [
         [
            'page_title' => 'Inovice Ninja',
            'menu_title' => 'Invoice Ninja',
            'capability' => 'manage_options',
            'menu_slug' => 'invoiceninja',
            'callback' => function() 
            {
               require_once $this->plugin_path . 'templates/settings.php';               
            },
            'icon_url' => 'dashicons-money-alt',
            'position' => 110,
         ],
      ];

      $this->settings->add_pages( $pages )->register();

      /*
      $subpages = [
         [
            'parent_slug' => 'invoiceninja',
            'page_title' => 'Inovice Ninja 1',
            'menu_title' => 'Invoice Ninja 1',
            'capability' => 'manage_options',
            'menu_slug' => 'invoiceninja-1',
            'callback' => function() { echo '<h1>Plugin 1</h1>'; },
            'icon_url' => 'dashicons-money-alt',
            'position' => 110,
         ],
      ];

      $this->settings
         ->add_pages( $pages )
         ->with_subpage( 'Settings' )
         ->add_subpages( $subpages )
         ->register();
      */

      add_filter( 'plugin_action_links_' . $this->plugin_basename, [ $this, 'add_link' ] );  
   }    

   public function render_page()
   {
      require_once $this->plugin_path . 'templates/settings.php';
   } 
 
   public function add_link( $links )
   {
      $settings_link = '<a href="admin.php?page=invoiceninja">Settings</a>';
 
      array_push( $links, $settings_link );
 
      return $links;
   } 
}