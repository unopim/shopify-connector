<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shopify\Helpers\Exporters\Metaobject\Exporter;
use Webkul\Shopify\Helpers\Importers\Metaobject\Importer;
use Webkul\Shopify\Helpers\MetaobjectFieldType;
use Webkul\Shopify\Repositories\ShopifyMetaobjectAttributeRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectEntryMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectEntryRepository;

/**
 * Invoke a protected method for pure-transform assertions.
 */
function callProtected(object $object, string $method, array $args = []): mixed
{
    $reflection = (new ReflectionClass($object))->getMethod($method);
    $reflection->setAccessible(true);

    return $reflection->invoke($object, ...$args);
}

it('creates then updates a metaobject entry by code without duplicating', function () {
    $repository = app(ShopifyMetaobjectEntryRepository::class);

    $repository->updateOrCreateByCode('brand', 'dell', ['brand_name' => 'Dell']);
    $repository->updateOrCreateByCode('brand', 'dell', ['brand_name' => 'Dell Inc']);

    $entries = $repository->findWhere(['type' => 'brand', 'code' => 'dell']);

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->values['brand_name'])->toBe('Dell Inc');
});

it('saves and reads an attribute binding with its list flag', function () {
    $repository = app(ShopifyMetaobjectAttributeRepository::class);

    $repository->saveBinding(4101, 7, true);

    expect($repository->bindingFor(4101)->definition_id)->toBe(7)
        ->and((bool) $repository->bindingFor(4101)->is_list)->toBeTrue();

    $repository->saveBinding(4101, 8, false);

    expect($repository->bindingFor(4101)->definition_id)->toBe(8)
        ->and((bool) $repository->bindingFor(4101)->is_list)->toBeFalse();
});

it('resolves a gid to the live entry and skips an orphan mapping', function () {
    $entryRepository = app(ShopifyMetaobjectEntryRepository::class);
    $mappingRepository = app(ShopifyMetaobjectEntryMappingRepository::class);

    $entry = $entryRepository->updateOrCreateByCode('brand', 'oppo', []);
    $shopUrl = 'https://test.myshopify.com';
    $gid = 'gid://shopify/Metaobject/555';

    DB::table('wk_shopify_metaobject_entry_mappings')->insert([
        'entry_id'   => 999999,
        'api_url'    => $shopUrl,
        'gid'        => $gid,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $mappingRepository->putGid((int) $entry->id, $shopUrl, $gid);

    expect($mappingRepository->entryIdForGid($gid, $shopUrl))->toBe((int) $entry->id);
});

it('deletes entry mappings for the given entries', function () {
    $mappingRepository = app(ShopifyMetaobjectEntryMappingRepository::class);
    $shopUrl = 'https://test.myshopify.com';

    $mappingRepository->putGid(7001, $shopUrl, 'gid://shopify/Metaobject/1');
    $mappingRepository->putGid(7002, $shopUrl, 'gid://shopify/Metaobject/2');

    $mappingRepository->deleteForEntries([7001]);

    expect($mappingRepository->gidFor(7001, $shopUrl))->toBeNull()
        ->and($mappingRepository->gidFor(7002, $shopUrl))->toBe('gid://shopify/Metaobject/2');
});

it('exposes the supported metaobject field types', function () {
    expect(MetaobjectFieldType::supportedTypes())->toHaveKeys([
        'single_line_text_field', 'file_reference', 'metaobject_reference', 'email', 'image', 'date_time',
    ]);
});

it('round-trips rich text between export JSON and import plain text', function () {
    $plain = "First line\n\nSecond line";

    $rich = callProtected(app(Exporter::class), 'richText', [$plain]);

    expect($rich)->toContain('"type":"root"')
        ->and($rich)->toContain('First line');

    expect(callProtected(app(Importer::class), 'richTextToPlain', [$rich]))->toBe($plain);
});
