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
        echo $args['before_widget'];

        echo "PRODUCTS";

        if ( ! empty( $instance['title'] ) )
        {
            echo $args['before_title'] . apply_filter( 'widget_title', $instance['title'] ) . $args['after_title'];
        }

        echo $args['after_widget'];
    }

    public function form( $instance ) 
    {
        $title = ! empty( $instance['title'] ) ? $instance['title'] : esc_html__('Custom Text', 'invoiceninja');
        $title_id = esc_attr( $this->get_field_id( 'title' ) );
        $title_name = esc_attr( $this->get_field_name( 'title' ) );
        ?>

        <p>

            <label for="<?php echo $title_id ?>">Title</label>

            <input type="text" class="widefat" id="<?php echo $title_id; ?>" 
                name="<?php echo $title_name; ?>" 
                value="<?php echo esc_attr( $title ); ?>"/>

        </p>

        <?php
    }

    public function update( $new_intance, $old_instance ) 
    {
        $instance = $old_instance;

        //$instance['title'] = sanitize_text_field( $new_intance['title'] );
        $instance['title'] = $new_intance['title'];

        return $instance;
    }
}