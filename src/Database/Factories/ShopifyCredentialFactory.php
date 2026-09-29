<?php

namespace Webkul\Shopify\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;

/**
 * @extends Factory<ShopifyCredentialsConfig>
 */
class ShopifyCredentialFactory extends Factory
{
    protected $model = ShopifyCredentialsConfig::class;

    /**
     * Define the model's default state.
     * Fake credentials are used for the testing purposes.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'shopUrl'      => 'https://demotest.myshopify.com',
            'apiVersion'   => '2026-07',
            'accessToken'  => '$2y$10$92IXUNpkjO0rOQ5byMi',
            'clientId'     => 'test_client_id',
            'clientSecret' => 'test_client_secret',
        ];
    }
}
