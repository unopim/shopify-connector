<?php

namespace Webkul\Shopify\Providers;

use Webkul\Core\Providers\CoreModuleServiceProvider;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        \Webkul\Shopify\Models\ShopifyCredentialsConfig::class,
        \Webkul\Shopify\Models\ShopifyExportMappingConfig::class,
        \Webkul\Shopify\Models\ShopifyMappingConfig::class,
    ];
}
