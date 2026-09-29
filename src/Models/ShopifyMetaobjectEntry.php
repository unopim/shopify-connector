<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Webkul\Shopify\Contracts\ShopifyMetaobjectEntry as ShopifyMetaobjectEntryContract;

#[Fillable(['type', 'code', 'values'])]
#[Table(name: 'wk_shopify_metaobject_entries')]
class ShopifyMetaobjectEntry extends Model implements ShopifyMetaobjectEntryContract
{
    protected function casts(): array
    {
        return ['values' => 'array'];
    }
}
