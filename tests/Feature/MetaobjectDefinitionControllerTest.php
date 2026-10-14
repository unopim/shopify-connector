<?php

use Illuminate\Support\Str;
use Webkul\Attribute\Models\Attribute;
use Webkul\Shopify\Repositories\ShopifyMetaobjectAttributeRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectDefinitionRepository;

beforeEach(function () {
    $this->definitionRepository = app(ShopifyMetaobjectDefinitionRepository::class);

    $this->makeDefinition = function (bool $withBoundAttribute): array {
        $definition = $this->definitionRepository->create([
            'name'   => 'Brand',
            'code'   => 'brand_'.Str::lower(Str::random(6)),
            'fields' => [['key' => 'brand_name', 'name' => 'Brand name', 'type' => 'single_line_text_field']],
        ]);

        $attribute = null;

        if ($withBoundAttribute) {
            $attribute = Attribute::factory()->create();

            app(ShopifyMetaobjectAttributeRepository::class)->saveBinding($attribute->id, $definition->id);
        }

        return [$definition, $attribute];
    };
});

it('refuses to delete a definition bound to attributes without attribute delete permission', function () {
    [$definition, $attribute] = ($this->makeDefinition)(true);

    $this->loginWithPermissions(permissions: ['shopify', 'shopify.metaobjects', 'shopify.metaobjects.delete']);

    $this->deleteJson(route('shopify.metaobject.destroy', $definition->id))
        ->assertForbidden()
        ->assertJson(['message' => trans('shopify::app.shopify.metaobject.attr-delete-denied')]);

    $this->assertModelExists($attribute);
    expect($this->definitionRepository->find($definition->id))->not->toBeNull();
});

it('deletes a bound definition and its attributes with attribute delete permission', function () {
    [$definition, $attribute] = ($this->makeDefinition)(true);

    $this->loginWithPermissions(permissions: ['shopify', 'shopify.metaobjects', 'shopify.metaobjects.delete', 'catalog', 'catalog.attributes', 'catalog.attributes.delete']);

    $this->deleteJson(route('shopify.metaobject.destroy', $definition->id))->assertOk();

    $this->assertModelMissing($attribute);
    expect($this->definitionRepository->find($definition->id))->toBeNull();
});

it('deletes an unbound definition without attribute delete permission', function () {
    [$definition] = ($this->makeDefinition)(false);

    $this->loginWithPermissions(permissions: ['shopify', 'shopify.metaobjects', 'shopify.metaobjects.delete']);

    $this->deleteJson(route('shopify.metaobject.destroy', $definition->id))->assertOk();

    expect($this->definitionRepository->find($definition->id))->toBeNull();
});

it('mass delete removes nothing when any selected definition is bound and attribute delete permission is missing', function () {
    [$unbound] = ($this->makeDefinition)(false);
    [$bound, $attribute] = ($this->makeDefinition)(true);

    $this->loginWithPermissions(permissions: ['shopify', 'shopify.metaobjects', 'shopify.metaobjects.mass_delete']);

    $this->postJson(route('shopify.metaobject.mass_delete'), ['indices' => [$unbound->id, $bound->id]])
        ->assertForbidden();

    expect($this->definitionRepository->find($unbound->id))->not->toBeNull()
        ->and($this->definitionRepository->find($bound->id))->not->toBeNull();

    $this->assertModelExists($attribute);
});

it('flashes a success message when a definition is created from the create page', function () {
    $this->loginAsAdmin();

    $this->postJson(route('shopify.metaobject.store'), [
        'name'   => 'Brand '.Str::random(4),
        'fields' => [['name' => 'Brand name', 'type' => 'single_line_text_field']],
    ])->assertOk();

    expect(session('success'))->toBe(trans('shopify::app.shopify.metaobject.saved'));
});

it('does not flash a success message when a definition is created from a nested builder', function () {
    $this->loginAsAdmin();

    $this->postJson(route('shopify.metaobject.store'), [
        'name'   => 'Brand '.Str::random(4),
        'fields' => [['name' => 'Brand name', 'type' => 'single_line_text_field']],
        'nested' => true,
    ])->assertOk();

    expect(session('success'))->toBeNull();
});
