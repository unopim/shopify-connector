<?php

namespace Webkul\Shopify\Providers;

use Webkul\Core\Providers\CoreModuleServiceProvider;
use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;
use Webkul\Shopify\Models\ShopifyExportMappingConfig;
use Webkul\Shopify\Models\ShopifyMappingConfig;
use Webkul\Shopify\Models\ShopifyMetaFieldsConfig;
use Webkul\Shopify\Models\ShopifyMetaobjectAttribute;
use Webkul\Shopify\Models\ShopifyMetaobjectDefinition;
use Webkul\Shopify\Models\ShopifyMetaobjectEntry;
use Webkul\Shopify\Models\ShopifyMetaobjectEntryMapping;
use Webkul\Shopify\Models\ShopifyMetaobjectMapping;

class ModuleServiceProvider extends CoreModuleServiceProvider
{
    protected $models = [
        ShopifyCredentialsConfig::class,
        ShopifyExportMappingConfig::class,
        ShopifyBulkOperation::class,
        ShopifyMappingConfig::class,
        ShopifyMetaFieldsConfig::class,
        ShopifyMetaobjectMapping::class,
        ShopifyMetaobjectEntry::class,
        ShopifyMetaobjectDefinition::class,
        ShopifyMetaobjectAttribute::class,
        ShopifyMetaobjectEntryMapping::class,
    ];
}
