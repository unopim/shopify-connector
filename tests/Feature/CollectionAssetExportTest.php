<?php

use Psr\Log\NullLogger;
use Webkul\Category\Models\CategoryField;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Repositories\AssetRepository;
use Webkul\Shopify\Helpers\Exporters\Category\Exporter;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;

use function Pest\Laravel\get;

beforeEach(function () {
    if (! class_exists(Asset::class)) {
        $this->markTestSkipped('unopim/dam is not installed.');
    }
});

function collectionAssetField(string $code = 'collection_asset'): CategoryField
{
    return CategoryField::firstOrCreate(['code' => $code], [
        'type'       => 'asset',
        'status'     => 1,
        'is_unique'  => 0,
        'is_required'=> 0,
        'position'   => 99,
    ]);
}

function damAsset(array $attributes = []): Asset
{
    return Asset::create(array_merge([
        'file_name' => 'collection-'.uniqid().'.jpg',
        'file_type' => 'image',
        'file_size' => 1024,
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'path'      => 'dam/collection-'.uniqid().'.jpg',
    ], $attributes));
}

/**
 * A real exporter with only the Shopify upload faked, built through the
 * container so its promoted dependencies are wired the way the job wires them.
 */
function partialExporter(): object
{
    $arguments = array_map(
        fn (ReflectionParameter $parameter) => app($parameter->getType()->getName()),
        (new ReflectionClass(Exporter::class))->getConstructor()->getParameters(),
    );

    $exporter = Mockery::mock(Exporter::class, $arguments)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $exporter->setLogger(new NullLogger);

    return $exporter;
}

/**
 * Resolve the collection image the exporter would send, with the mapping and the
 * category values the job would carry.
 */
function collectionImageFor(array $merged, string $fieldCode, ?object $exporter = null): ?string
{
    $exporter ??= resolve(Exporter::class);

    $credential = ShopifyCredentialsConfig::factory()->create(['shopUrl' => 'https://asset-'.uniqid().'.myshopify.com']);

    (function () use ($credential) {
        $this->credential = $credential;
    })->call($exporter);

    return (function () use ($merged, $fieldCode) {
        return $this->resolveCollectionImageUrl(
            ['mediaMapping' => ['mediaAttributes' => $fieldCode]],
            $merged,
            'CAT-1',
        );
    })->call($exporter);
}

it('offers asset fields to the collection image mapping', function () {
    $this->loginAsAdmin();

    expect(get(route('admin.shopify.collection-mappings', 4))->assertOk()->getContent())
        ->toContain('[&quot;image&quot;,&quot;file&quot;,&quot;asset&quot;]');
});

it('lists an asset category field on the endpoint the mapping reads', function () {
    $this->loginAsAdmin();

    $field = collectionAssetField();

    $options = get(route('admin.shopify.get-category-field').'?'.http_build_query([
        'entityName' => json_encode(['image', 'file', 'asset']),
    ]))->assertOk()->json('options');

    expect(collect($options)->firstWhere('code', $field->code))->not->toBeNull();
});

it('sends the staged upload of the mapped asset as the collection image', function () {
    $field = collectionAssetField();
    $asset = damAsset();

    $exporter = partialExporter();
    $exporter->shouldReceive('stageAssetUpload')->once()->andReturn('https://shopify-staged-uploads/collection.jpg');

    expect(collectionImageFor([$field->code => (string) $asset->id], $field->code, $exporter))
        ->toBe('https://shopify-staged-uploads/collection.jpg');
});

it('sends the first image and reports the rest', function () {
    $field = collectionAssetField();
    $first = damAsset();
    $second = damAsset();

    $exporter = partialExporter();
    $exporter->shouldReceive('stageAssetUpload')
        ->once()
        ->withArgs(fn (array $asset): bool => $asset['id'] === $first->id)
        ->andReturn('https://shopify-staged-uploads/first.jpg');

    expect(collectionImageFor([$field->code => $first->id.','.$second->id], $field->code, $exporter))
        ->toBe('https://shopify-staged-uploads/first.jpg');
});

it('exports no image when the mapped asset is not an image', function () {
    $field = collectionAssetField();
    $asset = damAsset(['mime_type' => 'application/pdf', 'file_type' => 'document', 'extension' => 'pdf']);

    $exporter = partialExporter();
    $exporter->shouldReceive('stageAssetUpload')->never();

    expect(collectionImageFor([$field->code => (string) $asset->id], $field->code, $exporter))->toBeNull();
});

it('exports no image when the mapped asset is gone', function () {
    $field = collectionAssetField();

    $exporter = partialExporter();
    $exporter->shouldReceive('stageAssetUpload')->never();

    expect(collectionImageFor([$field->code => '999999999'], $field->code, $exporter))->toBeNull();
});

it('keeps the collection when the asset cannot be staged', function () {
    $field = collectionAssetField();
    $asset = damAsset();

    $exporter = partialExporter();
    $exporter->shouldReceive('stageAssetUpload')->once()->andReturn(null);

    expect(collectionImageFor([$field->code => (string) $asset->id], $field->code, $exporter))->toBeNull();
});

it('leaves an image field on the path it already used', function () {
    $field = CategoryField::firstOrCreate(['code' => 'collection_image_field'], [
        'type'        => 'image',
        'status'      => 1,
        'is_unique'   => 0,
        'is_required' => 0,
        'position'    => 98,
    ]);

    $exporter = partialExporter();
    $exporter->shouldReceive('stageAssetUpload')->never();

    $url = collectionImageFor([$field->code => 'category/demo.jpg'], $field->code, $exporter);

    expect($url === null || str_contains($url, 'category/demo.jpg'))->toBeTrue();
});

it('reads the mapped category field once for a whole batch', function () {
    $field = collectionAssetField();
    $asset = damAsset();

    $exporter = partialExporter();
    $exporter->shouldReceive('stageAssetUpload')->andReturn('https://shopify-staged-uploads/collection.jpg');

    collectionImageFor([$field->code => (string) $asset->id], $field->code, $exporter);

    $reads = 0;

    DB::listen(function ($query) use (&$reads) {
        if (str_contains($query->sql, 'category_fields')) {
            $reads++;
        }
    });

    collectionImageFor([$field->code => (string) $asset->id], $field->code, $exporter);

    expect($reads)->toBe(0);
});

it('resolves the DAM asset repository when the container builds the exporter', function () {
    $exporter = resolve(Exporter::class);

    expect((fn () => $this->assetRepository())->call($exporter))
        ->toBeInstanceOf(AssetRepository::class);
});
