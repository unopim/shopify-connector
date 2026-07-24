<?php

use Webkul\Shopify\Models\ShopifyMetaFieldsConfig;
use Webkul\Shopify\Services\Taxonomy\ShopifyTaxonomyLoader;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

const TAXONOMY_ROOT = 'gid://shopify/TaxonomyCategory/ap';

const TAXONOMY_LEAF = 'gid://shopify/TaxonomyCategory/ap-1';

it('loads every taxonomy entry from the bundled file', function () {
    $entries = app(ShopifyTaxonomyLoader::class)->all();

    expect($entries)->not->toBeEmpty()
        ->and($entries[0])->toHaveKeys(['id', 'path', 'depth'])
        ->and($entries[0]['id'])->toStartWith('gid://shopify/TaxonomyCategory/');
});

it('lists top level taxonomy categories', function () {
    $rows = app(ShopifyTaxonomyLoader::class)->topLevel();

    expect($rows)->not->toBeEmpty()
        ->and(collect($rows)->pluck('id'))->toContain(TAXONOMY_ROOT)
        ->and(collect($rows)->firstWhere('id', TAXONOMY_ROOT)['name'])->toBe('Animals & Pet Supplies');
});

it('lists children of a taxonomy category', function () {
    $loader = app(ShopifyTaxonomyLoader::class);

    expect(collect($loader->children(TAXONOMY_ROOT))->pluck('id'))->toContain(TAXONOMY_LEAF)
        ->and($loader->children(TAXONOMY_LEAF))->toBeEmpty();
});

it('returns descendants including the node itself', function () {
    $loader = app(ShopifyTaxonomyLoader::class);

    expect($loader->descendants(TAXONOMY_LEAF))->toBe([TAXONOMY_LEAF])
        ->and($loader->descendants(TAXONOMY_ROOT))->toContain(TAXONOMY_ROOT, TAXONOMY_LEAF)
        ->and($loader->descendants('gid://shopify/TaxonomyCategory/unknown'))->toBeEmpty();
});

it('searches taxonomy paths case insensitively with a capped result set', function () {
    $hits = app(ShopifyTaxonomyLoader::class)->search('live animals');

    expect($hits)->not->toBeEmpty()
        ->and(count($hits))->toBeLessThanOrEqual(50)
        ->and(collect($hits)->pluck('id'))->toContain(TAXONOMY_LEAF);
});

it('resolves taxonomy names and ids', function () {
    $loader = app(ShopifyTaxonomyLoader::class);

    expect($loader->namesFor([TAXONOMY_LEAF]))->toBe([TAXONOMY_LEAF => 'Live Animals'])
        ->and($loader->findById(TAXONOMY_LEAF)['path'])->toBe('Animals & Pet Supplies > Live Animals')
        ->and($loader->findById('gid://shopify/TaxonomyCategory/unknown'))->toBeNull();
});

it('returns the taxonomy tree for the picker', function () {
    $this->loginAsAdmin();

    get(route('admin.shopify.get-taxonomy-tree'))
        ->assertStatus(200)
        ->assertJsonFragment(['id' => TAXONOMY_ROOT, 'name' => 'Animals & Pet Supplies', 'hasChildren' => true]);

    get(route('admin.shopify.get-taxonomy-tree', ['parent' => TAXONOMY_ROOT]))
        ->assertStatus(200)
        ->assertJsonFragment(['id' => TAXONOMY_LEAF, 'name' => 'Live Animals', 'hasChildren' => false]);
});

it('returns flat search results for the taxonomy picker', function () {
    $this->loginAsAdmin();

    get(route('admin.shopify.get-taxonomy-tree', ['query' => 'Live Animals']))
        ->assertStatus(200)
        ->assertJsonFragment([
            'id' => TAXONOMY_LEAF,
            'name' => 'Animals & Pet Supplies > Live Animals',
            'hasChildren' => false,
        ]);
});

it('returns taxonomy descendants and names for the picker', function () {
    $this->loginAsAdmin();

    get(route('admin.shopify.get-taxonomy-descendants', ['id' => TAXONOMY_LEAF]))
        ->assertStatus(200)
        ->assertJson(['ids' => [TAXONOMY_LEAF]]);

    get(route('admin.shopify.get-taxonomy-descendants'))
        ->assertStatus(200)
        ->assertJson(['ids' => []]);

    get(route('admin.shopify.get-taxonomy-names', ['ids' => [TAXONOMY_LEAF]]))
        ->assertStatus(200)
        ->assertJson(['names' => [TAXONOMY_LEAF => 'Live Animals']]);
});

it('stores a metafield definition with taxonomy categories', function () {
    $this->loginAsAdmin();

    post(route('shopify.metafield.store'), [
        'ownerType' => 'PRODUCT',
        'code' => 'taxonomy_code',
        'type' => 'single_line_text_field',
        'name_space_key' => 'custom.taxonomy_code',
        'pin' => '0',
        'attribute' => 'test_attribute',
        'taxonomy_category' => json_encode([TAXONOMY_ROOT, TAXONOMY_LEAF]),
    ])->assertStatus(200);

    expect(ShopifyMetaFieldsConfig::where('code', 'taxonomy_code')->first()->taxonomy_category)
        ->toBe([TAXONOMY_ROOT, TAXONOMY_LEAF]);
});

it('updates the taxonomy categories of a metafield definition', function () {
    $this->loginAsAdmin();

    $metaField = ShopifyMetaFieldsConfig::factory()->create([
        'ownerType' => 'PRODUCT',
        'type' => 'single_line_text_field',
        'taxonomy_category' => [TAXONOMY_ROOT],
    ]);

    put(route('shopify.metafield.update', ['id' => $metaField->id]), [
        'type' => 'single_line_text_field',
        'pin' => '0',
        'taxonomy_category' => json_encode([TAXONOMY_LEAF]),
    ])->assertRedirect(route('shopify.metafield.edit', ['id' => $metaField->id]));

    expect($metaField->refresh()->taxonomy_category)->toBe([TAXONOMY_LEAF]);
});

it('clears the taxonomy categories when none are selected', function () {
    $this->loginAsAdmin();

    $metaField = ShopifyMetaFieldsConfig::factory()->create([
        'ownerType' => 'PRODUCT',
        'type' => 'single_line_text_field',
        'taxonomy_category' => [TAXONOMY_ROOT, TAXONOMY_LEAF],
    ]);

    put(route('shopify.metafield.update', ['id' => $metaField->id]), [
        'type' => 'single_line_text_field',
        'pin' => '0',
        'taxonomy_category' => json_encode([]),
    ]);

    expect($metaField->refresh()->taxonomy_category)->toBe([]);
});
