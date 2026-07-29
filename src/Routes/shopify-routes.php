<?php

use Illuminate\Support\Facades\Route;
use Webkul\Shopify\Http\Controllers\CollectionMappingController;
use Webkul\Shopify\Http\Controllers\CredentialController;
use Webkul\Shopify\Http\Controllers\ImportMappingController;
use Webkul\Shopify\Http\Controllers\MappingController;
use Webkul\Shopify\Http\Controllers\MetaFieldController;
use Webkul\Shopify\Http\Controllers\MetaobjectController;
use Webkul\Shopify\Http\Controllers\MetaobjectEntryController;
use Webkul\Shopify\Http\Controllers\OptionController;
use Webkul\Shopify\Http\Controllers\SaasAutoLoginController;
use Webkul\Shopify\Http\Controllers\SettingController;

/**
 * Public Shopify-initiated auto-login. Lives outside the admin middleware so
 * Shopify can land users here without an existing UnoPim session; the HMAC
 * itself is what authenticates the request.
 */
Route::get('shopify/saas/secure-login', [SaasAutoLoginController::class, 'login'])
    ->name('shopify.saas.secure-login');

/**
 * Catalog routes.
 */
Route::group(['middleware' => ['admin'], 'prefix' => config('app.admin_url')], function () {
    Route::prefix('shopify')->group(function () {

        Route::controller(CredentialController::class)->prefix('credentials')->group(function () {
            Route::get('', 'index')->name('shopify.credentials.index');

            Route::post('create', 'store')->name('shopify.credentials.store');

            Route::get('edit/{id}', 'edit')->name('shopify.credentials.edit');

            Route::put('update/{id}', 'update')->name('shopify.credentials.update');

            Route::delete('delete/{id}', 'destroy')->name('shopify.credentials.delete');

            Route::post('sync/{id}', 'sync')->name('shopify.credentials.sync');

            Route::post('revoke/{id}', 'revoke')->name('shopify.credentials.revoke');
        });

        Route::controller(MetaFieldController::class)->prefix('metafields')->group(function () {
            Route::get('', 'index')->name('shopify.metafield.index');

            Route::post('create', 'store')->name('shopify.metafield.store');

            Route::get('edit/{id}', 'edit')->name('shopify.metafield.edit');

            Route::put('update/{id}', 'update')->name('shopify.metafield.update');

            Route::delete('delete/{id}', 'destroy')->name('shopify.metafield.delete');

            Route::post('mass-delete', 'massDestroy')->name('shopify.metafield.mass_delete');
        });

        Route::prefix('export')->group(function () {
            Route::controller(SettingController::class)->prefix('settings')->group(function () {
                Route::get('{id}', 'index')->name('admin.shopify.settings');

                Route::post('create', 'store')->name('shopify.export-settings.create');
            });
            Route::controller(MappingController::class)->prefix('mapping')->group(function () {
                Route::get('{id}', 'index')->name('admin.shopify.export-mappings');

                Route::post('create', 'store')->name('shopify.export-mappings.create');
            });

            Route::controller(CollectionMappingController::class)->prefix('collection-mapping')->group(function () {
                Route::get('{id}', 'index')->name('admin.shopify.collection-mappings');

                Route::post('create', 'store')->name('shopify.collection-mappings.create');
            });

        });

        Route::prefix('import')->group(function () {
            Route::controller(ImportMappingController::class)->prefix('mapping')->group(function () {
                Route::get('{id}', 'index')->name('admin.shopify.import-mappings');

                Route::post('create', 'store')->name('shopify.import-mappings.create');
            });
        });

        Route::controller(OptionController::class)->group(function () {

            Route::get('get-attribute', 'listAttributes')->name('admin.shopify.get-attribute');

            Route::get('get-category-field', 'listCategoryFields')->name('admin.shopify.get-category-field');

            Route::get('get-taxonomy-tree', 'listTaxonomyTree')->name('admin.shopify.get-taxonomy-tree');

            Route::get('get-taxonomy-descendants', 'listTaxonomyDescendants')->name('admin.shopify.get-taxonomy-descendants');

            Route::get('get-taxonomy-names', 'listTaxonomyNames')->name('admin.shopify.get-taxonomy-names');

            Route::get('get-image-attribute', 'listImageAttributes')->name('admin.shopify.get-image-attribute');

            Route::get('get-gallery-attribute', 'listGalleryAttributes')->name('admin.shopify.get-gallery-attribute');

            Route::get('get-metafield-attribute', 'listMetafieldAttributes')->name('admin.shopify.get-metafield-attribute');

            Route::get('selected-metafield-attribute', 'selectedMetafieldAttributes')->name('admin.shopify.get-selected-attribute');

            Route::get('get-shopify-credentials', 'listShopifyCredential')->name('shopify.credential.fetch-all');

            Route::get('get-shopify-channel', 'listChannel')->name('shopify.channel.fetch-all');

            Route::get('get-shopify-currency', 'listCurrency')->name('shopify.currency.fetch-all');

            Route::get('get-shopify-locale', 'listLocale')->name('shopify.locale.fetch-all');

            Route::get('get-shopify-attrGroup', 'listAttributeGroup')->name('shopify.attribute-group.fetch-all');

            Route::get('get-shopify-family', 'listShopifyFamily')->name('admin.shopify.get-all-family-variants');

            Route::get('metaobject/reference-options', 'referenceOptions')->name('shopify.metaobject.reference-options');
        });

        Route::controller(MetaobjectController::class)->group(function () {
            Route::get('metaobject', 'index')->name('shopify.metaobject.index');

            Route::post('metaobject', 'store')->name('shopify.metaobject.store');

            Route::get('metaobject/create', 'create')->name('shopify.metaobject.create');

            Route::get('metaobject/definitions', 'definitions')->name('shopify.metaobject.local-definitions');

            Route::get('metaobject/for-attribute', 'forAttribute')->name('shopify.metaobject.for-attribute');

            Route::post('metaobject/mass-delete', 'massDestroy')->name('shopify.metaobject.mass_delete');

            Route::get('metaobject/{id}/edit', 'edit')->name('shopify.metaobject.edit');

            Route::put('metaobject/{id}', 'update')->name('shopify.metaobject.update');

            Route::delete('metaobject/{id}', 'destroy')->name('shopify.metaobject.destroy');
        });

        Route::controller(MetaobjectEntryController::class)->group(function () {
            Route::get('metaobject-entry/list', 'list')->name('shopify.metaobject.entry.list');

            Route::post('metaobject-entry', 'store')->name('shopify.metaobject.entry.store');

            Route::delete('metaobject-entry/{id}', 'delete')->name('shopify.metaobject.entry.delete');
        });

    });
});
