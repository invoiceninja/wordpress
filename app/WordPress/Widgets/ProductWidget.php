<?php

/**
 * @package Invoice Ninja
 */

namespace App\Widgets;

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

    public function widget() 
    {
        
    }

    public function form( $instance ) 
    {
        $title = ! empty( $instance['title'] ) ? $instance['title'] : esc_html__('Custom Text', 'invoiceninja');

        $titleId = esc_attr( $this->get_field_id( 'title' ));

        ?>

        <p>

            <label for="<?php echo $titleId ?>">Title</label>

            <input type="text" class="widefat" id="<?php echo $titleId; ?>" 
                name="<?php esc_attr( $this->get_field_name( 'title' ) ); ?>" 
                value="<?php echo esc_attr( $title ); ?>"/>
        </p>

        <?php
    }

    public function update() 
    {
        
    }
}