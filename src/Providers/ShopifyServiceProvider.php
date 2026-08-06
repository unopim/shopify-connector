<?php

namespace Webkul\Shopify\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\DataTransfer\Services\JobLogger;
use Webkul\Shopify\Console\Commands\ShopifyInstaller;
use Webkul\Shopify\Console\Commands\ShopifyMappingProduct;
use Webkul\Shopify\Console\Commands\ShopifyPollBulkOperations;
use Webkul\Shopify\Listeners\DeferJobTrackCompletion;
use Webkul\Shopify\Listeners\RevokeShopifyOnApiKeyDelete;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectAttributeRepository;
use Webkul\Theme\ViewRenderEventManager;

class ShopifyServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(Router $router)
    {
        Route::middleware('web')->group(__DIR__.'/../Routes/shopify-routes.php');
        Route::middleware('api')->group(__DIR__.'/../Routes/shopify-api-routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migration');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'shopify');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'shopify');

        $this->app->register(ModuleServiceProvider::class);
        app('view')->prependNamespace('admin', __DIR__.'/../Resources/views');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ShopifyInstaller::class,
                ShopifyMappingProduct::class,
                ShopifyPollBulkOperations::class,
            ]);
        }

        Event::listen('unopim.admin.layout.head', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('shopify::style');
        });

        Event::listen('data_transfer.exports.started', static function ($export) {
            if (! str_starts_with(strtolower((string) ($export->type ?? '')), 'shopify')) {
                return;
            }

            $path = storage_path(JobLogger::getJobLogPath($export->id));

            if (file_exists($path)) {
                file_put_contents($path, '');
            }
        });

        Event::listen('unopim.admin.products.dynamic-attribute-fields.control.shopify_taxonomy.before', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('shopify::catalog.products.taxonomy-control');
        });

        Event::listen('unopim.admin.products.dynamic-attribute-fields.control.shopify_metaobject.before', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('shopify::catalog.products.metaobject-control');
        });

        Event::listen('unopim.admin.settings.data_transfer.exports.create.card.accordion.filters.befor', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('shopify::data-transfer.export-filters');
        });

        Event::listen('unopim.admin.settings.data_transfer.exports.edit.card.accordion.filters.befor', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('shopify::data-transfer.export-filters-edit');
        });

        Event::listen('unopim.admin.catalog.attributes.create.card.label.after', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('shopify::catalog.attributes.metaobject-binding');
        });

        Event::listen('unopim.admin.catalog.attributes.edit.card.label.after', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('shopify::catalog.attributes.metaobject-binding');
        });

        Event::listen('unopim.admin.catalog.attributes.list.after', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('shopify::catalog.attributes.metaobject-binding-modal');
        });

        $requireMetaobjectDefinition = static function () {
            if (request()->input('type') === 'shopify_metaobject' && ! request()->filled('metaobject_definition')) {
                throw ValidationException::withMessages([
                    'type' => trans('shopify::app.shopify.attribute.metaobject-required'),
                ]);
            }
        };

        Event::listen('catalog.attribute.create.before', $requireMetaobjectDefinition);

        Event::listen('catalog.attribute.update.before', $requireMetaobjectDefinition);

        Event::listen('catalog.attribute.create.after', static function ($attribute) {
            if (request()->input('type') === 'shopify_metaobject' && request()->input('metaobject_definition')) {
                app(ShopifyMetaobjectAttributeRepository::class)->saveBinding((int) $attribute->id, (int) request()->input('metaobject_definition'), request()->boolean('metaobject_multiple'));
            }
        });

        Event::listen('catalog.attribute.update.after', static function ($attribute) {
            $repository = app(ShopifyMetaobjectAttributeRepository::class);

            if (request()->input('type') === 'shopify_metaobject' && request()->input('metaobject_definition')) {
                $repository->saveBinding((int) $attribute->id, (int) request()->input('metaobject_definition'), request()->boolean('metaobject_multiple'));

                return;
            }

            $repository->deleteBinding((int) $attribute->id);
        });

        Event::listen('catalog.attribute.delete.before', static function ($id) {
            $attribute = app(AttributeRepository::class)->find((int) $id);

            if ($attribute?->type === 'shopify_metaobject') {
                app(ShopifyMetaFieldRepository::class)->deleteWhere([
                    ['code', '=', $attribute->code],
                    ['type', '=', 'metaobject_reference'],
                ]);
            }
        });

        Event::listen('catalog.attribute.delete.after', static function ($id) {
            app(ShopifyMetaobjectAttributeRepository::class)->deleteBinding((int) $id);
        });

        Event::listen('catalog.attribute.create.before', static function () {
            if (
                request()->input('type') === 'shopify_taxonomy'
                && app(AttributeRepository::class)->findWhere(['type' => 'shopify_taxonomy'])->isNotEmpty()
            ) {
                throw ValidationException::withMessages([
                    'type' => trans('shopify::app.shopify.attribute.only-one'),
                ]);
            }
        });

        Event::listen('data_transfer.export.completed', [DeferJobTrackCompletion::class, 'handle']);

        $shopifyImportPlaceholder = static function (): string {
            $path = 'shopify/import-placeholder.csv';

            if (! Storage::disk('private')->exists($path)) {
                Storage::disk('private')->put($path, "handle,type\nplaceholder,placeholder\n");
            }

            return $path;
        };

        $ensureShopifyImportFilePath = static function ($import) use ($shopifyImportPlaceholder) {
            if (! str_starts_with($import->entity_type ?? '', 'shopify') || ! empty($import->file_path)) {
                return;
            }

            $import->file_path = $shopifyImportPlaceholder();
            $import->field_separator = ',';
            $import->save();
        };

        Event::listen('data_transfer.imports.create.after', $ensureShopifyImportFilePath);

        Event::listen('data_transfer.imports.update.after', $ensureShopifyImportFilePath);

        Event::listen('data_transfer.imports.import.now.before', static function ($import) use ($shopifyImportPlaceholder) {
            if (str_starts_with($import->entity_type ?? '', 'shopify')) {
                $shopifyImportPlaceholder();
            }
        });

        Event::listen('user.api_key.delete.before', [RevokeShopifyOnApiKeyDelete::class, 'handle']);

        $this->publishes([
            __DIR__.'/../../publishable' => public_path('themes'),
        ], 'shopify-config');
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerConfig();
    }

    /**
     * Register package config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/menu.php',
            'menu.admin'
        );
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/acl.php', 'acl'
        );
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/api-acl.php', 'api-acl'
        );
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/exporters.php', 'exporters'
        );
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/importers.php', 'importers'
        );
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/bulk_mutations.php', 'shopify_bulk_mutations'
        );
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/bulk_operations.php', 'shopify-bulk-operations'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/unopim-vite.php', 'unopim-vite.viters'
        );
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/saas.php', 'shopify.saas'
        );
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/shopify_taxonomy.php', 'shopify_taxonomy'
        );
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/attribute_types.php', 'attribute_types'
        );
    }
}
