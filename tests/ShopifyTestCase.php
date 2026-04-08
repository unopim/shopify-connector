<?php

namespace Webkul\Shopify\Tests;

use Tests\TestCase;
use Webkul\Shopify\Database\Seeders\ShopifySettingConfigurationValuesSeeder;
use Webkul\User\Tests\Concerns\UserAssertions;

class ShopifyTestCase extends TestCase
{
    use UserAssertions;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure required Shopify mapping rows (ids 1/2/3) exist for mapping tests.
        (new ShopifySettingConfigurationValuesSeeder)->run();
    }
}
