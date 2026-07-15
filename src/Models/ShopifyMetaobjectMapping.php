<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shopify\Contracts\ShopifyMetaobjectMapping as ShopifyMetaobjectMappingContract;

class ShopifyMetaobjectMapping extends Model implements ShopifyMetaobjectMappingContract
{
    protected $table = 'wk_shopify_metaobject_mappings';

    protected $fillable = ['api_url', 'type', 'gid', 'name', 'fields'];

    protected $casts = ['fields' => 'array'];
}
