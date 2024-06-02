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
            'icon_url' => 'dashicons-store',
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
         [
            'option_group' => 'invoiceninja',
            'option_name' => 'invoiceninja_company_key',
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
            'callback' => function() { 
               $profile = get_option( 'invoiceninja_profile' );
               echo "Storefront Configuration: $profile"; 
            },
            'page' => 'invoiceninja',
            
         ],
      ];

      $this->settings->setSections( $args );
   }   

   public function setFields()
   {
      $args = [
         [
            'id' => 'invoiceninja_company_key',
            'title' => 'Company Key',
            'callback' => function() 
            { 
               $value = esc_attr( get_option( 'invoiceninja_company_key' ) );
               echo '<input type="text" class="regular-text code" value="' . $value . '" name="invoiceninja_company_key" required/>';
               echo '<p class="description">Enable the Storefront feature on Settings > Client Portal to view the company key</p>'; 
            },
            'page' => 'invoiceninja',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_company_key',
               //'class' => '',
               'help' => 'test',
            ]
         ],
         [
            'id' => 'invoiceninja_api_url',
            'title' => 'URL',
            'callback' => function() 
            { 
               $value = esc_attr( get_option( 'invoiceninja_api_url' ) );
               echo '<input type="url" class="regular-text code" value="' . $value . '" name="invoiceninja_api_url" placeholder="https://invoicing.co"/>'; 
               echo '<p class="description">Leave this field blank if you\'re using the hosted platform</p>'; 
            },
            'page' => 'invoiceninja',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_api_url',
               //'class' => '',
            ]
         ],
      ];

      $this->settings->setFields( $args );
   }
}