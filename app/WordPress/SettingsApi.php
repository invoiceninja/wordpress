<?php

/**
 * @package Invoice Ninja
 */

namespace App\WordPress;

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
        wp_enqueue_style( 'custom-page-styles', plugins_url( '/../../assets/css/settings.css?t=' . time(), __FILE__ ) );
        wp_enqueue_script( 'custom-script', plugins_url( '/../../assets/js/settings.js', __FILE__ ) );        
    }

    function optionUpdated($option_name, $old_value, $new_value) 
    {
        if ($option_name === 'invoiceninja_api_token' || $option_name === 'invoiceninja_api_url') {
            update_option('invoiceninja_profile', ProfileApi::load());
        } else if ($option_name === 'invoiceninja_product_label' 
            || $option_name === 'invoiceninja_products_label' 
            || $option_name === 'invoiceninja_product_template' 
            || $option_name === 'invoiceninja_image_template') {
                ProductController::loadProducts();
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

        if (isset($_POST['invoiceninja_action']) && $_POST['invoiceninja_action'] == 'refresh_company') 
        {
            if ( ! isset($_POST['invoiceninja_nonce']) || !wp_verify_nonce($_POST['invoiceninja_nonce'], 'invoiceninja_refresh_company') ) {
                wp_die('Security check failed');
            }
    
            update_option('invoiceninja_profile', ProfileApi::load());

            add_settings_error(
                'invoiceninja',
                'imported_products',
                'Successfully refreshed company!',
                'success'
            );
        } 
        
        if (isset($_POST['invoiceninja_action']) && $_POST['invoiceninja_action'] == 'import_products') 
        {
            if ( ! isset($_POST['invoiceninja_nonce']) || !wp_verify_nonce($_POST['invoiceninja_nonce'], 'invoiceninja_import_products') ) {
                wp_die('Security check failed');
            }
    
            ProductController::loadProducts(true);

            $products_label = get_option( 'invoiceninja_products_label' );
            if ( ! $products_label ) {
               $products_label = 'Products';
            }

            add_settings_error(
                'invoiceninja',
                'imported_products',
                'Successfully imported ' . strtolower( $products_label ) . '!',
                'success'
            );
        }    
    }
}