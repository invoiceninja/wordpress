<?php

/**
 * @package Invoice Ninja
 */

namespace App;

final class Init
{
    public static function get_controllers()
    {
        return [
            Controllers\SettingsController::class
        ];
    }

    public static function start() 
    {
        foreach ( self::get_controllers() as $class )
        {
            $controller = new $class();
            
            if ( method_exists( $controller, 'register' ) )
            {
                $controller->register();
            }
        }
    }
}