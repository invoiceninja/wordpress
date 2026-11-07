<?php

// Unit tests run without WordPress: WP functions are stubbed per test with Brain Monkey.

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'INVOICENINJA_VERSION' ) ) {
    define( 'INVOICENINJA_VERSION', 'test' );
}

// The plugin logs API failures with error_log(); keep test output clean
ini_set( 'error_log', '/dev/null' );

if ( ! class_exists( 'WP_Error' ) ) {
    // Minimal stand-in for WordPress' WP_Error
    class WP_Error
    {
        private $message;

        public function __construct( $code = '', $message = '' )
        {
            $this->message = $message;
        }

        public function get_error_message()
        {
            return $this->message;
        }
    }
}

if ( ! class_exists( 'WP_Post' ) ) {
    // Minimal stand-in for WordPress' WP_Post
    class WP_Post
    {
        public $ID = 0;
        public $post_type = 'post';
        public $post_status = 'publish';
        public $post_title = '';

        public function __construct( $post = null )
        {
            foreach ( (array) $post as $key => $value ) {
                $this->$key = $value;
            }
        }
    }
}

foreach ( array( 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400 ) as $name => $value ) {
    if ( ! defined( $name ) ) {
        define( $name, $value );
    }
}
