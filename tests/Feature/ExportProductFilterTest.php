<?php

use Webkul\Shopify\Helpers\Exporters\Product\Exporter;

function shopifyExporterStub(): Exporter
{
    return Mockery::mock(Exporter::class)->makePartial()->shouldAllowMockingProtectedMethods();
}

it('declares core sku and status filters and not the legacy pair', function () {
    $names = collect(config('exporters.shopifyProduct.filters.fields'))->pluck('name');

    expect($names)->toContain('sku')
        ->and($names)->toContain('status')
        ->and($names)->not->toContain('productfilter')
        ->and($names)->not->toContain('productstatus');
});

it('uses core scope names for channel and currency', function () {
    $names = collect(config('exporters.shopifyProduct.filters.fields'))->pluck('name');

    expect($names)->toContain('channels')
        ->and($names)->toContain('currencies')
        ->and($names)->not->toContain('channel')
        ->and($names)->not->toContain('currency');
});

it('keeps the singular scope names on the shopify product import', function () {
    $names = collect(config('importers.shopifyProduct.filters.fields') ?? [])->pluck('name');

    expect($names)->toContain('channel')
        ->and($names)->toContain('currency');
});

it('renders status full width so it fills core two column row on its own', function () {
    $status = collect(config('exporters.shopifyProduct.filters.fields'))->firstWhere('name', 'status');

    // Without ShopifyPro there is no attribute_families beside it, so status must
    // span the row rather than sit at half width with an empty column.
    $families = collect(config('exporters.shopifyProduct.filters.fields'))->firstWhere('name', 'attribute_families');

    expect($families === null ? ($status['full_width'] ?? false) : ! ($status['full_width'] ?? false))->toBeTrue();
});

it('reports no product filters when none are set', function () {
    $exporter = shopifyExporterStub();

    expect($exporter->hasProductFilters([]))->toBeFalse()
        ->and($exporter->hasProductFilters(['completeness' => 'none']))->toBeFalse()
        ->and($exporter->hasProductFilters(['sku' => []]))->toBeFalse();
});

it('reports product filters when a core filter is set', function () {
    $exporter = shopifyExporterStub();

    expect($exporter->hasProductFilters(['status' => 'enable']))->toBeTrue()
        ->and($exporter->hasProductFilters(['sku' => ['ABC']]))->toBeTrue()
        ->and($exporter->hasProductFilters(['completeness' => 'all']))->toBeTrue()
        ->and($exporter->hasProductFilters(['attribute_families' => ['default']]))->toBeTrue()
        ->and($exporter->hasProductFilters(['updated_after' => '2026-01-01 00:00:00']))->toBeTrue();
});
