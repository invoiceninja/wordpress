<?php

/**
 * @package Invoice Ninja
 */

namespace App\Controllers;

use \App\WordPress\PostsApi;

class ProductController extends BaseController
{
    public function __construct()
    {
        parent::__construct();

        $this->posts = new PostsApi();
    }

    public function register()
    {
        $types = [
            [
                'id' => 'invoiceninja_product',
                'name' => 'Products',
                'singular_name' => 'Product',
            ]
        ];

        $this->posts
            ->setPostTypes($types)
            ->register();
    }
}