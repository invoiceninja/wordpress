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
      $logo_url = plugins_url( '/../../assets/images/logo.svg', __FILE__ );

      $pages = [
         [
            'page_title' => 'Inovice Ninja',
            'menu_title' => 'Invoice Ninja',
            'capability' => 'manage_options',
            'menu_slug' => 'invoiceninja',
            'callback' => function() 
            {
               $product_label = get_option( 'invoiceninja_product_label' );
               if ( ! $product_label ) {
                  $product_label = 'Product';
               }
            
               $products_label = get_option( 'invoiceninja_products_label' );
               if ( ! $products_label ) {
                  $products_label = 'Products';
               }

               $company = '';

               if ($profile = json_decode( get_option( 'invoiceninja_profile' ) )) {
                  $settings = $profile->settings;

                  if ($settings->company_logo) {
                     $company .= '<img src="' . $settings->company_logo . '" height="80" style="float: left;padding-right: 16px;"/>';
                  }

                  $company .= '<h1 class="title" style="padding-top: 0px">' . esc_attr( $settings->name ) . '</h1>';
                  
                  if ( $settings->website ) {
                     $company .= '<a href="' . esc_attr( $settings->website ) . '" target="_blank">' . esc_attr( $settings->website ) . '</a>';
                  }

                  $args = [
                     'post_type'  => 'invoiceninja_product',
                     'posts_per_page' => -1,
                  ];

                  $statuses = get_post_statuses();
                  $query = new \WP_Query($args);
                  $total_count = $query->found_posts;
                  $company .= '<div style="padding-top: 8px">' . $total_count . ' ' . ( $total_count == 1 ? $product_label : $products_label );
                  $has_page = false;

                  if ( $page_id = get_option( 'invoiceninja_product_page_id' ) ) {
                     if ( $page = get_post( $page_id ) ) {
                        $has_page = $page->post_status != 'trash';
                     }
                  }

                  if ( $has_page && $total_count > 0 ) {
                     $company .= ' • <a href="/' . strtolower( $products_label ) . '" target="_blank">View Page</a> [' . $statuses[$page->post_status] . ']';
                  }

                  $company .= '</div>';
               }

               require_once "$this->plugin_path/templates/settings.php";
            },
            //'icon_url' => 'dashicons-store',
            'icon_url' => 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( $logo_url ) ),
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
            'option_group' => 'invoiceninja_settings',
            'option_name' => 'invoiceninja_api_url',
         ],
         [
            'option_group' => 'invoiceninja_settings',
            'option_name' => 'invoiceninja_api_token',
         ],
         [
            'option_group' => 'invoiceninja_settings',
            'option_name' => 'invoiceninja_products_label',
         ],
         [
            'option_group' => 'invoiceninja_settings',
            'option_name' => 'invoiceninja_product_label',
         ],
         [
            'option_group' => 'invoiceninja_settings',
            'option_name' => 'invoiceninja_product_template',
         ],
         [
            'option_group' => 'invoiceninja_settings',
            'option_name' => 'invoiceninja_image_template',
         ],
         [
            'option_group' => 'invoiceninja_settings',
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
            'id' => 'invoiceninja_api_token',
            'title' => 'Token',
            'callback' => function() 
            { 
               $value = esc_attr( get_option( 'invoiceninja_api_token' ) );
               echo '<input type="text" class="regular-text code" value="' . $value . '" name="invoiceninja_api_token"' . ( $value ? '' : ' autofocus' ) . '/>';
               echo '<p class="description">Tokens can be created in Invoice Ninja on Settings > Account Management</p>'; 
            },
            'page' => 'invoiceninja_configuration',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_api_token',
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

               $products_label = esc_attr( get_option( 'invoiceninja_products_label' ) );
               if ( ! $products_label ) {
                  $products_label = 'Products';
               }

               $product_label = esc_attr( get_option( 'invoiceninja_product_label' ) );
               if ( ! $product_label ) {
                  $product_label = 'Product';
               }
         
               echo '<div><textarea class="code" style="width:100%" rows="8" id="invoiceninja_product_template" name="invoiceninja_product_template">' . $value . '</textarea></div>';
               echo '<p style="float:right;">
                        <a href="#" onclick=\'alert("$post_url\n$title\n$content\n$price\n$image\n$custom1\n$custom2\n$custom3\n$custom4")\'>Variables</a> | 
                        <a href="#" onclick=\'document.querySelector("#invoiceninja_product_template").value = ' . json_encode( INVOICENINJA_DEFAULT_PRODUCT_TEMPLATE ) . ';return false;\'>Reset</a>
                     </p>';
               echo '<p class="description" style="float:left;">HTML template for each ' . strtolower( $product_label ) . ' on the ' . strtolower( $products_label ) . ' page</p>';                
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

               $products_label = esc_attr( get_option( 'invoiceninja_products_label' ) );
               if ( ! $products_label ) {
                  $products_label = 'Products';
               }

               $product_label = esc_attr( get_option( 'invoiceninja_product_label' ) );
               if ( ! $product_label ) {
                  $product_label = 'Product';
               }

               echo '<div><textarea class="code" style="width:100%" rows="8" id="invoiceninja_image_template" name="invoiceninja_image_template">' . $value . '</textarea></div>';
               echo '<p style="float:right;">
                        <a href="#" onclick=\'alert("$image_url\n$title")\'>Variables</a> | 
                        <a href="#" onclick=\'document.querySelector("#invoiceninja_image_template").value = ' . json_encode( INVOICENINJA_DEFAULT_IMAGE_TEMPLATE ) . ';return false;\'>Reset</a>
                     </p>';
               echo '<p class="description">HTML template for each image on the ' . strtolower( $products_label ) . ' page</p>';
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

               echo '<textarea class="code" style="width:100%" rows="6" name="invoiceninja_custom_css">' . $value . '</textarea>'; 
               echo '<p class="description">CSS to include on the ' . strtolower( $products_label ) . ' page</p>'; 
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