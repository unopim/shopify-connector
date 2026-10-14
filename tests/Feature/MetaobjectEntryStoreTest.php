<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Shopify\Repositories\ShopifyMetaobjectDefinitionRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectEntryRepository;

beforeEach(function () {
    Storage::fake('public');

    $this->loginAsAdmin();

    $this->definition = app(ShopifyMetaobjectDefinitionRepository::class)->create([
        'name'   => 'Brand',
        'code'   => 'brand_'.Str::lower(Str::random(6)),
        'fields' => [
            ['key' => 'brand_name', 'name' => 'Brand name', 'type' => 'single_line_text_field'],
            ['key' => 'logo', 'name' => 'Logo', 'type' => 'file_reference', 'content_type' => 'IMAGE'],
            ['key' => 'manual', 'name' => 'Manual', 'type' => 'file_reference', 'content_type' => 'FILE'],
        ],
    ]);

    $this->entryRepository = app(ShopifyMetaobjectEntryRepository::class);
});

it('rejects an svg upload for an image field', function () {
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $this->post(route('shopify.metaobject.entry.store'), [
        'type'  => $this->definition->code,
        'code'  => 'dell',
        'files' => ['logo' => $svg],
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['files.logo']);

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('rejects an html upload for a generic file field', function () {
    $html = UploadedFile::fake()->createWithContent('manual.html', '<html><body><script>alert(1)</script></body></html>');

    $this->post(route('shopify.metaobject.entry.store'), [
        'type'  => $this->definition->code,
        'code'  => 'dell',
        'files' => ['manual' => $html],
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['files.manual']);

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('rejects a file uploaded for a field that does not accept files', function () {
    $this->post(route('shopify.metaobject.entry.store'), [
        'type'  => $this->definition->code,
        'code'  => 'dell',
        'files' => ['brand_name' => UploadedFile::fake()->image('logo.png')],
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['files.brand_name']);
});

it('stores a png image for an image field', function () {
    $this->post(route('shopify.metaobject.entry.store'), [
        'type'  => $this->definition->code,
        'code'  => 'dell',
        'files' => ['logo' => UploadedFile::fake()->image('logo.png')],
    ], ['Accept' => 'application/json'])
        ->assertOk();

    $entry = $this->entryRepository->findOneWhere(['type' => $this->definition->code, 'code' => 'dell']);

    expect($entry->values['logo'])->toEndWith('.png');

    Storage::disk('public')->assertExists($entry->values['logo']);
});

it('stores a pdf for a generic file field', function () {
    $pdf = UploadedFile::fake()->createWithContent('manual.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

    $this->post(route('shopify.metaobject.entry.store'), [
        'type'  => $this->definition->code,
        'code'  => 'dell',
        'files' => ['manual' => $pdf],
    ], ['Accept' => 'application/json'])
        ->assertOk();

    $entry = $this->entryRepository->findOneWhere(['type' => $this->definition->code, 'code' => 'dell']);

    Storage::disk('public')->assertExists($entry->values['manual']);
});

it('refuses to create an entry whose code already exists', function () {
    $existing = $this->entryRepository->create([
        'type'   => $this->definition->code,
        'code'   => 'dell',
        'values' => ['brand_name' => 'Dell'],
    ]);

    $this->postJson(route('shopify.metaobject.entry.store'), [
        'type'   => $this->definition->code,
        'code'   => 'dell',
        'values' => json_encode(['brand_name' => 'Overwritten']),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code' => trans('shopify::app.shopify.metaobject.entry-code-taken')]);

    expect($existing->fresh()->values)->toBe(['brand_name' => 'Dell']);
});

it('refuses to rename an entry to a code used by another entry', function () {
    $this->entryRepository->create(['type' => $this->definition->code, 'code' => 'dell', 'values' => []]);
    $hp = $this->entryRepository->create(['type' => $this->definition->code, 'code' => 'hp', 'values' => []]);

    $this->postJson(route('shopify.metaobject.entry.store'), [
        'id'   => $hp->id,
        'type' => $this->definition->code,
        'code' => 'dell',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    expect($hp->fresh()->code)->toBe('hp');
});

it('updates an existing entry that keeps its own code', function () {
    $entry = $this->entryRepository->create(['type' => $this->definition->code, 'code' => 'dell', 'values' => ['brand_name' => 'Dell']]);

    $this->postJson(route('shopify.metaobject.entry.store'), [
        'id'     => $entry->id,
        'type'   => $this->definition->code,
        'code'   => 'dell',
        'values' => json_encode(['brand_name' => 'Dell Inc']),
    ])->assertOk();

    expect($entry->fresh()->values)->toBe(['brand_name' => 'Dell Inc']);
});

it('refuses to edit an entry through a different metaobject type', function () {
    $entry = $this->entryRepository->create(['type' => $this->definition->code, 'code' => 'dell', 'values' => ['brand_name' => 'Dell']]);

    $otherDefinition = app(ShopifyMetaobjectDefinitionRepository::class)->create([
        'name'   => 'Other',
        'code'   => 'other_'.Str::lower(Str::random(6)),
        'fields' => [['key' => 'brand_name', 'name' => 'Brand name', 'type' => 'single_line_text_field']],
    ]);

    $this->postJson(route('shopify.metaobject.entry.store'), [
        'id'     => $entry->id,
        'type'   => $otherDefinition->code,
        'code'   => 'dell',
        'values' => json_encode(['brand_name' => 'Overwritten']),
    ])->assertNotFound();

    expect($entry->fresh()->values)->toBe(['brand_name' => 'Dell']);
});

it('returns not found when editing an entry that does not exist', function () {
    $this->postJson(route('shopify.metaobject.entry.store'), [
        'id'   => 999999999,
        'type' => $this->definition->code,
        'code' => 'dell',
    ])->assertNotFound();
});

it('reports a concurrent duplicate code as a validation error', function () {
    $repository = Mockery::mock(ShopifyMetaobjectEntryRepository::class)->makePartial();
    $repository->shouldReceive('findOneWhere')->andReturnNull();
    $repository->shouldReceive('create')->andThrow(new UniqueConstraintViolationException('pgsql', 'insert', [], new Exception('duplicate key')));

    $this->app->instance(ShopifyMetaobjectEntryRepository::class, $repository);

    $this->postJson(route('shopify.metaobject.entry.store'), [
        'type' => $this->definition->code,
        'code' => 'dell',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code' => trans('shopify::app.shopify.metaobject.entry-code-taken')]);
});

it('lists entry values as plain text for the text-rendered datagrid', function () {
    $this->entryRepository->create([
        'type'   => $this->definition->code,
        'code'   => 'tom_and_jerry',
        'values' => ['brand_name' => 'Tom & Jerry'],
    ]);

    $records = $this->getJson(route('shopify.metaobject.entry.datagrid', $this->definition->code), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()
        ->json('records');

    expect($records[0]['field_brand_name'])->toBe('Tom & Jerry');
});
