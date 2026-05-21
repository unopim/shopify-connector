<?php

use Illuminate\Support\Facades\Route;
use Webkul\Shopify\Http\Controllers\Api\SaasCredentialController;

Route::group([
    'prefix' => 'api/v1/shopify/saas',
    'middleware' => [
        'auth:api',
        'api.scope',
        'accept.json',
    ],
], function () {
    Route::post('credentials', [SaasCredentialController::class, 'store'])
        ->name('shopify.api.saas.credentials.store');

    Route::put('credentials', [SaasCredentialController::class, 'update'])
        ->name('shopify.api.saas.credentials.update');
});
