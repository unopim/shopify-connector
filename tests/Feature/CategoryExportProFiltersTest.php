<?php

use Webkul\DataTransfer\Models\JobInstancesProxy;
use Webkul\Shopify\Support\ProFeatures;

use function Pest\Laravel\get;
use function Pest\Laravel\put;

/**
 * A saved category export, with the filters it was stored with.
 */
function categoryExportJob(array $filters = []): object
{
    return JobInstancesProxy::create([
        'code'        => 'category-pro-filters-'.uniqid(),
        'type'        => 'export',
        'entity_type' => 'shopifyCategories',
        'action'      => 'export',
        'filters'     => array_merge(['credentials' => 1], $filters),
    ]);
}

it('offers the category filters on the category export as pro filters', function () {
    config(['shopify.pro.installed' => false]);

    expect(array_column(config('exporters.shopifyCategories.filters.fields'), 'name'))
        ->toContain('credentials', 'locales', 'export_categories', 'with_media', 'with_children')
        ->and(resolve(ProFeatures::class)->lockedExportFilters('shopifyCategories'))
        ->toContain('locales', 'export_categories', 'with_media', 'with_children');
});

it('shows the category selection locked while the pro package is absent', function () {
    config(['shopify.pro.installed' => false]);

    $this->loginAsAdmin();

    $html = get(route('admin.settings.data_transfer.exports.edit', categoryExportJob()->id))->assertOk()->getContent();

    expect($html)->toContain(trans('shopify::app.shopify.export.category-filters.title'))
        ->toMatch('/data-shopify-category-selection[^>]*data-shopify-pro-locked/')
        ->toContain('only="export_categories"')
        ->toContain('only="with_children"');
});

it('shows the category selection unlocked once the pro package is there', function () {
    config(['shopify.pro.installed' => true]);

    $this->loginAsAdmin();

    $html = get(route('admin.settings.data_transfer.exports.edit', categoryExportJob()->id))->assertOk()->getContent();

    expect($html)->toContain('data-shopify-category-selection')
        ->not->toMatch('/data-shopify-category-selection[^>]*data-shopify-pro-locked/');
});

/**
 * The create screen holds the picked export as an object once one is chosen, so
 * the card follows the fields of the pick, as core's own cards do.
 */
it('shows the category selection on the create screen by the fields of the picked export', function () {
    $this->loginAsAdmin();

    expect(get(route('admin.settings.data_transfer.exports.create'))->assertOk()->getContent())
        ->toContain("v-if=\"filterFields.some(field => field.name === 'export_categories')\"")
        ->not->toContain("entityType === 'shopifyCategories'");
});

it('leaves the category selection off every other export', function () {
    $this->loginAsAdmin();

    $job = JobInstancesProxy::create([
        'code'        => 'metafield-no-selection-'.uniqid(),
        'type'        => 'export',
        'entity_type' => 'shopifyMetafield',
        'action'      => 'export',
        'filters'     => ['credentials' => 1],
    ]);

    expect(get(route('admin.settings.data_transfer.exports.edit', $job->id))->assertOk()->getContent())
        ->not->toContain('only="export_categories"');
});

it('keeps the saved category selection while the pro package is absent', function () {
    config(['shopify.pro.installed' => false]);

    $this->loginAsAdmin();

    $job = categoryExportJob(['export_categories' => ['saved_code']]);

    put(route('admin.settings.data_transfer.exports.update', $job->id), [
        'code'        => $job->code,
        'entity_type' => 'shopifyCategories',
        'filters'     => ['credentials' => 2, 'export_categories' => ['sneaked_code'], 'with_children' => '1'],
    ])->assertSessionHasNoErrors();

    expect($job->fresh()->filters)
        ->toMatchArray(['credentials' => 2, 'export_categories' => ['saved_code']])
        ->not->toHaveKey('with_children');
});
