<?php

namespace Webkul\Shopify\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Shopify\Console\Commands\ShopifyInstaller;
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
        $this->publishes([
            __DIR__.'/../config/unopim-vite.php' => config_path('unopim-vite.php'),
        ], 'config');

        $this->mergeConfigFrom(
            __DIR__.'/../config/unopim-vite.php', 'unopim-vite'
        );
        Route::middleware('web')->group(__DIR__.'/../Routes/shopify-routes.php');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migration');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'shopify');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'shopify');

        $this->app->register(ModuleServiceProvider::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ShopifyInstaller::class,
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
    }
}
