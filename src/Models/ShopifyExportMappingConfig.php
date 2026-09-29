<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Webkul\HistoryControl\Contracts\HistoryAuditable as HistoryContract;
use Webkul\HistoryControl\Interfaces\PresentableHistoryInterface;
use Webkul\HistoryControl\Traits\HistoryTrait;
use Webkul\Shopify\Contracts\ShopifyExportMappingConfig as ShopifyExportMappingConfigContract;
use Webkul\Shopify\Presenters\JsonDataPresenter;

#[Fillable([
    'name',
    'mapping',
])]
#[Table(name: 'shopify_setting_configuration_values')]
class ShopifyExportMappingConfig extends Model implements HistoryContract, PresentableHistoryInterface, ShopifyExportMappingConfigContract
{
    use HistoryTrait;

    protected $historyTags = ['shopify_exportmapping'];

    /**
     * custom history presenters to be used while displaying the history for that column
     */
    public static function getPresenters(): array
    {
        return [
            'mapping' => JsonDataPresenter::class,
        ];
    }

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
        ];
    }
}
