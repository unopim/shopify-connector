<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Webkul\Shopify\Contracts\ShopifyMappingConfig as ShopifyMappingConfigContract;

#[Fillable([
    'entityType',
    'code',
    'externalId',
    'jobInstanceId',
    'relatedId',
    'relatedSource',
    'apiUrl',
])]
#[Table(name: 'wk_shopify_data_mapping')]
class ShopifyMappingConfig extends Model implements ShopifyMappingConfigContract {}
