<?php

// Minimal stand-ins for WordPress' REST request and response, for unit tests of endpoints.

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response
    {
        public $data;
        public $status;
        public $headers = array();

        public function __construct( $data = null, $status = 200 )
        {
            $this->data   = $data;
            $this->status = $status;
        }

        public function header( $key, $value )
        {
            $this->headers[ $key ] = $value;
        }

        public function get_status()
        {
            return $this->status;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function set_data( $data )
        {
            $this->data = $data;
        }
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request
    {
        private $headers = array();
        private $params  = array();
        private $json    = null;
        private $method  = 'GET';

        // Either WordPress' ( $method, $route ) or, for short tests, an array of params.
        public function __construct( $method = 'GET', $route = '' )
        {
            if ( is_array( $method ) ) {
                $this->params = $method;
            } else {
                $this->method = strtoupper( (string) $method );
            }
        }

        public function get_method()
        {
            return $this->method;
        }

        public function get_params()
        {
            return $this->params;
        }

        private static function key( $name )
        {
            return strtolower( str_replace( '-', '_', $name ) );
        }

        public function set_header( $name, $value )
        {
            $this->headers[ self::key( $name ) ] = $value;
        }

        public function get_header( $name )
        {
            return $this->headers[ self::key( $name ) ] ?? null;
        }

        public function set_param( $name, $value )
        {
            $this->params[ $name ] = $value;
        }

        public function get_param( $name )
        {
            return $this->params[ $name ] ?? ( is_array( $this->json ) ? ( $this->json[ $name ] ?? null ) : null );
        }

        public function set_body( $body )
        {
            $this->json = json_decode( $body, true );
        }

        public function get_json_params()
        {
            return $this->json;
        }

        public function get_body_params()
        {
            return array();
        }
    }
}
