<?php

use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Services\Bulk\PayloadBuilders\TranslationsBulkPayloadBuilder;
use Webkul\Shopify\Services\ProductPhaseDataService;

function makeAttribute(bool $perLocale): object
{
    return new class($perLocale)
    {
        public function __construct(public bool $value_per_locale) {}
    };
}

function makeExportMapping(array $connectorSettings): object
{
    return new class($connectorSettings)
    {
        public array $mapping;

        public function __construct(array $connectorSettings)
        {
            $this->mapping = ['shopify_connector_settings' => $connectorSettings];
        }
    };
}

function makeTranslationBuilder(
    ProductPhaseDataService $phaseData,
    ?ShopifyCredentialRepository $credentials = null,
    ?ShopifyMappingRepository $mappings = null
): TranslationsBulkPayloadBuilder {
    return new TranslationsBulkPayloadBuilder(
        $phaseData,
        $credentials ?? Mockery::mock(ShopifyCredentialRepository::class),
        $mappings ?? Mockery::mock(ShopifyMappingRepository::class),
    );
}

$storeLocaleMapping = ['en' => 'en_US', 'fr' => 'fr_FR'];
$storeLocales = [
    ['locale' => 'en', 'defaultlocale' => true],
    ['locale' => 'fr', 'defaultlocale' => false],
];

it('registers a metafield instance translation for a non-default locale', function () use ($storeLocaleMapping, $storeLocales) {
    $phaseData = Mockery::mock(ProductPhaseDataService::class);

    $phaseData->shouldReceive('getProductContext')
        ->once()
        ->andReturn([
            'parent_data' => ['sku' => 'SKU1'],
            'row_data' => ['sku' => 'SKU1'],
            'merged_fields' => ['subtitle' => 'Subtitle', 'name' => 'Title'],
            'export_mapping' => makeExportMapping(['title' => 'name']),
            'product_metafields' => [
                ['type' => 'single_line_text_field', 'listvalue' => false, 'code' => 'subtitle', 'name_space_key' => 'custom.subtitle'],
            ],
            'attributes' => ['subtitle' => makeAttribute(true), 'name' => makeAttribute(true)],
        ]);

    $phaseData->shouldReceive('getAllAttributeValues')
        ->once()
        ->andReturn(['name' => 'Le titre', 'subtitle' => 'Le sous-titre']);

    $entries = [[
        'manifest' => ['product_sku' => 'SKU1'],
        'product' => [
            'id' => 'gid://shopify/Product/111',
            'mf_custom_subtitle' => ['id' => 'gid://shopify/Metafield/999'],
        ],
    ]];

    $lines = makeTranslationBuilder($phaseData)->build(
        $entries,
        1,
        'default',
        'USD',
        $storeLocaleMapping,
        $storeLocales,
        ['mf_custom_subtitle' => 'custom.subtitle'],
    );

    $decoded = array_map(fn ($line) => json_decode($line, true), $lines);

    $metafieldLine = collect($decoded)->firstWhere('resourceId', 'gid://shopify/Metafield/999');

    expect($metafieldLine)->not->toBeNull();
    expect($metafieldLine['translations'])->toHaveCount(1);
    expect($metafieldLine['translations'][0])->toMatchArray([
        'key' => 'value',
        'value' => 'Le sous-titre',
        'locale' => 'fr',
    ]);
    expect($metafieldLine['translations'][0]['translatableContentDigest'])
        ->toBe(hash('sha256', 'Subtitle'));
});

it('also registers product-level translations alongside metafields', function () use ($storeLocaleMapping, $storeLocales) {
    $phaseData = Mockery::mock(ProductPhaseDataService::class);

    $phaseData->shouldReceive('getProductContext')->once()->andReturn([
        'parent_data' => ['sku' => 'SKU1'],
        'row_data' => ['sku' => 'SKU1'],
        'merged_fields' => ['name' => 'Title'],
        'export_mapping' => makeExportMapping(['title' => 'name']),
        'product_metafields' => [],
        'attributes' => ['name' => makeAttribute(true)],
    ]);

    $phaseData->shouldReceive('getAllAttributeValues')->once()->andReturn(['name' => 'Le titre']);

    $entries = [[
        'manifest' => ['product_sku' => 'SKU1'],
        'product' => ['id' => 'gid://shopify/Product/111'],
    ]];

    $lines = makeTranslationBuilder($phaseData)->build(
        $entries, 1, 'default', 'USD', $storeLocaleMapping, $storeLocales
    );

    $productLine = json_decode($lines[0], true);

    expect($productLine['resourceId'])->toBe('gid://shopify/Product/111');
    expect($productLine['translations'][0])->toMatchArray([
        'key' => 'title',
        'value' => 'Le titre',
        'locale' => 'fr',
    ]);
});

it('returns no lines when the store has a single locale', function () {
    $phaseData = Mockery::mock(ProductPhaseDataService::class);
    $phaseData->shouldNotReceive('getProductContext');

    $lines = makeTranslationBuilder($phaseData)->build(
        [['manifest' => ['product_sku' => 'SKU1'], 'product' => ['id' => 'gid://shopify/Product/111']]],
        1,
        'default',
        'USD',
        ['en' => 'en_US'],
        [['locale' => 'en', 'defaultlocale' => true]],
    );

    expect($lines)->toBe([]);
});

it('skips non-translatable and list metafields', function () use ($storeLocaleMapping, $storeLocales) {
    $phaseData = Mockery::mock(ProductPhaseDataService::class);

    $phaseData->shouldReceive('getProductContext')->once()->andReturn([
        'parent_data' => ['sku' => 'SKU1'],
        'row_data' => ['sku' => 'SKU1'],
        'merged_fields' => ['color' => 'Red', 'tags' => 'a,b'],
        'export_mapping' => makeExportMapping([]),
        'product_metafields' => [
            ['type' => 'number_integer', 'listvalue' => false, 'code' => 'color', 'name_space_key' => 'custom.color'],
            ['type' => 'single_line_text_field', 'listvalue' => true, 'code' => 'tags', 'name_space_key' => 'custom.tags'],
        ],
        'attributes' => ['color' => makeAttribute(true), 'tags' => makeAttribute(true)],
    ]);

    $phaseData->shouldReceive('getAllAttributeValues')->once()->andReturn(['color' => 'Rouge', 'tags' => 'x,y']);

    $entries = [[
        'manifest' => ['product_sku' => 'SKU1'],
        'product' => [
            'id' => 'gid://shopify/Product/111',
            'mf_custom_color' => ['id' => 'gid://shopify/Metafield/1'],
            'mf_custom_tags' => ['id' => 'gid://shopify/Metafield/2'],
        ],
    ]];

    $lines = makeTranslationBuilder($phaseData)->build(
        $entries,
        1,
        'default',
        'USD',
        $storeLocaleMapping,
        $storeLocales,
        ['mf_custom_color' => 'custom.color', 'mf_custom_tags' => 'custom.tags'],
    );

    expect($lines)->toBe([]);
});

it('resolves a recreated product GID from the mapping when absent from the result', function () use ($storeLocaleMapping, $storeLocales) {
    $phaseData = Mockery::mock(ProductPhaseDataService::class);

    $phaseData->shouldReceive('getProductContext')->once()->andReturn([
        'parent_data' => ['sku' => 'SKU1'],
        'row_data' => ['sku' => 'SKU1'],
        'merged_fields' => ['name' => 'Title'],
        'export_mapping' => makeExportMapping(['title' => 'name']),
        'product_metafields' => [],
        'attributes' => ['name' => makeAttribute(true)],
    ]);

    $phaseData->shouldReceive('getAllAttributeValues')->once()->andReturn(['name' => 'Le titre']);

    $credential = new class
    {
        public string $shopUrl = 'https://demo.myshopify.com';
    };

    $credentials = Mockery::mock(ShopifyCredentialRepository::class);
    $credentials->shouldReceive('find')->with(1)->andReturn($credential);

    $mappingRow = new class
    {
        public string $entityType = 'product';

        public string $apiUrl = 'https://demo.myshopify.com';

        public string $code = 'SKU1';

        public string $relatedId = 'gid://shopify/Product/222';

        public ?string $externalId = null;
    };

    $mappings = Mockery::mock(ShopifyMappingRepository::class);
    $mappings->shouldReceive('findWhereIn')->with('code', ['SKU1'])->andReturn(collect([$mappingRow]));

    $entries = [[
        'manifest' => ['product_sku' => 'SKU1'],
        'product' => [],
    ]];

    $lines = makeTranslationBuilder($phaseData, $credentials, $mappings)->build(
        $entries, 1, 'default', 'USD', $storeLocaleMapping, $storeLocales
    );

    $productLine = json_decode($lines[0], true);

    expect($productLine['resourceId'])->toBe('gid://shopify/Product/222');
    expect($productLine['translations'][0]['value'])->toBe('Le titre');
});
