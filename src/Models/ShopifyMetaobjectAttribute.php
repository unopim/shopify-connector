<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shopify\Contracts\ShopifyMetaobjectAttribute as ShopifyMetaobjectAttributeContract;

class ShopifyMetaobjectAttribute extends Model implements ShopifyMetaobjectAttributeContract
{
    protected $table = 'wk_shopify_metaobject_attributes';

    protected $fillable = ['attribute_id', 'definition_id', 'is_list'];

    protected function casts(): array
    {
        return ['is_list' => 'boolean'];
    }
}
