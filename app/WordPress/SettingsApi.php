<?php

/**
 * @package Invoice Ninja
 */

namespace App\WordPress;

use \App\Controllers\SettingsController;
use \App\Controllers\ProductController;
use \App\InvoiceNinja\ProfileApi;
use \App\InvoiceNinja\ProductApi;

class SettingsApi
{
    public $admin_pages = [];

    public $admin_subpages = [];

    public $settings = [];

    public $sections = [];

    public $fields = [];

    public function register()
    {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueueStyles' ] );

        if ( ! empty( $this->admin_pages) ) 
        {
            add_action( 'admin_menu', [ $this, 'addAdminMenu' ] );
        }

        if ( ! empty( $this->settings ) )
        {
            
            add_action( 'admin_init', [ $this, 'registerCustomFields' ] );
        }

        add_action('updated_option', [ $this, 'optionUpdated' ], 10, 3);
    }

    public function enqueueStyles()
    {        
        wp_enqueue_style( 'custom-page-styles', plugins_url( '/../../assets/css/settings.css', __FILE__ ) );
        wp_enqueue_script( 'custom-script', plugins_url( '/../../assets/js/settings.js', __FILE__ ) );        
    }

    function optionUpdated($option_name, $old_value, $new_value) 
    {
        if ($option_name === 'invoiceninja_company_key' || $option_name === 'invoiceninja_api_url') 
        {
            if ($old_value !== $new_value) 
            {
                SettingsController::loadProfile();
                ProductController::loadProducts();
            }
        }
    }

    public function addPages( array $pages )
    {
        $this->admin_pages = $pages;

        return $this;
    }
    
    public function addSubpages( array $pages )
    {
        $this->admin_subpages = array_merge( $this->admin_subpages, $pages );

        return $this;
    }

    public function withSubpage( string $title = null )
    {
        if ( empty( $this->admin_pages ) ) 
        {
            return $this;
        }

        $admin_page = $this->admin_pages[0];

        $subpages = [
            [
                'parent_slug' => $admin_page['menu_slug'],
                'page_title' => $admin_page['page_title'],
                'menu_title' => $title ?? $admin_page['menu_title'],
                'capability' => $admin_page['capability'],
                'menu_slug' => $admin_page['menu_slug'],
                'callback' => $admin_page['callback'],
            ]                   
        ];

        $this->admin_subpages = $subpages;

        return $this;
    }

    public function addAdminMenu()
    {
        foreach ($this->admin_pages as $page)
        {
            add_menu_page(
                $page['page_title'],
                $page['menu_title'],
                $page['capability'],
                $page['menu_slug'],
                $page['callback'],
                $page['icon_url'],
                $page['position'],
            );
        }

        foreach ($this->admin_subpages as $page)
        {
            add_submenu_page(
                $page['parent_slug'],
                $page['page_title'],
                $page['menu_title'],
                $page['capability'],
                $page['menu_slug'],
                $page['callback'],
            );
        }
    }

    public function setSettings( array $settings )
    {
        $this->settings = $settings;

        return $this;
    }

    public function setSections( array $sections )
    {
        $this->sections = $sections;

        return $this;
    }

    public function setFields( array $fields )
    {
        $this->fields = $fields;

        return $this;
    }

    public function registerCustomFields()
    {
        foreach ( $this->settings as $setting)
        {
            register_setting( 
                $setting['option_group'], 
                $setting['option_name'], 
            );
        }

        foreach ( $this->sections as $section )
        {
            add_settings_section(
                $section['id'],
                $section['title'],
                ( isset( $section['callback'] ) ? $section['callback'] : '' ),
                $section['page'],
            );
        }

        foreach ($this->fields as $field)
        {
            add_settings_field(
                $field['id'],
                $field['title'],
                ( isset( $field['callback'] ) ? $field['callback'] : '' ),
                $field['page'],
                $field['section'],
                ( isset( $field['args'] ) ? $field['args'] : '' ),
            );
        }

        /*
        if ( isset($_POST['submit']) && isset($_POST['option_page']) && substr( $_POST['option_page'], 0, 12 ) === 'invoiceninja' ) 
        {
            SettingsController::loadProfile();            
        }
        */

        if (isset($_POST['my_plugin_action']) && $_POST['my_plugin_action'] == 'run_code') 
        {
            // Check the nonce for security
            if ( ! isset($_POST['my_plugin_nonce']) || !wp_verify_nonce($_POST['my_plugin_nonce'], 'my_plugin_run_code') ) {
                wp_die('Security check failed');
            }
    
            ProductController::loadProducts();
        }
    
    }
}