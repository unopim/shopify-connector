<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shopify\Contracts\ShopifyMetaobjectEntryMapping as ShopifyMetaobjectEntryMappingContract;

class ShopifyMetaobjectEntryMapping extends Model implements ShopifyMetaobjectEntryMappingContract
{
    protected $table = 'wk_shopify_metaobject_entry_mappings';

    protected $fillable = ['entry_id', 'api_url', 'gid'];
}
