<?php

namespace Webkul\Shopify\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\DataTransfer\Models\JobInstancesProxy;
use Webkul\DataTransfer\Services\JobLogger;
use Webkul\Product\Repositories\AssociationTypeRepository;
use Webkul\Shopify\Console\Commands\ShopifyInstaller;
use Webkul\Shopify\Console\Commands\ShopifyMappingProduct;
use Webkul\Shopify\Console\Commands\ShopifyPollBulkOperations;
use Webkul\Shopify\Listeners\DeferJobTrackCompletion;
use Webkul\Shopify\Listeners\ExportUpdatedSummary;
use Webkul\Shopify\Listeners\RevokeShopifyOnApiKeyDelete;
use Webkul\Shopify\Repositories\ShopifyExportMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectAttributeRepository;
use Webkul\Shopify\Support\ProFeatures;
use Webkul\Shopify\Support\ShopifyMapping;
use Webkul\Theme\ViewRenderEventManager;

class ShopifyServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(Router $router): void
    {
        View::composer('shopify::*', static function ($view): void {
            $view->with('shopifyProInstalled', resolve(ProFeatures::class)->isInstalled());
        });

        View::composer([
            'shopify::credential.edit',
            'shopify::catalogs.index',
            'shopify::realtime.credential',
        ], static function ($view): void {
            $credential = $view->getData()['credential'] ?? null;

            if (! $credential?->id || ! bouncer()->hasPermission('shopify.credentials.catalogs')) {
                return;
            }

            $view->with('tabItems', [
                [
                    'key'   => 'general',
                    'url'   => route('shopify.credentials.edit', $credential->id),
                    'label' => 'admin::app.components.layouts.sidebar.general',
                ], [
                    'key'   => 'catalogs',
                    'url'   => route('shopify.credentials.catalogs.index', $credential->id),
                    'label' => 'shopify::app.shopify.catalogs.title',
                ], [
                    'key'   => 'realtime',
                    'url'   => route('shopify.credentials.realtime.index', $credential->id),
                    'label' => 'shopify::app.shopify.realtime.title',
                ],
            ]);
        });

        View::composer([
            'shopify::realtime.index',
            'admin::components.layouts.with-history.index',
        ], static function ($view): void {
            if ($view->getName() === 'admin::components.layouts.with-history.index'
                && ! request()->routeIs('admin.shopify.export-mappings')) {
                return;
            }

            $view->with('tabItems', [
                [
                    'key'   => 'general',
                    'url'   => route('admin.shopify.export-mappings', ShopifyMapping::EXPORT_ID),
                    'label' => 'admin::app.components.layouts.sidebar.general',
                ], [
                    'key'   => 'realtime',
                    'url'   => route('shopify.realtime.index', ShopifyMapping::EXPORT_ID),
                    'label' => 'shopify::app.shopify.realtime.title',
                ],
            ]);
        });

        Route::middleware('web')->group(__DIR__.'/../Routes/shopify-routes.php');
        Route::middleware('api')->group(__DIR__.'/../Routes/shopify-api-routes.php');

        $this->loadMigrationsFrom(__DIR__.'/../Database/Migration');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'shopify');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'shopify');

        $this->app->register(ModuleServiceProvider::class);
        resolve('view')->prependNamespace('admin', __DIR__.'/../Resources/views');

        Blade::anonymousComponentPath(__DIR__.'/../Resources/views/components', 'shopify');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ShopifyInstaller::class,
                ShopifyMappingProduct::class,
                ShopifyPollBulkOperations::class,
            ]);
        }

        Event::listen('unopim.admin.layout.head', static function (ViewRenderEventManager $viewRenderEventManager): void {
            $viewRenderEventManager->addTemplate('shopify::style');
        });

        Event::listen('data_transfer.exports.started', static function ($export): void {
            if (! str_starts_with(strtolower((string) ($export->type ?? '')), 'shopify')) {
                return;
            }

            $path = storage_path(JobLogger::getJobLogPath($export->id));

            if (file_exists($path)) {
                file_put_contents($path, '');
            }
        });

        Event::listen('unopim.admin.products.dynamic-attribute-fields.control.shopify_taxonomy.before', static function (ViewRenderEventManager $viewRenderEventManager): void {
            $viewRenderEventManager->addTemplate('shopify::catalog.products.taxonomy-control');
        });

        Event::listen('unopim.admin.products.dynamic-attribute-fields.control.shopify_metaobject.before', static function (ViewRenderEventManager $viewRenderEventManager): void {
            $viewRenderEventManager->addTemplate('shopify::catalog.products.metaobject-control');
        });

        foreach (['create', 'edit'] as $screen) {
            Event::listen("unopim.admin.settings.data_transfer.exports.{$screen}.card.general.before", static function (ViewRenderEventManager $viewRenderEventManager): void {
                $viewRenderEventManager->addTemplate('shopify::data-transfer.pro-filter-badges');
            });
        }

        /**
         * The right-hand column of a Shopify export: the credentials open the
         * Output card, in core's when the export has one and in the connector's
         * own when it has none, and the schedule follows below it.
         *
         * @var array<string, array{before: string, output: string, after: string}>
         */
        $exportCards = [
            'create' => [
                'before' => 'shopify::data-transfer.export-credentials',
                'output' => 'shopify::data-transfer.export-credentials-output',
                'after'  => 'shopify::data-transfer.export-create-schedule',
            ],
            'edit' => [
                'before' => 'shopify::data-transfer.export-credentials-edit',
                'output' => 'shopify::data-transfer.export-credentials-output-edit',
                'after'  => 'shopify::data-transfer.export-schedule',
            ],
        ];

        View::composer('shopify::association-mappings.section', function ($view): void {
            $view->with('associationTypes', resolve(AssociationTypeRepository::class)->getActiveTypes()->map(fn ($associationType): array => ['id' => $associationType->code, 'label' => $associationType->name ?: $associationType->code])->all());
            $mappingId = request()->routeIs('admin.shopify.export-mappings') ? ShopifyMapping::EXPORT_ID : ShopifyMapping::IMPORT_ID;
            $mapping = resolve(ShopifyExportMappingRepository::class)->find($mappingId);
            $view->with('associationMapping', $mapping?->mapping['shopify_pro_association_mapping'] ?? []);
        });

        View::composer('shopify::external-media.section', function ($view): void {
            $mediaMapping = resolve(ShopifyExportMappingRepository::class)->find(ShopifyMapping::EXPORT_ID)?->mapping['mediaMapping'] ?? [];
            $locale = core()->getRequestedLocaleCode();

            $view->with('externalImageAttribute', $mediaMapping['externalImageAttribute'] ?? null)
                ->with('externalVideoAttribute', $mediaMapping['externalVideoAttribute'] ?? null)
                ->with('urlAttributes', Attribute::query()
                    ->where('validation', 'url')
                    ->orderBy('code')
                    ->get()
                    ->map(fn (Attribute $attribute): array => [
                        'code'  => $attribute->code,
                        'label' => $attribute->translate($locale)?->name ?: "[{$attribute->code}]",
                    ])
                    ->all());
        });

        Event::listen('unopim.admin.layout.head', static function (ViewRenderEventManager $viewRenderEventManager): void {
            $viewRenderEventManager->addTemplate('shopify::pro.styles');
        });

        $this->app->booted(static function (): void {
            View::composer('admin::components.layouts.sidebar.index', static function ($view): void {
                $menu = $view->getData()['menu'];

                if (! isset($menu->items['shopify']['children']['upgrade'])) {
                    return;
                }

                $menu->items['shopify']['children']['upgrade']['url'] = config('shopify.pro.url');
            });
        });

        /**
         * The Pro cards belong to the mapping form, and they move themselves
         * next to its media card once mounted. The history tab carries neither,
         * so they are only offered where they have a place to land.
         */
        Event::listen('unopim.admin.layout.content.after', static function (ViewRenderEventManager $viewRenderEventManager): void {
            if (request()->has('history')
                || ! request()->routeIs('admin.shopify.export-mappings', 'admin.shopify.import-mappings')) {
                return;
            }

            $viewRenderEventManager->addTemplate('shopify::pro.mapping-sections');
        });

        $this->appendExportFilterFields();
        $this->appendScheduleFilterFields();

        foreach ($exportCards as $screen => $templates) {
            Event::listen("unopim.admin.settings.data_transfer.exports.{$screen}.card.accordion.filters.befor", static function (ViewRenderEventManager $viewRenderEventManager) use ($templates): void {
                $viewRenderEventManager->addTemplate($templates['before']);
            });

            Event::listen("unopim.admin.settings.data_transfer.exports.{$screen}.card.accordion.filters.output.before", static function (ViewRenderEventManager $viewRenderEventManager) use ($templates): void {
                $viewRenderEventManager->addTemplate($templates['output']);
            });

            Event::listen("unopim.admin.settings.data_transfer.exports.{$screen}.card.accordion.filters.after", static function (ViewRenderEventManager $viewRenderEventManager) use ($templates): void {
                $viewRenderEventManager->addTemplate($templates['after']);
            });

            /**
             * The category export's Pro filters have no core card: the selection
             * sits under the scope card, the children toggle among the output fields.
             */
            Event::listen("unopim.admin.settings.data_transfer.exports.{$screen}.card.scope.after", static function (ViewRenderEventManager $viewRenderEventManager): void {
                $viewRenderEventManager->addTemplate('shopify::data-transfer.category-selection');
            });

            Event::listen("unopim.admin.settings.data_transfer.exports.{$screen}.card.accordion.filters.output.after", static function (ViewRenderEventManager $viewRenderEventManager): void {
                $viewRenderEventManager->addTemplate('shopify::data-transfer.category-output');
            });
        }

        Event::listen('unopim.admin.catalog.attributes.create.card.label.after', static function (ViewRenderEventManager $viewRenderEventManager): void {
            $viewRenderEventManager->addTemplate('shopify::catalog.attributes.metaobject-binding');
        });

        Event::listen('unopim.admin.catalog.attributes.edit.card.label.after', static function (ViewRenderEventManager $viewRenderEventManager): void {
            $viewRenderEventManager->addTemplate('shopify::catalog.attributes.metaobject-binding');
        });

        Event::listen('unopim.admin.catalog.attributes.list.after', static function (ViewRenderEventManager $viewRenderEventManager): void {
            $viewRenderEventManager->addTemplate('shopify::catalog.attributes.metaobject-binding-modal');
        });

        Event::listen('unopim.admin.settings.data_transfer.tracker.job.state.processing.after', static function (ViewRenderEventManager $viewRenderEventManager): void {
            $viewRenderEventManager->addTemplate('shopify::data-transfer.tracker.phase');
        });

        $requireMetaobjectDefinition = static function (): void {
            if (request()->input('type') === 'shopify_metaobject' && ! request()->filled('metaobject_definition')) {
                throw ValidationException::withMessages([
                    'type' => trans('shopify::app.shopify.attribute.metaobject-required'),
                ]);
            }
        };

        Event::listen('catalog.attribute.create.before', $requireMetaobjectDefinition);

        Event::listen('catalog.attribute.update.before', $requireMetaobjectDefinition);

        Event::listen('catalog.attribute.create.after', static function ($attribute): void {
            if (request()->input('type') === 'shopify_metaobject' && request()->input('metaobject_definition')) {
                resolve(ShopifyMetaobjectAttributeRepository::class)->saveBinding((int) $attribute->id, (int) request()->input('metaobject_definition'), request()->boolean('metaobject_multiple'));
            }
        });

        Event::listen('catalog.attribute.update.after', static function ($attribute): void {
            $repository = resolve(ShopifyMetaobjectAttributeRepository::class);

            if (request()->input('type') === 'shopify_metaobject' && request()->input('metaobject_definition')) {
                $repository->saveBinding((int) $attribute->id, (int) request()->input('metaobject_definition'), request()->boolean('metaobject_multiple'));

                return;
            }

            $repository->deleteBinding((int) $attribute->id);
        });

        Event::listen('catalog.attribute.delete.before', static function ($id): void {
            $attribute = resolve(AttributeRepository::class)->find((int) $id);

            if ($attribute?->type === 'shopify_metaobject') {
                resolve(ShopifyMetaFieldRepository::class)->deleteWhere([
                    ['code', '=', $attribute->code],
                    ['type', '=', 'metaobject_reference'],
                ]);
            }
        });

        Event::listen('catalog.attribute.delete.after', static function ($id): void {
            resolve(ShopifyMetaobjectAttributeRepository::class)->deleteBinding((int) $id);
        });

        Event::listen('catalog.attribute.create.before', static function (): void {
            if (
                request()->input('type') === 'shopify_taxonomy'
                && resolve(AttributeRepository::class)->findWhere(['type' => 'shopify_taxonomy'])->isNotEmpty()
            ) {
                throw ValidationException::withMessages([
                    'type' => trans('shopify::app.shopify.attribute.only-one'),
                ]);
            }
        });

        Event::listen('data_transfer.export.completed', [ExportUpdatedSummary::class, 'completed']);
        Event::listen('data_transfer.export.completed', [DeferJobTrackCompletion::class, 'handle']);

        $shopifyImportPlaceholder = static function (): string {
            $path = 'shopify/import-placeholder.csv';

            if (! Storage::disk('private')->exists($path)) {
                Storage::disk('private')->put($path, "handle,type\nplaceholder,placeholder\n");
            }

            return $path;
        };

        $ensureShopifyImportFilePath = static function ($import) use ($shopifyImportPlaceholder): void {
            if (! str_starts_with($import->entity_type ?? '', 'shopify') || ! empty($import->file_path)) {
                return;
            }

            $import->file_path = $shopifyImportPlaceholder();
            $import->field_separator = ',';
            $import->save();
        };

        JobInstancesProxy::creating(static function ($jobInstance): void {
            if (str_starts_with($jobInstance->entity_type ?? '', 'shopify') && empty($jobInstance->validation_strategy)) {
                $jobInstance->validation_strategy = 'stop-on-errors';
            }
        });

        Event::listen('data_transfer.imports.create.after', $ensureShopifyImportFilePath);

        Event::listen('data_transfer.imports.update.after', $ensureShopifyImportFilePath);

        Event::listen('data_transfer.imports.import.now.before', static function ($import) use ($shopifyImportPlaceholder): void {
            if (str_starts_with($import->entity_type ?? '', 'shopify')) {
                $shopifyImportPlaceholder();
            }
        });

        Event::listen('data_transfer.exports.create.before', function (): void {
            $this->keepProFiltersIntact();
        });

        Event::listen('data_transfer.exports.update.before', function (): void {
            $this->keepProFiltersIntact((int) request()->route('id'));
        });

        Event::listen('user.api_key.delete.before', [RevokeShopifyOnApiKeyDelete::class, 'handle']);

        $this->publishes([
            __DIR__.'/../../publishable' => public_path('themes'),
        ], 'shopify-config');
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->registerConfig();
    }

    /**
     * Without Pro a locked filter is offered but never taken from the request, so
     * a value carried over from another export is dropped and one the profile was
     * saved with survives untouched.
     */
    protected function keepProFiltersIntact(?int $id = null): void
    {
        $names = resolve(ProFeatures::class)->lockedExportFilters((string) request('entity_type'));

        if ($names === []) {
            return;
        }

        $filters = (array) request('filters', []);

        $stored = (array) (JobInstancesProxy::query()->whereKey($id)->value('filters') ?? []);

        foreach ($names as $name) {
            unset($filters[$name]);

            if (array_key_exists($name, $stored)) {
                $filters[$name] = $stored[$name];
            }
        }

        request()->merge(['filters' => $filters]);
    }

    /**
     * Contribute the Pro filter fields to every export the package extends,
     * ignoring any the connector already declares, and publish what was
     * contributed so the connector can mark those filters as Pro.
     */
    protected function appendExportFilterFields(): void
    {
        $contributed = [];

        foreach (array_keys(config('shopify_pro_filters', [])) as $entityType) {
            $existing = config("exporters.{$entityType}.filters.fields", []);

            $declared = array_column($existing, 'name');

            $additional = array_filter(
                config("shopify_pro_filters.{$entityType}.filters.fields", []),
                fn (array $field): bool => ! in_array($field['name'], $declared, true)
            );

            if ($additional === []) {
                continue;
            }

            foreach ($additional as $field) {
                $contributed[$entityType][$field['name']] = $field['title'] ?? null;
            }

            $fields = $this->pairStatusWithFamilies(array_merge($existing, $additional));

            config([
                "exporters.{$entityType}.filters.fields" => $this->inCoreOrder($fields),
            ]);
        }

        config(['shopify.pro.export_filters' => $contributed]);
    }

    /**
     * Drop the full width flag from status once attribute families shares its
     * row, so core renders the pair side by side as it does for its own export.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    protected function pairStatusWithFamilies(array $fields): array
    {
        $names = array_column($fields, 'name');

        if (! in_array('attribute_families', $names, true)) {
            return $fields;
        }

        return array_map(function (array $field): array {
            if ($field['name'] === 'status') {
                unset($field['full_width']);
            }

            return $field;
        }, $fields);
    }

    /**
     * Order the fields as core's product exporter declares them, since the core
     * field set renders in configuration order.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    protected function inCoreOrder(array $fields): array
    {
        $order = array_flip(array_column(config('exporters.products.filters.fields', []), 'name'));

        usort($fields, fn (array $a, array $b): int => ($order[$a['name']] ?? -1) <=> ($order[$b['name']] ?? -1));

        return array_values($fields);
    }

    /**
     * Offer the schedule fields on every export that may run unattended. The
     * card renders them; the Pro package is what acts on them.
     */
    protected function appendScheduleFilterFields(): void
    {
        foreach (config('shopify_schedule.entity_types', []) as $entityType) {
            $existing = config("exporters.{$entityType}.filters.fields", []);

            if ($existing === []) {
                continue;
            }

            $declared = array_column($existing, 'name');

            $additional = array_filter(
                config('shopify_schedule.fields', []),
                fn (array $field): bool => ! in_array($field['name'], $declared, true)
            );

            config([
                "exporters.{$entityType}.filters.fields" => array_merge($existing, $additional),
            ]);
        }
    }

    /**
     * Register package config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../Config/menu.php',
            'menu.admin'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/acl.php', 'acl'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/api-acl.php', 'api-acl'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/exporters.php', 'exporters'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/importers.php', 'importers'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/bulk_mutations.php', 'shopify_bulk_mutations'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/bulk_operations.php', 'shopify-bulk-operations'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/unopim-vite.php', 'unopim-vite.viters'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/saas.php', 'shopify.saas'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/shopify_taxonomy.php', 'shopify_taxonomy'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/attribute_types.php', 'attribute_types'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/schedule.php', 'shopify_schedule'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/pro-filters.php', 'shopify_pro_filters'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/pro.php', 'shopify.pro'
        );
        $this->mergeConfigFrom(
            __DIR__.'/../Config/pro-comparison.php', 'shopify.pro.comparison'
        );
    }
}
