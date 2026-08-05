<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\HistoryControl\Contracts\HistoryAuditable as HistoryContract;
use Webkul\HistoryControl\Interfaces\PresentableHistoryInterface;
use Webkul\HistoryControl\Traits\HistoryTrait;
use Webkul\Shopify\Contracts\ShopifyMetaobjectDefinition as ShopifyMetaobjectDefinitionContract;
use Webkul\Shopify\Presenters\JsonDataPresenter;

class ShopifyMetaobjectDefinition extends Model implements HistoryContract, PresentableHistoryInterface, ShopifyMetaobjectDefinitionContract
{
    use HistoryTrait;

    protected $table = 'wk_shopify_metaobject_definitions';

    protected $historyTags = ['shopify_metaobject'];

    protected $fillable = ['name', 'code', 'fields', 'options'];

    protected $casts = ['fields' => 'array', 'options' => 'array'];

    /**
     * Custom history presenters for the JSON columns.
     */
    public static function getPresenters(): array
    {
        return [
            'fields'  => JsonDataPresenter::class,
            'options' => JsonDataPresenter::class,
        ];
    }
}
