<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Category\Models\Category;

class ShopifyCategoryTaxonomyMapping extends Model
{
    protected $table = 'wk_shopify_category_taxonomy_mapping';

    protected $fillable = [
        'unopim_category_id',
        'taxonomy_id',
        'taxonomy_path',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'unopim_category_id');
    }
}
