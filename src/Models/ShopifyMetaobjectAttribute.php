<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Webkul\Shopify\Contracts\ShopifyMetaobjectAttribute as ShopifyMetaobjectAttributeContract;

#[Fillable(['attribute_id', 'definition_id', 'is_list'])]
#[Table(name: 'wk_shopify_metaobject_attributes')]
class ShopifyMetaobjectAttribute extends Model implements ShopifyMetaobjectAttributeContract
{
    protected function casts(): array
    {
        return ['is_list' => 'boolean'];
    }
}
