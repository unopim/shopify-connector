<?php

namespace Packages\Webkul\Shopify\tests\Feature;

use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Core\Repositories\LocaleRepository;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\Shopify\Helpers\Importers\Metafield\Importer;
use Webkul\Shopify\Models\ShopifyMetaFieldsConfig;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectAttributeRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectDefinitionRepository;

it('counts metafield definition as created on first import and updated on re-import', function () {
    $metafieldRepo = resolve(ShopifyMetaFieldRepository::class);

    // Clean up any existing test records
    ShopifyMetaFieldsConfig::where('name_space_key', 'custom.test_summary_key')->delete();

    $importer = new Importer(
        resolve(JobTrackBatchRepository::class),
        resolve(AttributeRepository::class),
        resolve(LocaleRepository::class),
        resolve(ShopifyCredentialRepository::class),
        $metafieldRepo,
        resolve(ShopifyMetaobjectDefinitionRepository::class),
        resolve(ShopifyMetaobjectAttributeRepository::class),
    );

    $metafieldDefinition = [
        'ownerType'       => 'PRODUCT',
        'type'            => 'single_line_text_field',
        'name_space_key'  => 'custom.test_summary_key',
        'code'            => 'test_summary_key',
        'attribute'       => 'Test Summary Key',
        'pin'             => true,
        'listvalue'       => false,
        'ContentTypeName' => 'single_line_text_field',
        'apiUrl'          => json_encode(['https://dev-faizan.myshopify.com' => 'gid://shopify/MetafieldDefinition/99999999']),
    ];

    // 1. First import: definition does not exist in DB -> should count as created
    $importer->persistMetafieldDefinition($metafieldDefinition);
    expect($importer->getCreatedItemsCount())->toBe(1);
    expect($importer->getUpdatedItemsCount())->toBe(0);

    // Verify it was saved to DB
    $saved = ShopifyMetaFieldsConfig::where('name_space_key', 'custom.test_summary_key')->first();
    expect($saved)->not->toBeNull();

    // 2. Second import: definition already exists in DB -> should count as updated
    $importer->persistMetafieldDefinition($metafieldDefinition);
    expect($importer->getCreatedItemsCount())->toBe(1);
    expect($importer->getUpdatedItemsCount())->toBe(1);

    // Clean up
    ShopifyMetaFieldsConfig::where('name_space_key', 'custom.test_summary_key')->delete();
});
