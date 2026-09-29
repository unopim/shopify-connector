<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Webkul\HistoryControl\Contracts\HistoryAuditable as HistoryContract;
use Webkul\HistoryControl\Interfaces\PresentableHistoryInterface;
use Webkul\HistoryControl\Traits\HistoryTrait;
use Webkul\Shopify\Contracts\ShopifyMetaobjectDefinition as ShopifyMetaobjectDefinitionContract;
use Webkul\Shopify\Presenters\JsonDataPresenter;

#[Fillable(['name', 'code', 'fields', 'options'])]
#[Table(name: 'wk_shopify_metaobject_definitions')]
class ShopifyMetaobjectDefinition extends Model implements HistoryContract, PresentableHistoryInterface, ShopifyMetaobjectDefinitionContract
{
    use HistoryTrait;

    protected $historyTags = ['shopify_metaobject'];

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

    protected function casts(): array
    {
        return ['fields' => 'array', 'options' => 'array'];
    }
}
