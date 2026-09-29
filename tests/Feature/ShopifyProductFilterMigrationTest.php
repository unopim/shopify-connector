<?php

use Illuminate\Support\Facades\DB;

function shopifyFilterMigration(): object
{
    return require dirname(__DIR__, 2).'/src/Database/Migration/2026_09_09_120000_migrate_shopify_product_filters.php';
}

function seedShopifyJobInstance(string $code, string $entityType, array $filters): int
{
    return DB::table('job_instances')->insertGetId([
        'code'                => $code,
        'entity_type'         => $entityType,
        'type'                => 'export',
        'action'              => 'export',
        'validation_strategy' => 'stop-on-errors',
        'allowed_errors'      => 0,
        'field_separator'     => ',',
        'file_path'           => 'shopify-api',
        'filters'             => json_encode($filters),
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);
}

function shopifyJobFilters(int $id): array
{
    return json_decode((string) DB::table('job_instances')->where('id', $id)->value('filters'), true);
}

it('rewrites productfilter into a sku array', function () {
    $id = seedShopifyJobInstance('mig-sku', 'shopifyProduct', [
        'credentials'   => 1,
        'productfilter' => "SKU-1, SKU-2\nSKU-3",
    ]);

    shopifyFilterMigration()->up();

    $filters = shopifyJobFilters($id);

    expect($filters['sku'])->toBe(['SKU-1', 'SKU-2', 'SKU-3'])
        ->and($filters)->not->toHaveKey('productfilter')
        ->and($filters['credentials'])->toBe(1);
});

it('rewrites productstatus into status', function () {
    $id = seedShopifyJobInstance('mig-status', 'shopifyProduct', ['productstatus' => 'disable']);

    shopifyFilterMigration()->up();

    $filters = shopifyJobFilters($id);

    expect($filters['status'])->toBe('disable')
        ->and($filters)->not->toHaveKey('productstatus');
});

it('drops an empty productfilter without adding sku', function () {
    $id = seedShopifyJobInstance('mig-empty', 'shopifyProduct', ['productfilter' => '  ', 'productstatus' => '']);

    shopifyFilterMigration()->up();

    $filters = shopifyJobFilters($id);

    expect($filters)->not->toHaveKey('sku')
        ->and($filters)->not->toHaveKey('status')
        ->and($filters)->not->toHaveKey('productfilter')
        ->and($filters)->not->toHaveKey('productstatus');
});

it('leaves non-shopify job instances alone', function () {
    $id = seedShopifyJobInstance('mig-core', 'products', ['productfilter' => 'SKU-9']);

    shopifyFilterMigration()->up();

    $filters = shopifyJobFilters($id);

    expect($filters['productfilter'])->toBe('SKU-9')
        ->and($filters)->not->toHaveKey('sku');
});

it('reverses cleanly', function () {
    $id = seedShopifyJobInstance('mig-down', 'shopifyProduct', [
        'productfilter' => 'SKU-1,SKU-2',
        'productstatus' => 'enable',
    ]);

    $migration = shopifyFilterMigration();
    $migration->up();
    $migration->down();

    $filters = shopifyJobFilters($id);

    expect($filters['productfilter'])->toBe('SKU-1,SKU-2')
        ->and($filters['productstatus'])->toBe('enable')
        ->and($filters)->not->toHaveKey('sku')
        ->and($filters)->not->toHaveKey('status');
});

it('renames channel and currency to the core scope names', function () {
    $id = seedShopifyJobInstance('mig-scope', 'shopifyProduct', [
        'credentials' => 1,
        'channel'     => 'shopify_default',
        'currency'    => 'USD',
    ]);

    shopifyFilterMigration()->up();

    $filters = shopifyJobFilters($id);

    expect($filters['channels'])->toBe('shopify_default')
        ->and($filters['currencies'])->toBe('USD')
        ->and($filters)->not->toHaveKey('channel')
        ->and($filters)->not->toHaveKey('currency');
});

it('reverses the scope rename', function () {
    $id = seedShopifyJobInstance('mig-scope-down', 'shopifyProduct', [
        'channel'  => 'shopify_default',
        'currency' => 'USD',
    ]);

    $migration = shopifyFilterMigration();
    $migration->up();
    $migration->down();

    $filters = shopifyJobFilters($id);

    expect($filters['channel'])->toBe('shopify_default')
        ->and($filters['currency'])->toBe('USD')
        ->and($filters)->not->toHaveKey('channels');
});

it('leaves shopify import profiles untouched', function () {
    $id = seedShopifyJobInstance('mig-import', 'shopifyProductImport', [
        'channel'  => 'shopify_default',
        'currency' => 'USD',
    ]);

    shopifyFilterMigration()->up();

    $filters = shopifyJobFilters($id);

    expect($filters['channel'])->toBe('shopify_default')
        ->and($filters['currency'])->toBe('USD');
});
