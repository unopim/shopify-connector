<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Webkul\Shopify\Contracts\ShopifyMetaobjectMapping as ShopifyMetaobjectMappingContract;

#[Fillable(['api_url', 'type', 'gid', 'name', 'fields'])]
#[Table(name: 'wk_shopify_metaobject_mappings')]
class ShopifyMetaobjectMapping extends Model implements ShopifyMetaobjectMappingContract
{
    protected function casts(): array
    {
        return ['fields' => 'array'];
    }
}
