<?php

use Webkul\DataTransfer\Models\JobInstancesProxy;

use function Pest\Laravel\get;

/**
 * The fields core renders in its own output card, which marks where that card is.
 */
const CORE_OUTPUT_FIELDS = 'only="file_format,with_media,with_associations,header_row,use_labels,date_format,file_path"';

/**
 * The edit screen of a saved Shopify export.
 */
function shopifyExportEditScreen(string $entityType): string
{
    $job = JobInstancesProxy::create([
        'code'        => 'output-card-'.uniqid(),
        'type'        => 'export',
        'entity_type' => $entityType,
        'action'      => 'export',
        'filters'     => ['credentials' => 1],
    ]);

    return get(route('admin.settings.data_transfer.exports.edit', $job->id))->assertOk()->getContent();
}

it('puts the credentials at the top of the output card, with the schedule below it', function (string $entityType) {
    $this->loginAsAdmin();

    $html = shopifyExportEditScreen($entityType);

    $credentials = strpos($html, 'only="credentials"');
    $output = strpos($html, CORE_OUTPUT_FIELDS);
    $schedule = strpos($html, 'only="schedule_');

    expect(substr_count($html, 'only="credentials"'))->toBe(1)
        ->and($credentials)->toBeLessThan($output)
        ->and($schedule)->toBeGreaterThan($output)
        ->and($html)->not->toContain(trans('shopify::app.shopify.export.filters.shopify'));
})->with(['shopifyProduct', 'shopifyCategories']);

it('gives the credentials an output card of their own where core has none', function (string $entityType) {
    $this->loginAsAdmin();

    $html = shopifyExportEditScreen($entityType);

    $credentials = strpos($html, 'only="credentials"');
    $schedule = strpos($html, 'only="schedule_');

    expect($html)->not->toContain(CORE_OUTPUT_FIELDS)
        ->and(substr_count($html, 'only="credentials"'))->toBe(1)
        ->and($credentials)->toBeGreaterThan(strpos($html, 'shopify-export-output'))
        ->and($schedule)->toBeGreaterThan($credentials)
        ->and($html)->not->toContain(trans('shopify::app.shopify.export.filters.shopify'));
})->with(['shopifyMetafield', 'shopifyMetaobject']);

it('picks the credentials placement on the create screen by the fields of the picked export', function () {
    $this->loginAsAdmin();

    $html = get(route('admin.settings.data_transfer.exports.create'))->assertOk()->getContent();

    expect(substr_count($html, 'only="credentials"'))->toBe(2)
        ->and($html)->not->toContain(trans('shopify::app.shopify.export.filters.shopify'))
        ->and(strpos($html, 'only="schedule_'))->toBeGreaterThan(strpos($html, CORE_OUTPUT_FIELDS));
});
