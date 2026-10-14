<?php

use Webkul\Shopify\Models\ShopifyMetaFieldsConfig;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

it('should returns the shopify Metafield Definitions index page', function () {
    $this->loginAsAdmin();

    get(route('shopify.metafield.index'))
        ->assertStatus(200)
        ->assertSeeText(trans('shopify::app.shopify.metafield.index.title'));
});

it('should returns the shopify Metafield Definitions edit page', function () {
    $this->loginAsAdmin();

    $shopifyMetafield = ShopifyMetaFieldsConfig::factory()->create();

    get(route('shopify.metafield.edit', ['id' => $shopifyMetafield->id]))
        ->assertStatus(200);
});

it('should create the shopify Metafield Definitions with valid input', function () {
    $this->loginAsAdmin();

    $shopifyMetaField = [
        'ownerType'      => 'test_ownerType',
        'code'           => 'test_code',
        'type'           => 'test_type',
        'name_space_key' => 'test_name.space_key',
        'pin'            => '1',
        'attribute'      => 'test_attribute',
    ];

    post(route('shopify.metafield.store'), $shopifyMetaField)
        ->assertStatus(200);
});

it('should update the shopify Metafield Definitions with valid input', function () {
    $this->loginAsAdmin();
    $metaField = ShopifyMetaFieldsConfig::factory()->create([
        'ownerType'      => 'test_ownerType',
        'code'           => 'test_code',
        'type'           => 'test_type',
        'name_space_key' => 'test_name.space_key',
        'pin'            => '0',
        'attribute'      => 'test_attribute',
    ]);

    $updatedData = [
        'pin'         => '0',
        'type'        => 'test_type',
        'storefronts' => '1',
    ];

    $response = $this->put(route('shopify.metafield.update', ['id' => $metaField->id]), $updatedData);

    $response->assertRedirect(route('shopify.metafield.edit', ['id' => $metaField->id]));

    $response->assertSessionHas('success', trans('shopify::app.shopify.metafield.update-success'));
});

it('hides the raw database error when saving a new metafield definition fails', function () {
    $this->loginAsAdmin();

    ShopifyMetaFieldsConfig::creating(fn () => throw new RuntimeException('SQLSTATE[23000]: internal detail'));

    $this->postJson(route('shopify.metafield.store'), [
        'ownerType'      => 'test_ownerType',
        'code'           => 'test_code',
        'type'           => 'test_type',
        'name_space_key' => 'test_name.space_key',
        'pin'            => '1',
        'attribute'      => 'test_attribute',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['shopUrl' => trans('shopify::app.shopify.metafield.save-failed')])
        ->assertDontSee('SQLSTATE');
});

it('hides the raw database error when mass deleting metafield definitions fails', function () {
    $this->loginAsAdmin();

    $this->mock(ShopifyMetaFieldRepository::class)
        ->shouldReceive('whereIN')
        ->andThrow(new RuntimeException('SQLSTATE[23000]: internal detail'));

    $this->postJson(route('shopify.metafield.mass_delete'), ['indices' => [1]])
        ->assertJson(['message' => trans('shopify::app.shopify.metafield.mass-delete-failed')])
        ->assertDontSee('SQLSTATE');
});
