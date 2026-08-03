<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shopify\Contracts\ShopifyMetaobjectDefinition as ShopifyMetaobjectDefinitionContract;

class ShopifyMetaobjectDefinition extends Model implements ShopifyMetaobjectDefinitionContract
{
    protected $table = 'wk_shopify_metaobject_definitions';

    protected $fillable = ['name', 'code', 'fields', 'options'];

    protected $casts = ['fields' => 'array', 'options' => 'array'];
}
