<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Webkul\Shopify\Contracts\ShopifyMetaobjectEntryMapping as ShopifyMetaobjectEntryMappingContract;

#[Fillable(['entry_id', 'api_url', 'gid'])]
#[Table(name: 'wk_shopify_metaobject_entry_mappings')]
class ShopifyMetaobjectEntryMapping extends Model implements ShopifyMetaobjectEntryMappingContract {}
