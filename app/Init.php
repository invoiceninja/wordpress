<?php

/**
 * @package Invoice Ninja
 */

namespace App;

final class Init
{
    public static function getControllers()
    {
        return [
            Controllers\SettingsController::class,
            Controllers\ProductController::class,
            Controllers\ClientController::class,
            Controllers\WidgetController::class,
        ];
    }

    public static function start() 
    {
        foreach ( self::getControllers() as $class )
        {
            $controller = new $class();
            
            if ( method_exists( $controller, 'register' ) )
            {
                $controller->register();
            }
        }
    }
}