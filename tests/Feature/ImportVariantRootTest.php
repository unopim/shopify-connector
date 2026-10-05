<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Psr\Log\NullLogger;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Product\Enums\ProductType;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\VariantStructure;
use Webkul\Product\Models\VariantStructureAxis;
use Webkul\Shopify\Helpers\Importers\Product\Importer;

uses(DatabaseTransactions::class);

/**
 * A Shopify product is imported against the UnoPim configurable its variants
 * belong to. A two-level structure puts a variant_group in between, and writing
 * the product level values onto that group is what core's variant guard rejects.
 */
it('resolves a nested leaf up to its configurable root', function () {
    $root = Product::factory()->configurable()->create(['sku' => 'root-'.uniqid()]);
    $group = Product::factory()->create(['sku' => 'group-'.uniqid(), 'type' => 'variant_group', 'parent_id' => $root->id]);
    $leaf = Product::factory()->create(['sku' => 'leaf-'.uniqid(), 'parent_id' => $group->id]);

    expect(resolve(Importer::class)->rootProductOf($leaf)?->id)->toBe($root->id);
});

it('resolves a flat variant up to its configurable', function () {
    $root = Product::factory()->configurable()->create(['sku' => 'flat-'.uniqid()]);
    $variant = Product::factory()->create(['sku' => 'flat-variant-'.uniqid(), 'parent_id' => $root->id]);

    expect(resolve(Importer::class)->rootProductOf($variant)?->id)->toBe($root->id);
});

it('leaves a product that has no parent to be matched by its handle', function () {
    $simple = Product::factory()->create(['sku' => 'standalone-'.uniqid()]);

    expect(resolve(Importer::class)->rootProductOf($simple))->toBeNull()
        ->and(resolve(Importer::class)->rootProductOf(null))->toBeNull();
});

it('removes common values from an existing variant payload before it is saved', function () {
    $family = AttributeFamily::factory()->create();
    $color = Attribute::factory()->create(['type' => 'select']);

    $structure = VariantStructure::create([
        'attribute_family_id' => $family->id,
        'code'                => 'structure-'.uniqid(),
        'name'                => 'Structure',
        'levels'              => 1,
    ]);

    VariantStructureAxis::create([
        'variant_structure_id' => $structure->id,
        'attribute_id'         => $color->id,
        'level'                => 'level_1',
        'position'             => 1,
    ]);

    $root = Product::factory()->configurable()->create([
        'attribute_family_id'  => $family->id,
        'variant_structure_id' => $structure->id,
    ]);
    $variant = Product::factory()->create([
        'parent_id' => $root->id,
        'type'      => ProductType::Simple->value,
    ]);

    $importer = resolve(Importer::class)->setLogger(new NullLogger);

    $filter = Closure::bind(
        fn (array $payload): array => $this->filterVariantPayloadsByOwnership($payload),
        $importer,
        Importer::class,
    );

    $filtered = $filter([
        $variant->id => [
            'sku'    => $variant->sku,
            'values' => [
                'common' => [
                    $color->code  => 'red',
                    'weight'      => ['value' => '0.29', 'unit' => 'KILOGRAM'],
                    'image'       => 'product/example/image.webp',
                ],
            ],
        ],
    ]);

    expect($filtered[$variant->id]['values']['common'])->toBe([
        $color->code => 'red',
    ]);
});
