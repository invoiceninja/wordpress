<?php

/**
 * @package Invoice Ninja
 */

namespace App\WordPress\Widgets;

use WP_Widget;

class ProductWidget extends WP_Widget
{
    public $widget_ID;

    public $widget_name;

    public $widget_options = [];

    public $control_options = [];

    public function __construct()
    {
        $this->widget_ID = 'invoiceninja';
        $this->widget_name = 'Invoice Ninja';

        $this->widget_options = [
            'classname' => 'invoiceninja',
            'description' => '',
            'customize_selective_refresh' => true,
        ];

        $this->control_options = [
            'width' => 400,
            'height' => 350,
        ];
    }

    public function register()
    {
        parent::__construct(
            $this->widget_ID,
            $this->widget_name,
            $this->widget_options,
            $this->control_options,
        );

        add_action('widgets_init', [ $this, 'widgetInit' ] );
    }

    public function widgetInit()
    {
        register_widget( $this );
    }

    public function widget( $args, $instance ) 
    {
        extract( $args );

        // Check the widget options
        $title = isset( $instance['title'] ) ? apply_filters( 'widget_title', $instance['title'] ) : '';
    
        // WordPress core before_widget hook (always include )
        echo $before_widget;
    
        // Display the widget
        echo '<div class="widget-text wp_widget_plugin_box">';
        
        // Display widget title if defined
        if ( $title ) {
            echo $before_title . $title . $after_title;
        }

        echo '</div>';
    
        // WordPress core after_widget hook (always include )
        echo $after_widget;
    
    }

    public function form( $instance ) 
    {
        // Set widget defaults
        $defaults = array(
            'title'    => '',
            'text'     => '',
            'textarea' => '',
            'checkbox' => '',
            'select'   => '',
        );

        // Parse current settings with defaults
        extract( wp_parse_args( ( array ) $instance, $defaults ) ); ?>

        <?php // Widget Title ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php _e( 'Widget Title', 'text_domain' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
        </p>
        
        <?php
    }

    public function update( $new_intance, $old_instance ) 
    {
        $instance = $old_instance;

        $instance['title'] = isset( $new_instance['title'] ) ? wp_strip_all_tags( $new_instance['title'] ) : '';

        return $instance;
    }
}