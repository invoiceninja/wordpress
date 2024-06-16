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
               $product_label = get_option( 'invoiceninja_product_label', 'Product' );
               $products_label = get_option( 'invoiceninja_products_label', 'Products' );

               $company = '';

               if ($profile = json_decode( get_option( 'invoiceninja_profile' ) )) {
                  $settings = $profile->settings;

                  $args = array(
                     'post_type'      => 'attachment',
                     'post_status'    => 'inherit',
                     'posts_per_page' => 1,
                     'fields'         => 'ids',
                     's'              => 'invoiceninja_plugin',
                  );
 
                  $attachments = get_posts( $args );
                  if ( $attachments ) {
                     $attachment_id = $attachments[0];                    
                     $logo_url = wp_get_attachment_url($attachment_id);
                     $company .= '<img src="' . $logo_url . '" height="80" style="float: left;padding-right: 16px;"/>';
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
      add_filter( 'pre_update_option_invoiceninja_api_token', [ $this, 'filterToken' ], 10, 2 );
   }    

   public function filterToken( $new_value, $old_value )
   {
      if ( strlen($new_value) > 10 && preg_match('/^\*+$/', substr( $new_value, 10 ) ) === 1 ) {
         return $old_value;
      }

      return $new_value;
   }

   public static function loadProfile()
   {
      $profile = ProfileApi::load();
      update_option( 'invoiceninja_profile', json_encode( $profile ) );
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
            'option_name' => 'invoiceninja_sync_clients',
            'option_sanitize' => 'intval',
         ],
         [
            'option_group' => 'invoiceninja_settings',
            'option_name' => 'invoiceninja_included_roles',
         ],
         [
            'option_group' => 'invoiceninja_settings',
            'option_name' => 'invoiceninja_match_found',
         ],
         [
            'option_group' => 'invoiceninja_settings',
            'option_name' => 'invoiceninja_sync_products',
            'option_sanitize' => 'intval',
         ],
         [
            'option_group' => 'invoiceninja_settings',
            'option_name' => 'invoiceninja_online_purchases',
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
            'option_name' => 'invoiceninja_products_css',
         ],
         [
            'option_group' => 'invoiceninja_settings',
            'option_name' => 'invoiceninja_product_css',
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
            'page' => 'invoiceninja_credentials',            
         ],
         [
            'id' => 'invoiceninja_admin_index',
            'title' => '',
            'callback' => function() {},
            'page' => 'invoiceninja_clients',
         ],
         [
            'id' => 'invoiceninja_admin_index',
            'title' => '',
            'callback' => function() {},
            'page' => 'invoiceninja_products',
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
         [
            'id' => 'invoiceninja_admin_index',
            'title' => '',
            'callback' => function() {},
            'page' => 'invoiceninja_custom_css',            
         ],
      ];

      $this->settings->setSections( $args );
   }   

   public function setFields()
   {
      $product_label = esc_attr( get_option( 'invoiceninja_product_label', 'Product' ) );
      $products_label = esc_attr( get_option( 'invoiceninja_products_label', 'Products' ) );

      $args = [
         [
            'id' => 'invoiceninja_api_token',
            'title' => 'Token',
            'callback' => function() 
            { 
               $value = esc_attr( get_option( 'invoiceninja_api_token' ) );
               if ( $value ) {
                  $value = substr( $value, 0, 10) . '******************';
               }
               echo '<input type="text" class="regular-text code" value="' . $value . '" name="invoiceninja_api_token"' . ( $value ? '' : ' autofocus' ) . ' required/>';
               echo '<p class="description">API tokens can be created in Invoice Ninja on Settings > Account Management</p>'; 
            },
            'page' => 'invoiceninja_credentials',
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
            'page' => 'invoiceninja_credentials',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_api_url',
               //'class' => '',
            ]
         ],
         [
            'id' => 'invoiceninja_sync_clients',
            'title' => 'Sync Clients',
            'callback' => function() 
            { 
               $value = get_option( 'invoiceninja_sync_clients' );
               echo '<label for="invoiceninja_sync_clients"><input type="checkbox" ' . checked(1, $value, false) . ' value="1" id="invoiceninja_sync_clients" name="invoiceninja_sync_clients"/> Automatically export clients to Invoice Ninja</label>'; 
            },
            'page' => 'invoiceninja_clients',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_sync_clients',
               //'class' => '',
            ]
         ],
         [
            'id' => 'invoiceninja_match_found',
            'title' => 'If Match Is Found',
            'callback' => function() 
            { 
               global $wp_roles;

               $value = get_option( 'invoiceninja_match_found' );
               
               echo '<select name="invoiceninja_match_found">
                        <option value="skip" ' . ($value == 'skip' ? 'SELECTED' : '') . '>Skip</option>
                        <option value="update" ' . ($value == 'update' ? 'SELECTED' : '') . '>Update</option>
                     </select>';
            },
            'page' => 'invoiceninja_clients',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_included_roles',
               //'class' => '',
            ]
         ],
         [
            'id' => 'invoiceninja_included_roles',
            'title' => 'Included Roles',
            'callback' => function() 
            { 
               global $wp_roles;

               $value = get_option( 'invoiceninja_included_roles', [] );
               
               echo '<select name="invoiceninja_included_roles[]" multiple size="5">';

               $roles = $wp_roles->roles;

               foreach ($roles as $role_key => $role) {
                  $selected = in_array( $role_key, $value ) ? 'selected="selected"' : '';
                  echo '<option value="' . $role_key . '" ' . $selected . '>' . $role['name'] . '</option>';
               }           

               echo '</select>';
               echo '<p class="description">Ctrl + click to select multiple</p>'; 
            },
            'page' => 'invoiceninja_clients',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_included_roles',
               //'class' => '',
            ]
         ],
         [
            'id' => 'invoiceninja_sync_products',
            'title' => 'Sync ' . $products_label,
            'callback' => function() 
            { 
               $value = get_option( 'invoiceninja_sync_products' );
               $products_label = get_option( 'invoiceninja_products_label' );
               echo '<label for="invoiceninja_sync_products"><input type="checkbox" ' . checked(1, $value, false) . ' value="1" id="invoiceninja_sync_products" name="invoiceninja_sync_products"/> Automatically import ' . strtolower( $products_label ) . ' from Invoice Ninja</label>'; 
            },
            'page' => 'invoiceninja_products',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_sync_products',
               //'class' => '',
            ]
         ],
         [
            'id' => 'invoiceninja_online_purchases',
            'title' => 'Online Purchases',
            'callback' => function() 
            { 
               $value = get_option( 'invoiceninja_online_purchases', 'disabled' );
               
               echo '<select name="invoiceninja_online_purchases">
                  <option value="disabled" ' . ( $value == 'disabled' ? 'SELECTED' : '' ) . '>Disabled</option>
                  <option value="single" ' . ( $value == 'single' ? 'SELECTED' : '' ) . '>Single [Buy Now]</option>
                  <option value="multiple" ' . ( $value == 'multiple' ? 'SELECTED' : '' ) . '>Multiple [Add to Cart]</option>
               </select>';
            },
            'page' => 'invoiceninja_products',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_online_purchases',
               //'class' => '',
            ]
         ],
         [
            'id' => 'invoiceninja_product_label',
            'title' => 'Product Label',
            'callback' => function() 
            { 
               $value = esc_attr( get_option( 'invoiceninja_product_label', 'Product' ) );
               echo '<input type="text" class="regular-text" value="' . $value . '" name="invoiceninja_product_label" required/>'; 
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
               $value = esc_attr( get_option( 'invoiceninja_products_label', 'Products' ) );
               echo '<input type="text" class="regular-text" value="' . $value . '" name="invoiceninja_products_label" required/>'; 
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

               $products_label = esc_attr( get_option( 'invoiceninja_products_label', 'Products' ) );
               $product_label = esc_attr( get_option( 'invoiceninja_product_label', 'Product' ) );
         
               echo '<div><textarea class="code" style="width:100%" rows="8" id="invoiceninja_product_template" name="invoiceninja_product_template">' . $value . '</textarea></div>';
               echo '<p style="float:right;">
                        <a href="#" onclick=\'alert("$product\n$product_id\n$page_url\n$description\n$price\n$image\n$custom1\n$custom2\n$custom3\n$custom4\n")\'>Variables</a> | 
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

               $products_label = esc_attr( get_option( 'invoiceninja_products_label', 'Products' ) );
               $product_label = esc_attr( get_option( 'invoiceninja_product_label', 'Product' ) );

               echo '<div><textarea class="code" style="width:100%" rows="6" id="invoiceninja_image_template" name="invoiceninja_image_template">' . $value . '</textarea></div>';
               echo '<p style="float:right;">
                        <a href="#" onclick=\'alert("$image_url\n$product")\'>Variables</a> | 
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
            'id' => 'invoiceninja_product_css',
            'title' => $product_label . ' Page',
            'callback' => function() 
            { 
               $value = esc_attr( get_option( 'invoiceninja_product_css' ) );
               $product_label = esc_attr( get_option( 'invoiceninja_product_label', 'Product' ) );

               echo '<textarea class="code" style="width:100%" rows="6" name="invoiceninja_product_css">' . $value . '</textarea>'; 
               echo '<p class="description">CSS to include on the individual ' . strtolower( $product_label ) . ' page</p>'; 
            },
            'page' => 'invoiceninja_custom_css',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_product_css',
               //'class' => '',
            ]
         ],
         [
            'id' => 'invoiceninja_products_css',
            'title' => $products_label . ' Page',
            'callback' => function() 
            { 
               $value = esc_attr( get_option( 'invoiceninja_products_css' ) );
               $products_label = esc_attr( get_option( 'invoiceninja_products_label', 'Products' ) );

               echo '<textarea class="code" style="width:100%" rows="6" name="invoiceninja_products_css">' . $value . '</textarea>'; 
               echo '<p class="description">CSS to include on the ' . strtolower( $products_label ) . ' list page</p>'; 
            },
            'page' => 'invoiceninja_custom_css',
            'section' => 'invoiceninja_admin_index',
            'args' => [
               'label_for' => 'invoiceninja_products_css',
               //'class' => '',
            ]
         ],
      ];

      $this->settings->setFields( $args );
   }
}