<?php

use Webkul\Product\Models\Product;
use Webkul\Shopify\Helpers\Importers\Product\Importer;

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
