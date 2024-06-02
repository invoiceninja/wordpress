<?php

/**
 * @package Invoice Ninja
 */

namespace App\Controllers;

use \App\WordPress\PostApi;

class ProductController extends BaseController
{
    public function __construct()
    {
        parent::__construct();

        $this->posts = new PostApi();
    }

    public function register()
    {
        $types = [
            [
                'id' => 'product',
                'name' => 'Products',
                'singular_name' => 'Product',
            ]
        ];

        $this->posts
            ->setPostTypes($types)
            ->register();
    }
}