<?php

namespace Webkul\Shopify\Providers;

use Webkul\Core\Providers\CoreModuleServiceProvider;
use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;
use Webkul\Shopify\Models\ShopifyExportMappingConfig;
use Webkul\Shopify\Models\ShopifyMappingConfig;
use Webkul\Shopify\Models\ShopifyMetaFieldsConfig;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        ShopifyCredentialsConfig::class,
        ShopifyExportMappingConfig::class,
        ShopifyBulkOperation::class,
        ShopifyMappingConfig::class,
        ShopifyMetaFieldsConfig::class,
    ];
}
