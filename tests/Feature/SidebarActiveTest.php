<?php

use Webkul\Shopify\Models\ShopifyCredentialsConfig;

use function Pest\Laravel\get;

/**
 * The sidebar marks the entry a screen belongs to, which it works out from the
 * url alone, so every screen is opened for real and the highlighted entry read
 * back off the page.
 */
function highlightedMenuEntries(string $html): array
{
    preg_match_all('/<a\s+href="[^"]+"\s+class="([^"]*)"[^>]*>\s*([^<]+?)\s*</s', $html, $matches, PREG_SET_ORDER);

    return collect($matches)
        ->filter(fn (array $match): bool => in_array('text-primary-700', preg_split('/\s+/', $match[1]), true))
        ->map(fn (array $match): string => trim($match[2]))
        ->unique()
        ->values()
        ->all();
}

it('marks the credentials entry on every screen that belongs to a credential', function (string $routeName) {
    $this->loginAsAdmin();

    $credential = ShopifyCredentialsConfig::factory()->create();

    $page = get(route($routeName, [$credential->id, $credential->id]))->assertOk();

    expect(highlightedMenuEntries($page->getContent()))
        ->toContain(trans('shopify::app.components.layouts.sidebar.credentials'));
})->with([
    'credential edit'     => 'shopify.credentials.edit',
    'catalogs'            => 'shopify.credentials.catalogs.index',
    'credential realtime' => 'shopify.credentials.realtime.index',
]);

it('marks its own entry on every screen that stands on its own', function (string $routeName, array $params, string $label) {
    $this->loginAsAdmin();

    $page = get(route($routeName, $params))->assertOk();

    expect(highlightedMenuEntries($page->getContent()))->toContain(trans($label));
})->with([
    'credentials'        => ['shopify.credentials.index', [], 'shopify::app.components.layouts.sidebar.credentials'],
    'realtime settings'  => ['shopify.realtime.index', [1], 'shopify::app.components.layouts.sidebar.export-mappings'],
    'metafields'         => ['shopify.metafield.index', [], 'shopify::app.components.layouts.sidebar.meta-fields'],
    'metaobjects'        => ['shopify.metaobject.index', [], 'shopify::app.components.layouts.sidebar.metaobjects'],
    'export mapping'     => ['admin.shopify.export-mappings', [1], 'shopify::app.components.layouts.sidebar.export-mappings'],
    'import mapping'     => ['admin.shopify.import-mappings', [3], 'shopify::app.components.layouts.sidebar.import-mappings'],
    'collection mapping' => ['admin.shopify.collection-mappings', [4], 'shopify::app.components.layouts.sidebar.collection-mappings'],
    'export settings'    => ['admin.shopify.settings', [2], 'shopify::app.components.layouts.sidebar.settings'],
]);
