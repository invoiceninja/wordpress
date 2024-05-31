<?php

/**
 * @package Invoice Ninja
 */

namespace App\Pages;

class Settings
{
    public function register()
    {
        add_action( 'admin_menu', [ $this, 'add_page' ] );

        add_filter( 'plugin_action_links_' . PLUGIN_BASENAME, [ $this, 'add_link' ] );  
    }    

    public function add_page() 
    {
       add_menu_page( 
          'Invoice Ninja', 
          'Invoice Ninja', 
          'manage_options', 
          'invoiceninja', 
          [ $this, 'render_page' ], 
          'dashicons-money-alt'
       );
    }

    public function render_page()
    {
       require_once PLUGIN_PATH . 'templates/settings.php';
    } 
 
    public function add_link( $links )
    {
       $settings_link = '<a href="admin.php?page=invoiceninja">Settings</a>';
 
       array_push( $links, $settings_link );
 
       return $links;
    } 
 }