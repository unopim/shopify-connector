<?php

use Illuminate\Support\Facades\Event;
use Psr\Log\NullLogger;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Models\JobInstancesProxy;
use Webkul\DataTransfer\Models\JobTrack;
use Webkul\DataTransfer\Models\JobTrackBatch;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;

/**
 * A category export run with one batch, as the job creates them.
 *
 * @return array{track: object, batch: object}
 */
function categoryExportRun(): array
{
    $jobInstance = JobInstancesProxy::create([
        'code'        => 'category-summary-'.uniqid(),
        'entity_type' => 'shopifyCategories',
        'type'        => 'export',
        'action'      => 'export',
        'filters'     => ['credentials' => 1],
    ]);

    $track = JobTrack::factory()->export()->create([
        'state'            => ExportHelper::STATE_PROCESSING,
        'job_instances_id' => $jobInstance->id,
        'user_id'          => auth()->guard('admin')->id(),
    ]);

    $batch = JobTrackBatch::factory()->create([
        'job_track_id' => $track->id,
        'state'        => ExportHelper::STATE_VALIDATED,
        'data'         => [
            ['code' => 'new_collection', 'additional_data' => ['common' => ['name' => 'New']]],
            ['code' => 'known_collection', 'additional_data' => ['common' => ['name' => 'Known']]],
            ['code' => 'deleted_collection', 'additional_data' => ['common' => ['name' => 'Deleted']]],
        ],
    ]);

    return compact('track', 'batch');
}

/**
 * The job's category exporter with every call to Shopify answered in place:
 * `known_collection` is mapped to a live collection, `deleted_collection` to one
 * deleted in Shopify, so it is created again, and `new_collection` is not mapped.
 */
function countingCategoryExporter(object $track): object
{
    $class = config('exporters.shopifyCategories.exporter');

    $arguments = array_map(
        fn (ReflectionParameter $parameter) => app($parameter->getType()->getName()),
        (new ReflectionClass($class))->getConstructor()->getParameters(),
    );

    $exporter = Mockery::mock($class, $arguments)->makePartial()->shouldAllowMockingProtectedMethods();

    $exporter->setExport($track)->setLogger(new NullLogger);

    $exporter->shouldReceive('checkMappingInDb')->andReturnUsing(
        fn (array $item): ?array => $item['code'] === 'new_collection' ? null : [['id' => 1, 'externalId' => 'gid://shopify/Collection/1']]
    );

    $collection = ['collection' => ['id' => 'gid://shopify/Collection/1', 'title' => 'Title'], 'userErrors' => []];

    $missing = ['collection' => null, 'userErrors' => [['field' => ['id'], 'message' => 'Collection does not exist']]];

    $exporter->shouldReceive('apiRequestShopify')->andReturnUsing(fn (array $category, ?string $id = null): array => [
        'body' => ['data' => [$id ? 'collectionUpdate' : 'collectionCreate' => $category['title'] === 'Deleted' ? $missing : $collection]],
    ]);

    $exporter->shouldReceive('handleAfterApiRequest')->andReturn($collection);
    $exporter->shouldReceive('categoryTranslation')->andReturnNull();

    $credential = ShopifyCredentialsConfig::factory()->make();

    (function () use ($credential): void {
        $this->credential = $credential;
        $this->credentialArray = [];
        $this->collectionMapping = (object) ['mapping' => ['collection_mapping' => ['title' => 'name']]];
        $this->shopifyDefaultLocale = 'en_US';
    })->call($exporter);

    return $exporter;
}

beforeEach(fn () => test()->loginAsAdmin());

it('counts a live collection as updated and one recreated after a delete in shopify as created', function () {
    ['track' => $track, 'batch' => $batch] = categoryExportRun();

    $exporter = countingCategoryExporter($track);

    $exporter->prepareCategoriesShopify($batch, null);
    $exporter->updateBatchState($batch->id, ExportHelper::STATE_PROCESSED);

    expect($batch->refresh()->summary)
        ->toMatchArray(['processed' => 3, 'created' => 2, 'updated' => 1, 'skipped' => 0]);
});

it('carries the updated count into the finished job summary', function () {
    ['track' => $track, 'batch' => $batch] = categoryExportRun();

    $exporter = countingCategoryExporter($track);

    $exporter->prepareCategoriesShopify($batch, null);
    $exporter->updateBatchState($batch->id, ExportHelper::STATE_PROCESSED);

    Event::dispatch('data_transfer.export.completed', $track->refresh());

    expect($track->refresh()->summary)
        ->toMatchArray(['processed' => 3, 'created' => 2, 'updated' => 1, 'skipped' => 0]);
});

it('leaves the summary of every other export to core', function () {
    $jobInstance = JobInstancesProxy::create([
        'code'        => 'metafield-summary-'.uniqid(),
        'entity_type' => 'shopifyMetafield',
        'type'        => 'export',
        'action'      => 'export',
        'filters'     => ['credentials' => 1],
    ]);

    $track = JobTrack::factory()->export()->create([
        'state'            => ExportHelper::STATE_COMPLETED,
        'job_instances_id' => $jobInstance->id,
        'user_id'          => auth()->guard('admin')->id(),
        'summary'          => ['processed' => 3, 'created' => 3, 'skipped' => 0],
    ]);

    Event::dispatch('data_transfer.export.completed', $track);

    expect($track->refresh()->summary)->toBe(['processed' => 3, 'created' => 3, 'skipped' => 0]);
});
