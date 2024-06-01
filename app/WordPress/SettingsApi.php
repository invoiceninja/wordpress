<?php

/**
 * @package Invoice Ninja
 */

namespace App\WordPress;

class SettingsApi
{
    public $admin_pages = [];

    public $admin_subpages = [];

    public $settings = [];

    public $sections = [];

    public $fields = [];

    public function register()
    {
        if ( ! empty( $this->admin_pages) ) 
        {
            add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        }
    }

    public function add_pages( array $pages )
    {
        $this->admin_pages = $pages;

        return $this;
    }
    
    public function add_subpages( array $pages )
    {
        $this->admin_subpages = array_merge( $this->admin_subpages, $pages );

        return $this;
    }

    public function with_subpage( string $title = null )
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

    public function add_admin_menu()
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

    public function set_settings( array $settings )
    {
        $this->settings = $settings;

        return $this;
    }

    public function set_sections( array $sections )
    {
        $this->sections = $sections;

        return $this;
    }

    public function set_fields( array $fields )
    {
        $this->fields = $fields;

        return $this;
    }

    public function register_custom_fields()
    {
        foreach ( $this->settings as $setting)
        {
            register_settings( 
                $setting['option_group'], 
                $setting['option_name'], 
                ( isset( $setting['callback'] ) ? $setting['callback'] : '' ),
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
    }
}