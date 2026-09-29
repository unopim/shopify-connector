<?php

use Illuminate\Support\Facades\Route;
use Webkul\Shopify\Http\Controllers\CatalogController;
use Webkul\Shopify\Http\Controllers\CollectionMappingController;
use Webkul\Shopify\Http\Controllers\CredentialController;
use Webkul\Shopify\Http\Controllers\ImportMappingController;
use Webkul\Shopify\Http\Controllers\MappingController;
use Webkul\Shopify\Http\Controllers\MetaFieldController;
use Webkul\Shopify\Http\Controllers\MetaobjectController;
use Webkul\Shopify\Http\Controllers\MetaobjectEntryController;
use Webkul\Shopify\Http\Controllers\OptionController;
use Webkul\Shopify\Http\Controllers\ProController;
use Webkul\Shopify\Http\Controllers\RealtimeController;
use Webkul\Shopify\Http\Controllers\SaasAutoLoginController;
use Webkul\Shopify\Http\Controllers\SettingController;

Route::get('shopify/saas/secure-login', [SaasAutoLoginController::class, 'login'])
    ->name('shopify.saas.secure-login');

Route::group(['middleware' => ['admin'], 'prefix' => config('app.admin_url')], function (): void {
    Route::prefix('shopify')->group(function (): void {
        Route::controller(CredentialController::class)->prefix('credentials')->group(function (): void {
            Route::get('', 'index')->name('shopify.credentials.index');

            Route::post('create', 'store')->name('shopify.credentials.store');

            Route::get('{id}/edit', 'edit')->name('shopify.credentials.edit')->whereNumber('id');

            Route::put('{id}', 'update')->name('shopify.credentials.update')->whereNumber('id');

            Route::delete('{id}', 'destroy')->name('shopify.credentials.delete')->whereNumber('id');

            Route::post('{id}/sync', 'sync')->name('shopify.credentials.sync')->whereNumber('id');

            Route::post('{id}/revoke', 'revoke')->name('shopify.credentials.revoke')->whereNumber('id');
        });

        Route::controller(MetaFieldController::class)->prefix('metafields')->group(function (): void {
            Route::get('', 'index')->name('shopify.metafield.index');

            Route::post('create', 'store')->name('shopify.metafield.store');

            Route::post('mass-delete', 'massDestroy')->name('shopify.metafield.mass_delete');

            Route::get('{id}/edit', 'edit')->name('shopify.metafield.edit')->whereNumber('id');

            Route::put('{id}', 'update')->name('shopify.metafield.update')->whereNumber('id');

            Route::delete('{id}', 'destroy')->name('shopify.metafield.delete')->whereNumber('id');
        });

        Route::get('upgrade', [ProController::class, 'index'])->name('shopify.upgrade');

        /**
         * The real-time screens and the catalogs they belong to sit under the
         * credential they configure, so the sidebar marks Credentials active
         * without a rule of its own.
         */
        Route::prefix('credentials')->group(function (): void {
            Route::get('{credentialId}/realtime', [RealtimeController::class, 'credential'])
                ->whereNumber('credentialId')
                ->name('shopify.credentials.realtime.index');

            /**
             * Only the screen itself, because the tab that leads to it is the
             * connector's. What a catalog is then read from or written to is
             * Pro's, and ships with Pro.
             */
            Route::get('{credentialId}/catalogs', [CatalogController::class, 'index'])
                ->whereNumber('credentialId')
                ->name('shopify.credentials.catalogs.index');
        });

        Route::controller(SettingController::class)->prefix('export-settings')->group(function (): void {
            Route::post('create', 'store')->name('shopify.export-settings.create');

            Route::get('{id}', 'index')->name('admin.shopify.settings')->whereNumber('id');
        });

        Route::controller(MappingController::class)->prefix('export-mapping')->group(function (): void {
            Route::post('create', 'store')->name('shopify.export-mappings.create');

            Route::get('{id}', 'index')->name('admin.shopify.export-mappings')->whereNumber('id');
        });

        /**
         * Real-time sync is a tab of the export mapping screen, so it is served
         * from under it and the sidebar keeps Export Mappings active.
         */
        Route::prefix('export-mapping/{id}')->whereNumber('id')->group(function (): void {
            Route::get('realtime', [RealtimeController::class, 'index'])->name('shopify.realtime.index');
        });

        Route::controller(CollectionMappingController::class)->prefix('collection-mapping')->group(function (): void {
            Route::post('create', 'store')->name('shopify.collection-mappings.create');

            Route::get('{id}', 'index')->name('admin.shopify.collection-mappings')->whereNumber('id');
        });

        Route::controller(ImportMappingController::class)->prefix('import-mapping')->group(function (): void {
            Route::post('create', 'store')->name('shopify.import-mappings.create');

            Route::get('{id}', 'index')->name('admin.shopify.import-mappings')->whereNumber('id');
        });

        Route::controller(OptionController::class)->group(function (): void {
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

            Route::get('metaobjects/reference-options', 'referenceOptions')->name('shopify.metaobject.reference-options');
        });

        Route::controller(MetaobjectController::class)->prefix('metaobjects')->group(function (): void {
            Route::get('', 'index')->name('shopify.metaobject.index');

            Route::post('', 'store')->name('shopify.metaobject.store');

            Route::get('create', 'create')->name('shopify.metaobject.create');

            Route::get('definitions', 'definitions')->name('shopify.metaobject.local-definitions');

            Route::get('for-attribute', 'forAttribute')->name('shopify.metaobject.for-attribute');

            Route::post('mass-delete', 'massDestroy')->name('shopify.metaobject.mass_delete');

            Route::get('{id}/edit', 'edit')->name('shopify.metaobject.edit')->whereNumber('id');

            Route::put('{id}', 'update')->name('shopify.metaobject.update')->whereNumber('id');

            Route::delete('{id}', 'destroy')->name('shopify.metaobject.destroy')->whereNumber('id');

            Route::patch('{id}/general', 'updateGeneral')->name('shopify.metaobject.general')->whereNumber('id');

            /** The datagrid shares the fields path, so it is matched before the key. */
            Route::get('{id}/fields/datagrid', 'fieldDatagrid')->name('shopify.metaobject.field.datagrid')->whereNumber('id');

            Route::get('{id}/fields', 'fields')->name('shopify.metaobject.fields')->whereNumber('id');

            Route::post('{id}/fields', 'fieldStore')->name('shopify.metaobject.field.store')->whereNumber('id');

            Route::get('{id}/fields/{key}', 'fieldGet')->name('shopify.metaobject.field.get')->whereNumber('id');

            Route::put('{id}/fields/{key}', 'fieldUpdate')->name('shopify.metaobject.field.update')->whereNumber('id');

            Route::delete('{id}/fields/{key}', 'fieldDestroy')->name('shopify.metaobject.field.destroy')->whereNumber('id');
        });

        Route::controller(MetaobjectEntryController::class)->prefix('metaobject-entries')->group(function (): void {
            Route::get('list', 'list')->name('shopify.metaobject.entry.list');

            Route::get('datagrid/{type}', 'datagrid')->name('shopify.metaobject.entry.datagrid');

            Route::get('columns/{type}', 'columns')->name('shopify.metaobject.entry.columns');

            Route::post('', 'store')->name('shopify.metaobject.entry.store');

            Route::get('{id}', 'get')->name('shopify.metaobject.entry.get')->whereNumber('id');

            Route::delete('{id}', 'delete')->name('shopify.metaobject.entry.delete')->whereNumber('id');
        });

    });
});
