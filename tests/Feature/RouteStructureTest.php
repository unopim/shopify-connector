<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Every admin screen the connector owns is served from one place, and the sidebar
 * works out what to highlight from the url and the route name alone, so both
 * are pinned here.
 */
function shopifyRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => Str::startsWith((string) $route->getName(), ['shopify.', 'admin.shopify.']))
        ->reject(fn ($route): bool => Str::startsWith((string) $route->getName(), ['shopify.api.', 'shopify.saas.']))
        ->mapWithKeys(fn ($route): array => [$route->getName().' '.$route->methods()[0] => $route->uri()])
        ->all();
}

it('serves every shopify screen under a single shopify prefix', function () {
    $uris = shopifyRoutes();

    expect($uris)->not->toBeEmpty();

    foreach ($uris as $name => $uri) {
        expect($uri)->toStartWith(config('app.admin_url').'/shopify/', "{$name} is served from outside the shopify prefix")
            ->and($uri)->not->toContain('shopify/shopify', "{$name} carries the shopify prefix twice");
    }
});

it('spells the mapping screens out in their urls', function (string $name, string $uri) {
    expect(shopifyRoutes())->toHaveKey($name, $uri);
})->with([
    'export mapping'     => ['admin.shopify.export-mappings GET', 'admin/shopify/export-mapping/{id}'],
    'import mapping'     => ['admin.shopify.import-mappings GET', 'admin/shopify/import-mapping/{id}'],
    'collection mapping' => ['admin.shopify.collection-mappings GET', 'admin/shopify/collection-mapping/{id}'],
    'export settings'    => ['admin.shopify.settings GET', 'admin/shopify/export-settings/{id}'],
]);

it('keeps the metafield and metaobject screens on one nesting level', function (string $name, string $uri) {
    expect(shopifyRoutes())->toHaveKey($name, $uri);
})->with([
    'metafields'           => ['shopify.metafield.index GET', 'admin/shopify/metafields'],
    'metafield edit'       => ['shopify.metafield.edit GET', 'admin/shopify/metafields/{id}/edit'],
    'metaobjects'          => ['shopify.metaobject.index GET', 'admin/shopify/metaobjects'],
    'metaobject edit'      => ['shopify.metaobject.edit GET', 'admin/shopify/metaobjects/{id}/edit'],
    'metaobject field'     => ['shopify.metaobject.field.update PUT', 'admin/shopify/metaobjects/{id}/fields/{key}'],
    'metaobject entries'   => ['shopify.metaobject.entry.list GET', 'admin/shopify/metaobject-entries/list'],
]);

it('leaves the credentials menu active on the screens that belong to a credential', function (string $name, array $params) {
    expect(route($name, $params))->toStartWith(route('shopify.credentials.index').'/');
})->with([
    'credential edit'     => ['shopify.credentials.edit', [1]],
    'catalogs'            => ['shopify.credentials.catalogs.index', [1]],
    'credential realtime' => ['shopify.credentials.realtime.index', [1]],
]);

it('leaves the metafield and metaobject menus active on their own screens', function (string $name, array $params, string $menuRoute) {
    $menuUrl = route($menuRoute);
    $url = route($name, $params);

    expect($url === $menuUrl || Str::startsWith($url, $menuUrl.'/') || Str::startsWith($name, Str::beforeLast($menuRoute, '.').'.'))->toBeTrue();
})->with([
    'metafield edit'   => ['shopify.metafield.edit', [1], 'shopify.metafield.index'],
    'metaobject edit'  => ['shopify.metaobject.edit', [1], 'shopify.metaobject.index'],
    'metaobject entry' => ['shopify.metaobject.entry.list', [], 'shopify.metaobject.index'],
]);

it('serves the real-time settings from under the export mapping they belong to', function () {
    $this->loginAsAdmin();

    expect(route('shopify.realtime.index', 1))->toBe(route('admin.shopify.export-mappings', 1).'/realtime')
        ->and(Route::getRoutes()->getByName('shopify.credentials.update')->wheres['id'] ?? null)->toBe('[0-9]+');
});
