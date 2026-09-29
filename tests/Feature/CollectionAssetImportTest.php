<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Webkul\Category\Models\CategoryField;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\Directory;
use Webkul\Shopify\Helpers\Importers\Category\Importer;

beforeEach(function () {
    if (! class_exists(Asset::class)) {
        $this->markTestSkipped('unopim/dam is not installed.');
    }

    Storage::fake(Directory::getAssetDisk());

    Http::fake([
        '*' => Http::response('binary-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);
});

function importAssetField(string $code, bool $perLocale = false): CategoryField
{
    return CategoryField::firstOrCreate(['code' => $code], [
        'type'             => 'asset',
        'status'           => 1,
        'is_unique'        => 0,
        'is_required'      => 0,
        'value_per_locale' => $perLocale,
        'position'         => 98,
    ]);
}

function categoryImporter(array $mediaAttributes = []): Importer
{
    $importer = resolve(Importer::class);

    (fn () => $this->collectionMapping = (object) ['mapping' => ['mediaMapping' => ['mediaAttributes' => $mediaAttributes]]])
        ->call($importer);

    return $importer;
}

/**
 * Invoke a protected importer method the way the batch does.
 */
function callImporter(Importer $importer, string $method, array $arguments)
{
    return (fn () => $this->{$method}(...$arguments))->call($importer);
}

it('stores a Shopify collection image as a DAM asset for an asset field', function () {
    $url = 'https://cdn.shopify.com/collection-'.uniqid().'.jpg';

    $value = callImporter(categoryImporter(), 'storeCollectionImage', ['asset', $url, 'collection_image', 'shoes']);

    expect($value)->toBeString()->not->toBeEmpty();
    expect(Asset::whereKey((int) $value)->exists())->toBeTrue();
});

it('reuses the same DAM asset when the collection image url repeats', function () {
    $url = 'https://cdn.shopify.com/collection-'.uniqid().'.jpg';
    $importer = categoryImporter();

    $first = callImporter($importer, 'storeCollectionImage', ['asset', $url, 'collection_image', 'shoes']);
    $second = callImporter($importer, 'storeCollectionImage', ['asset', $url, 'collection_image', 'shoes']);

    expect($second)->toBe($first);
});

it('writes the asset id into the category common values', function () {
    $field = importAssetField('collection_asset_import');
    $url = 'https://cdn.shopify.com/collection-'.uniqid().'.jpg';

    $importer = categoryImporter([$field->code]);

    $data = [];
    callImporter($importer, 'mapCollectionImage', [
        ['node' => ['handle' => 'shoes', 'image' => ['url' => $url]]],
        &$data,
    ]);

    $value = $data['additional_data'][CategoryRepository::COMMON_VALUES_KEY][$field->code] ?? null;

    expect($value)->toBeString()->not->toBeEmpty();
    expect(Asset::whereKey((int) $value)->exists())->toBeTrue();
});

it('clears a mapped asset field when the collection has no image', function () {
    $field = importAssetField('collection_asset_clear');

    $importer = categoryImporter([$field->code]);

    $data = ['additional_data' => [CategoryRepository::COMMON_VALUES_KEY => [$field->code => '7']]];

    callImporter($importer, 'mapCollectionImage', [
        ['node' => ['handle' => 'shoes', 'image' => null]],
        &$data,
    ]);

    expect($data['additional_data'][CategoryRepository::COMMON_VALUES_KEY][$field->code] ?? null)->toBeNull();
});

it('keeps the storage path branch for image fields', function () {
    expect(Importer::MEDIA_FIELD_TYPES)->toBe(['image', 'file', 'asset']);
});
