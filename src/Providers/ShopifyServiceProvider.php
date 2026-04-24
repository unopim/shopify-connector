<?php

namespace Webkul\Shopify\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Shopify\Console\Commands\ShopifyInstaller;
use Webkul\Shopify\Console\Commands\ShopifyMappingProduct;
use Webkul\Shopify\Console\Commands\ShopifyPollBulkOperations;
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
            dirname(__DIR__).'/Config/exporters.php', 'exporters'
        );
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/importers.php', 'importers'
        );
        $this->mergeConfigFrom(
            dirname(__DIR__).'/Config/bulk_operations.php', 'shopify-bulk-operations'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/unopim-vite.php', 'unopim-vite.viters'
        );
    }
}
