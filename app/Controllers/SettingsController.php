<?php

/**
 * @package Invoice Ninja
 */

namespace App\Controllers;

use \App\WordPress\SettingsApi;
use \App\InvoiceNinja\ProfileApi;

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
               $company = '';

               if ($profile = json_decode( get_option( 'invoiceninja_profile' ) )) {
                  $settings = $profile->settings;

                  if ($settings->company_logo) {
                     $company .= '<img src="' . $settings->company_logo . '" height="80" style="float: left;padding-right: 16px;"/>';
                  }

                  $company .= '<h1 class="title" style="padding-top: 0px">' . $settings->name . '</h1>';
                  
                  if ( $settings->website ) {
                     $company .= '<a href="' . $settings->website . '" target="_blank">' . $settings->website . '</a>';
                  }

                  $args = [
                     'post_type'  => 'invoiceninja_product',
                     'posts_per_page' => -1,
                  ];

                  $query = new \WP_Query($args);

                  $total_count = $query->found_posts;

                  if ($total_count > 0) {
                     $product_label = $total_count == 1 ? get_option( 'invoiceninja_product_label', 'Product' ) : get_option( 'invoiceninja_products_label', 'Products' );
                     $company .= '<div style="padding-top: 4px">' . $total_count . ' ' . $product_label . '</div>';
                  }
               } else if ( get_option( 'invoiceninja_company_key' ) ) {
                  $company = '<b>Invalid company key or URL</b>';
               }

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

   public static function loadProfile()
   {
      update_option('invoiceninja_profile', ProfileApi::load());
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
            'option_group' => 'invoiceninja_configuration',
            'option_name' => 'invoiceninja_api_url',
         ],
         [
            'option_group' => 'invoiceninja_configuration',
            'option_name' => 'invoiceninja_company_key',
         ],
         [
            'option_group' => 'invoiceninja_localization',
            'option_name' => 'invoiceninja_products_label',
         ],
         [
            'option_group' => 'invoiceninja_localization',
            'option_name' => 'invoiceninja_product_label',
         ],
         [
            'option_group' => 'invoiceninja_templates',
            'option_name' => 'invoiceninja_product_template',
         ],
         [
            'option_group' => 'invoiceninja_templates',
            'option_name' => 'invoiceninja_image_template',
         ],
         [
            'option_group' => 'invoiceninja_templates',
            'option_name' => 'invoiceninja_custom_css',
         ],
      ];

      $this->settings->setSettings( $args );
   }

   public function setSections()
   {
      $args = [
         [
            'id' => 'invoiceninja_admin_index',
            'title' => '',
            'callback' => function() {},
            'page' => 'invoiceninja_configuration',            
         ],
         [
            'id' => 'invoiceninja_admin_index',
            'title' => '',
            'callback' => function() {},
            'page' => 'invoiceninja_localization',
         ],
         [
            'id' => 'invoiceninja_admin_index',
            'title' => '',
            'callback' => function() {},
            'page' => 'invoiceninja_templates',            
         ],
      ];

      $this->settings->setSections( $args );
   }   

   public function setFields()
   {
      $product_label = esc_attr( get_option( 'invoiceninja_product_label' ) );
      if ( ! $product_label ) {
         $product_label = 'Product';
      }

      $args = [
         [
            'id' => 'invoiceninja_company_key',
            'title' => 'Company Key',
            'callback' => function() 
            { 
               $value = esc_attr( get_option( 'invoiceninja_company_key' ) );
               echo '<input type="text" class="regular-text code" value="' . $value . '" name="invoiceninja_company_key"/>';
               echo '<p class="description">Enable the Storefront option on Settings > Client Portal to generate a company key</p>'; 
            },
            'page' => 'invoiceninja_configuration',
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
            'page' => 'invoiceninja_configuration',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_api_url',
               //'class' => '',
            ]
         ],
         [
            'id' => 'invoiceninja_product_label',
            'title' => 'Product Label',
            'callback' => function() 
            { 
               $value = esc_attr( get_option( 'invoiceninja_product_label' ) );
               echo '<input type="text" class="regular-text" value="' . $value . '" name="invoiceninja_product_label" placeholder="Product"/>'; 
               echo '<p class="description">Singular label to use for individual products</p>'; 
            },
            'page' => 'invoiceninja_localization',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_product_label',
               //'class' => '',
            ]
         ],
         [
            'id' => 'invoiceninja_products_label',
            'title' => 'Products Label',
            'callback' => function() 
            { 
               $value = esc_attr( get_option( 'invoiceninja_products_label' ) );
               echo '<input type="text" class="regular-text" value="' . $value . '" name="invoiceninja_products_label" placeholder="Products"/>'; 
               echo '<p class="description">Plural label to use for multiple products</p>'; 
            },
            'page' => 'invoiceninja_localization',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_products_label',
               //'class' => '',
            ]
         ],
         [
            'id' => 'invoiceninja_product_template',
            'title' => $product_label . ' Template',
            'callback' => function() 
            { 
               $value = esc_attr( get_option( 'invoiceninja_product_template' ) );
               if ( ! $value ) {
                  $value = INVOICENINJA_DEFAULT_PRODUCT_TEMPLATE;
               }

               $product_label = esc_attr( get_option( 'invoiceninja_product_label' ) );
               if ( ! $product_label ) {
                  $product_label = 'Product';
               }
         
               echo '<textarea class="code" cols="60" rows="8" name="invoiceninja_product_template">' . $value . '</textarea>'; 
               echo '<p class="description">HTML template for each ' . strtolower( $product_label ) . '</p>'; 
            },
            'page' => 'invoiceninja_templates',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_product_template',
               //'class' => '',
            ]
         ],
         [
            'id' => 'invoiceninja_image_template',
            'title' => 'Image Template',
            'callback' => function() 
            { 
               $value = esc_attr( get_option( 'invoiceninja_image_template' ) );
               if ( ! $value ) {
                  $value = INVOICENINJA_DEFAULT_IMAGE_TEMPLATE;
               }

               $product_label = esc_attr( get_option( 'invoiceninja_product_label' ) );
               if ( ! $product_label ) {
                  $product_label = 'Product';
               }

               echo '<textarea class="code" cols="60" rows="6" name="invoiceninja_image_template">' . $value . '</textarea>'; 
               echo '<p class="description">HTML template for each ' . strtolower( $product_label ) . ' image</p>';                
            },
            'page' => 'invoiceninja_templates',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_image_template',
               //'class' => '',
            ]
         ],
         [
            'id' => 'invoiceninja_custom_css',
            'title' => 'Custom CSS',
            'callback' => function() 
            { 
               $value = esc_attr( get_option( 'invoiceninja_custom_css' ) );

               $products_label = esc_attr( get_option( 'invoiceninja_products_label' ) );
               if ( ! $products_label ) {
                  $products_label = 'Products';
               }

               echo '<textarea class="code" cols="60" rows="6" name="invoiceninja_custom_css">' . $value . '</textarea>'; 
               echo '<p class="description">CSS to include on ' . strtolower( $products_label ) . ' page</p>'; 
            },
            'page' => 'invoiceninja_templates',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_custom_css',
               //'class' => '',
            ]
         ],
      ];

      $this->settings->setFields( $args );
   }
}