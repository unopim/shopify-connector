<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shopify\Contracts\ShopifyMetaobjectEntry as ShopifyMetaobjectEntryContract;

class ShopifyMetaobjectEntry extends Model implements ShopifyMetaobjectEntryContract
{
    protected $table = 'wk_shopify_metaobject_entries';

    protected $fillable = ['type', 'code', 'values'];

    protected $casts = ['values' => 'array'];
}
