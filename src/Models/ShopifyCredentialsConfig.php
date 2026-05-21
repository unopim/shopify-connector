<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Webkul\HistoryControl\Contracts\HistoryAuditable as HistoryContract;
use Webkul\HistoryControl\Interfaces\PresentableHistoryInterface;
use Webkul\HistoryControl\Traits\HistoryTrait;
use Webkul\Shopify\Contracts\ShopifyCredentialsConfig as ShopifyCredentialsContract;
use Webkul\Shopify\Database\Factories\ShopifyCredentialFactory;
use Webkul\Shopify\Presenters\JsonDataPresenter;

class ShopifyCredentialsConfig extends Model implements HistoryContract, PresentableHistoryInterface, ShopifyCredentialsContract
{
    use HasFactory, HistoryTrait;

    protected $table = 'wk_shopify_credentials_config';

    protected $historyTags = ['shopify_credentials'];

    protected $auditExclude = ['storeLocales', 'accessToken', 'clientSecret'];

    protected $fillable = [
        'shopUrl',
        'accessToken',
        'clientId',
        'clientSecret',
        'accessTokenExpiresAt',
        'active',
        'apiVersion',
        'storelocaleMapping',
        'storeLocales',
        'defaultSet',
        'resources',
        'extras',
        'salesChannel',
    ];

    protected $casts = [
        'storelocaleMapping' => 'array',
        'storeLocales' => 'array',
        'extras' => 'array',
        'accessTokenExpiresAt' => 'datetime',
    ];

    /**
     * custom history presenters to be used while displaying the history for that column
     */
    public static function getPresenters(): array
    {
        return [
            'storelocaleMapping' => JsonDataPresenter::class,
            'extras' => JsonDataPresenter::class,
        ];
    }

    /**
     * Represent the credential as the array shape consumed by the Shopify API
     * clients, exporters, importers and bulk jobs.
     *
     * This is the single definition of that shape: callers must use it instead
     * of hand-building the array, so a new field is added in exactly one place.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'credentialId' => $this->id,
            'shopUrl' => $this->shopUrl,
            'accessToken' => $this->accessToken,
            'apiVersion' => $this->apiVersion,
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'accessTokenExpiresAt' => optional($this->accessTokenExpiresAt)?->toDateTimeString(),
            'extras' => $this->extras,
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): Factory
    {
        return ShopifyCredentialFactory::new();
    }
}
