<?php

use Webkul\Shopify\Models\ShopifyMetaFieldsConfig;

use function Pest\Laravel\post;

it('stores an id metafield with regex and character-limit validations', function () {
    $this->loginAsAdmin();

    post(route('shopify.metafield.store'), [
        'ownerType' => 'PRODUCT',
        'code' => 'serial_no',
        'type' => 'id',
        'name_space_key' => 'custom.serial_no',
        'attribute' => 'Serial No',
        'pin' => '0',
        'regex' => '^[a-zA-Z0-9]+$',
        'minvalue' => '3',
        'maxvalue' => '20',
    ])->assertStatus(200);

    $record = ShopifyMetaFieldsConfig::where('code', 'serial_no')->where('ownerType', 'PRODUCT')->first();

    expect($record)->not->toBeNull()
        ->and($record->type)->toBe('id')
        ->and(json_decode($record->validations, true))->toMatchArray([
            'regex' => '^[a-zA-Z0-9]+$',
            'min' => '3',
            'max' => '20',
        ]);
});

it('updates an id metafield regex from numeric to alphanumeric', function () {
    $this->loginAsAdmin();

    $metafield = ShopifyMetaFieldsConfig::factory()->create([
        'ownerType' => 'PRODUCT',
        'code' => 'serial_no',
        'type' => 'id',
        'name_space_key' => 'custom.serial_no',
        'pin' => '0',
        'attribute' => 'Serial No',
        'validations' => json_encode(['regex' => '^[0-9]+$']),
    ]);

    $this->put(route('shopify.metafield.update', ['id' => $metafield->id]), [
        'ownerType' => 'PRODUCT',
        'code' => 'serial_no',
        'type' => 'id',
        'name_space_key' => 'custom.serial_no',
        'attribute' => 'Serial No',
        'pin' => '0',
        'regex' => '^[a-zA-Z0-9]+$',
    ]);

    expect(json_decode(ShopifyMetaFieldsConfig::find($metafield->id)->validations, true))
        ->toMatchArray(['regex' => '^[a-zA-Z0-9]+$']);
});
