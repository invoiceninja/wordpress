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
               require_once "$this->plugin_path/templates/settings.php";
            },
            'icon_url' => 'dashicons-money-alt',
            'position' => 110,
         ],
      ];

      $this->settings->addPages( $pages );

      $this->setSections();
      $this->setSettings();
      $this->setFields();

      $this->settings->register();

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
         ->addPages( $pages )
         ->withSubpage( 'Settings' )
         ->addSubpages( $subpages )
         ->register();
      */

      add_filter( 'plugin_action_links_' . $this->plugin_basename, [ $this, 'addLink' ] );  
   }    

   public function renderPage()
   {
      require_once $this->plugin_path . 'templates/settings.php';
   } 
 
   public function addLink( $links )
   {
      $settings_link = '<a href="admin.php?page=invoiceninja">Settings</a>';
 
      array_push( $links, $settings_link );
 
      return $links;
   }

   public function setSettings()
   {
      $args = [
         [
            'option_group' => 'invoiceninja',
            'option_name' => 'invoiceninja_api_url',
         ],
      ];

      $this->settings->setSettings( $args );
   }

   public function setSections()
   {
      $args = [
         [
            'id' => 'invoiceninja_admin_index',
            'title' => 'Settings',
            'callback' => function() { echo "set sections"; },
            'page' => 'invoiceninja',
            
         ]
      ];

      $this->settings->setSections( $args );
   }   

   public function setFields()
   {
      $args = [
         [
            'id' => 'invoiceninja_api_url',
            'title' => 'API URL',
            'callback' => function() 
            { 
               $value = esc_attr( get_option( 'invoiceninja_api_url' ) );
               echo '<input type="text" class="regular-text" value="' . $value . '" name="invoiceninja_api_url" placeholder="invoices.domain.com"/>'; 
            },
            'page' => 'invoiceninja',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_api_url',
               //'class' => '',
            ]
         ]
      ];

      $this->settings->setFields( $args );
   }
}