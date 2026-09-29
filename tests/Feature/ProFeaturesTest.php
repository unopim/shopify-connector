<?php

use Illuminate\Support\Facades\Blade;
use Webkul\Shopify\Helpers\ShoifyMetaFieldType;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;
use Webkul\Shopify\Support\ProFeatures;
use Webkul\Shopify\Support\ShopifyMapping;

use function Pest\Laravel\get;

/**
 * The Pro package ships in this repository, so its absence is simulated by
 * clearing the flag it sets while booting.
 */
function withoutShopifyPro(): void
{
    config(['shopify.pro.installed' => false]);
}

/**
 * Raises the same flag the Pro package raises, so a suite running without the
 * package still covers what the screens do once it is there.
 */
function withShopifyPro(): void
{
    config(['shopify.pro.installed' => true]);
}

/**
 * True only while the package itself booted, for the few expectations its own
 * boot has to satisfy.
 */
function shopifyProBooted(): bool
{
    return (bool) config('shopify.pro.installed');
}

it('detects the installed pro package', function () {
    withShopifyPro();

    expect(app(ProFeatures::class)->isInstalled())->toBeTrue();
});

it('detects an absent pro package', function () {
    withoutShopifyPro();

    expect(app(ProFeatures::class)->isInstalled())->toBeFalse();
});

it('wears one pro mark, installed or not', function () {
    withShopifyPro();

    $withPro = Blade::render('<x-shopify::pro-cta variant="badge" />');

    withoutShopifyPro();

    expect($withPro)
        ->toContain(trans('shopify::app.shopify.pro.badge'))
        ->toContain('shopify-pro-badge')
        ->and(Blade::render('<x-shopify::pro-cta variant="badge" />'))->toBe($withPro);
});

it('decorates the pro export filter labels while the pro package is installed', function () {
    withShopifyPro();

    $html = view('shopify::data-transfer.pro-filter-badges')->render();

    expect($html)
        ->toContain('v-shopify-pro-filter-badges')
        ->toContain('shopify-pro-badge')
        ->toContain(':locked="false"');
});

it('locks the pro filters while the pro package is absent', function () {
    withoutShopifyPro();

    $html = view('shopify::data-transfer.pro-filter-badges')->render();

    expect($html)
        ->toContain('v-shopify-pro-filter-badges')
        ->toContain(':locked="true"')
        ->toContain(trans('shopify::app.shopify.pro.badge'));
});

function proNotice(string $variant): string
{
    return Blade::render(
        '<x-shopify::pro-notice :variant="$variant" title="Association Mapping" note="Mapped on Pro." />',
        compact('variant'),
    );
}

it('names a pro section and offers to unlock it while the package is absent', function () {
    withoutShopifyPro();

    expect(proNotice('section'))
        ->toContain('Association Mapping')
        ->toContain('Mapped on Pro.')
        ->toContain(trans('shopify::app.shopify.pro.badge'))
        ->toContain(trans('shopify::app.shopify.pro.unlock'))
        ->toContain(config('shopify.pro.url'))
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"');
});

it('opens the sidebar upgrade link in a new tab', function () {
    $menu = require dirname(__DIR__, 2).'/src/Config/menu.php';
    $upgradeMenuItem = collect($menu)->firstWhere('key', 'shopify.upgrade');

    config(['menu.admin' => [...config('menu.admin'), $upgradeMenuItem]]);

    $this->loginAsAdmin();

    get(route('shopify.credentials.index'))
        ->assertOk()
        ->assertSee('href="'.config('shopify.pro.url').'"', false);

    $html = view('shopify::pro.styles')->render();

    expect($html)
        ->toContain(json_encode(config('shopify.pro.url')))
        ->toContain('a[href="'.config('shopify.pro.url').'"]')
        ->toContain("link.target = '_blank'")
        ->toContain("link.rel = 'noopener noreferrer'")
        ->toContain("'unopim:navigate:success'");
});

it('sums a pro screen up once while the package is absent', function () {
    withoutShopifyPro();

    expect(proNotice('page'))
        ->toContain(trans('shopify::app.shopify.pro.summary'))
        ->toContain(e(trans('shopify::app.shopify.pro.tagline')))
        ->toContain(e(trans('shopify::app.shopify.pro.compare')))
        ->toContain(trans('shopify::app.shopify.pro.upgrade'));
});

it('leaves a pro section as its own heading while the package is installed', function () {
    withShopifyPro();

    expect(proNotice('section'))
        ->toContain('Association Mapping')
        ->not->toContain(trans('shopify::app.shopify.pro.unlock'))
        ->and(trim(proNotice('page')))->toBe('');
});

it('names only filters the export screens actually render', function () {
    $map = app(ProFeatures::class)->exportFilterMap();

    expect($map)->not->toBeEmpty();

    foreach ($map as $entityType => $names) {
        $rendered = array_column((array) config("exporters.{$entityType}.filters.fields", []), 'name');

        expect(array_diff($names, $rendered))->toBe([]);
    }
});

it('badges no core shopify export filter', function () {
    $coreExporters = require base_path('packages/Webkul/Shopify/src/Config/exporters.php');

    foreach (app(ProFeatures::class)->exportFilterMap() as $entityType => $names) {
        $coreNames = array_column($coreExporters[$entityType]['filters']['fields'] ?? [], 'name');

        expect(array_intersect($names, $coreNames))->toBe([]);
    }
});

it('treats currencies as core on the product export and pro on the metaobject export', function () {
    $map = app(ProFeatures::class)->exportFilterMap();

    expect($map['shopifyProduct'])->not->toContain('currencies')
        ->and($map['shopifyMetaobject'])->toContain('currencies');
});

it('serves the shopify screens while the pro package is absent', function () {
    withoutShopifyPro();

    $this->loginAsAdmin();

    get(route('admin.shopify.export-mappings', 1))
        ->assertOk()
        ->assertSeeText(trans('shopify::app.shopify.pro.summary'));

    get(route('admin.shopify.import-mappings', 3))
        ->assertOk()
        ->assertSeeText(trans('shopify::app.shopify.pro.summary'));

    get(route('shopify.metafield.index'))
        ->assertOk()
        ->assertSeeText(trans('shopify::app.shopify.pro.types-note'));

    get(route('shopify.metaobject.index'))
        ->assertOk()
        ->assertSeeText(trans('shopify::app.shopify.pro.types-note'));

    get(route('admin.settings.data_transfer.exports.create'))
        ->assertOk()
        ->assertSee(':locked="true"', false);
});

it('sends the upgrade entry to the comparison, which is what leads on to the store', function () {
    withoutShopifyPro();

    $this->loginAsAdmin();

    $html = get(route('shopify.upgrade'))->assertOk()->getContent();

    expect($html)
        ->toContain(trans('shopify::app.shopify.pro.comparison.title'))
        ->toContain(trans('shopify::app.shopify.pro.comparison.community'))
        ->toContain(config('shopify.pro.url'));
});

it('keeps the upgrade menu entry out of the sidebar while pro is installed', function () {
    expect(collect(config('menu.admin'))->pluck('key'))->not->toContain('shopify.upgrade');
})->skip(fn (): bool => ! shopifyProBooted(), 'The Pro package is the one that hides the entry.');

it('offers the catalog screen without its list while the pro package is absent', function () {
    withoutShopifyPro();

    $this->loginAsAdmin();

    $credential = ShopifyCredentialsConfig::factory()->create();

    get(route('shopify.credentials.catalogs.index', $credential->id))
        ->assertOk()
        ->assertSeeText(trans('shopify::app.shopify.pro.summary'))
        ->assertDontSeeText(trans('shopify::app.shopify.catalogs.create'));
});

it('offers the real time screens read only while the pro package is absent', function () {
    withoutShopifyPro();

    $this->loginAsAdmin();

    $credential = ShopifyCredentialsConfig::factory()->create();

    get(route('shopify.realtime.index', 1))
        ->assertOk()
        ->assertSeeText(trans('shopify::app.shopify.pro.summary'))
        ->assertSee('action="#"', false);

    get(route('shopify.credentials.realtime.index', $credential->id))
        ->assertOk()
        ->assertSeeText(trans('shopify::app.shopify.pro.summary'))
        ->assertSee('action="#"', false);
});

it('offers the mapping sections and the schedule read only while the pro package is absent', function () {
    withoutShopifyPro();

    $this->loginAsAdmin();

    get(route('admin.shopify.export-mappings', ShopifyMapping::EXPORT_ID))
        ->assertOk()
        ->assertSee(trans('shopify::app.shopify.association-mapping.title'))
        ->assertSee(trans('shopify::app.shopify.external-media.title'))
        ->assertSeeText(trans('shopify::app.shopify.pro.summary'));

    get(route('admin.settings.data_transfer.exports.create'))
        ->assertOk()
        ->assertSeeText(trans('shopify::app.export.schedule.title'))
        ->assertSeeText(trans('shopify::app.shopify.pro.summary'));
});

/**
 * The connector's own files are read straight from disk, because what matters
 * is what it declares alone: a suite running beside Pro sees the merged config.
 * That Pro then declares these lives in Pro's own suite.
 */
it('leaves the pro jobs for the pro package to declare', function () {
    $config = fn (string $group): array => require dirname(__DIR__, 2)."/src/Config/{$group}.php";

    expect(array_keys($config('exporters')))->not->toContain('shopifyCatalog');
    expect(array_keys($config('importers')))->not->toContain('shopifyCatalog', 'shopifyCatalogPrice');
});

it('refuses a pro metafield type while the package is absent', function () {
    withoutShopifyPro();

    $this->loginAsAdmin();

    $this->post(route('shopify.metafield.store'), [
        'ownerType' => 'PRODUCT',
        'code'      => 'pro_locked_'.uniqid(),
        'type'      => 'money',
    ])->assertSessionHasErrors('type');

    expect(resolve(ProFeatures::class)->lockedMetafieldTypes())
        ->toContain('money')
        ->toContain('area');
});

it('marks the pro types unselectable while the package is absent', function () {
    withoutShopifyPro();

    $types = resolve(ShoifyMetaFieldType::class)->getMetaFieldType();

    expect($types['price'][0])->toHaveKey('$isDisabled')
        ->and($types['measurement'][0])->toHaveKey('$isDisabled');
});

it('names the filters core renders under a heading instead of a label', function () {
    expect(resolve(ProFeatures::class)->exportFilterHeadings())
        ->toBe([trans('admin::app.settings.data-transfer.exports.create.attribute-conditions') => 'custom_attributes']);
});
