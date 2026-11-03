<?php

use Psr\Log\NullLogger;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Jobs\Export\File\FlatItemBuffer;
use Webkul\DataTransfer\Models\JobInstancesProxy;
use Webkul\DataTransfer\Models\JobTrack;
use Webkul\DataTransfer\Models\JobTrackBatch;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\Shopify\Helpers\Exporters\MetaField\Exporter;
use Webkul\Shopify\Helpers\ShoifyMetaFieldType;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;
use Webkul\Shopify\Models\ShopifyMetaFieldsConfig;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectMappingRepository;

/**
 * Setup a metafield export run for testing.
 *
 * @return array{track: object, batch: object}
 */
function metafieldExportRun(array $rows): array
{
    $jobInstance = JobInstancesProxy::create([
        'code'        => 'metafield-test-'.uniqid(),
        'entity_type' => 'shopifyMetafields',
        'type'        => 'export',
        'action'      => 'export',
        'filters'     => ['credentials' => 1],
    ]);

    $track = JobTrack::factory()->export()->create([
        'state'            => ExportHelper::STATE_PROCESSING,
        'job_instances_id' => $jobInstance->id,
        'user_id'          => auth()->guard('admin')->id(),
    ]);

    $batch = JobTrackBatch::factory()->create([
        'job_track_id' => $track->id,
        'state'        => ExportHelper::STATE_VALIDATED,
        'data'         => $rows,
    ]);

    return compact('track', 'batch');
}

beforeEach(fn () => test()->loginAsAdmin());

it('falls back to unpinned creation when shopify returns PINNED_LIMIT_REACHED', function () {
    $metafield = ShopifyMetaFieldsConfig::factory()->create([
        'code'            => 'brand_tag_'.uniqid(),
        'attribute'       => 'Brand Tag',
        'attributeLabel'  => 'Brand Tag',
        'type'            => 'single_line_text_field',
        'ContentTypeName' => 'single_line_text_field',
        'ownerType'       => 'PRODUCT',
        'ownerTypeName'   => 'PRODUCT',
        'name_space'      => 'custom',
        'name_space_key'  => 'custom.brand_tag',
        'description'     => '',
        'pin'             => 1,
        'storefronts'     => 1,
    ]);

    ['track' => $track, 'batch' => $batch] = metafieldExportRun([$metafield->toArray()]);

    $exporter = Mockery::mock(Exporter::class, [
        app(JobTrackBatchRepository::class),
        app(FlatItemBuffer::class),
        app(ShopifyCredentialRepository::class),
        app(ShoifyMetaFieldType::class),
        app(ShopifyMetaFieldRepository::class),
        app(ShopifyMetaobjectMappingRepository::class),
    ])->makePartial()->shouldAllowMockingProtectedMethods();

    $exporter->setExport($track)->setLogger(new NullLogger);

    $credential = ShopifyCredentialsConfig::factory()->make([
        'shopUrl' => 'test-store.myshopify.com',
    ]);

    (function () use ($credential): void {
        $this->credential = $credential;
        $this->credentialArray = [
            'shopUrl'     => 'test-store.myshopify.com',
            'apiVersion'  => '2025-01',
            'accessToken' => 'fake-token',
        ];
        $this->shopifyDefaultLocale = 'en_US';
        $this->currentConstraintsMap = [];
    })->call($exporter);

    $exporter->shouldReceive('requestGraphQlApiActionPool')->once()->andReturn([
        (string) $metafield->id => [
            'code' => 200,
            'body' => [
                'data' => [
                    'metafieldDefinitionCreate' => [
                        'createdDefinition' => null,
                        'userErrors'        => [
                            [
                                'code'    => 'PINNED_LIMIT_REACHED',
                                'message' => 'Limit of 50 pinned definitions.',
                                'field'   => ['definition'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $exporter->shouldReceive('requestGraphQlApiAction')
        ->with('metafieldDefinitionCreate', Mockery::type('array'), Mockery::on(function ($params) {
            return ($params['input']['pin'] ?? null) === false;
        }))
        ->once()
        ->andReturn([
            'code' => 200,
            'body' => [
                'data' => [
                    'metafieldDefinitionCreate' => [
                        'createdDefinition' => ['id' => 'gid://shopify/MetafieldDefinition/999'],
                        'userErrors'        => [],
                    ],
                ],
            ],
        ]);

    $exporter->prepareMetafieldShopify($batch, null);

    $metafield->refresh();

    expect($metafield->apiUrl)->toContain('gid://shopify/MetafieldDefinition/999');

    $exporter->updateBatchState($batch->id, ExportHelper::STATE_PROCESSED);

    expect($batch->refresh()->summary)
        ->toMatchArray(['processed' => 1, 'created' => 1, 'skipped' => 0]);
});

it('falls back to unpinned update when shopify returns PINNED_LIMIT_REACHED on update', function () {
    $metafield = ShopifyMetaFieldsConfig::factory()->create([
        'code'            => 'brand_tag_update_'.uniqid(),
        'attribute'       => 'Brand Tag Update',
        'attributeLabel'  => 'Brand Tag Update',
        'type'            => 'single_line_text_field',
        'ContentTypeName' => 'single_line_text_field',
        'ownerType'       => 'PRODUCT',
        'ownerTypeName'   => 'PRODUCT',
        'name_space'      => 'custom',
        'name_space_key'  => 'custom.brand_tag_update',
        'description'     => '',
        'pin'             => 1,
        'storefronts'     => 1,
        'apiUrl'          => json_encode(['test-store.myshopify.com' => 'gid://shopify/MetafieldDefinition/888']),
    ]);

    ['track' => $track, 'batch' => $batch] = metafieldExportRun([$metafield->toArray()]);

    $exporter = Mockery::mock(Exporter::class, [
        app(JobTrackBatchRepository::class),
        app(FlatItemBuffer::class),
        app(ShopifyCredentialRepository::class),
        app(ShoifyMetaFieldType::class),
        app(ShopifyMetaFieldRepository::class),
        app(ShopifyMetaobjectMappingRepository::class),
    ])->makePartial()->shouldAllowMockingProtectedMethods();

    $exporter->setExport($track)->setLogger(new NullLogger);

    $credential = ShopifyCredentialsConfig::factory()->make([
        'shopUrl' => 'test-store.myshopify.com',
    ]);

    (function () use ($credential): void {
        $this->credential = $credential;
        $this->credentialArray = [
            'shopUrl'     => 'test-store.myshopify.com',
            'apiVersion'  => '2025-01',
            'accessToken' => 'fake-token',
        ];
        $this->shopifyDefaultLocale = 'en_US';
        $this->currentConstraintsMap = [];
    })->call($exporter);

    $exporter->shouldReceive('requestGraphQlApiActionPool')->once()->andReturn([
        (string) $metafield->id => [
            'code' => 200,
            'body' => [
                'data' => [
                    'metafieldDefinitionUpdate' => [
                        'updatedDefinition' => null,
                        'userErrors'        => [
                            [
                                'code'    => 'PINNED_LIMIT_REACHED',
                                'message' => 'Limit of 50 pinned definitions.',
                                'field'   => ['definition'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $exporter->shouldReceive('requestGraphQlApiAction')
        ->with('metafieldDefinitionUpdate', Mockery::type('array'), Mockery::on(function ($params) {
            return ($params['input']['pin'] ?? null) === false;
        }))
        ->once()
        ->andReturn([
            'code' => 200,
            'body' => [
                'data' => [
                    'metafieldDefinitionUpdate' => [
                        'updatedDefinition' => ['id' => 'gid://shopify/MetafieldDefinition/888'],
                        'userErrors'        => [],
                    ],
                ],
            ],
        ]);

    $exporter->prepareMetafieldShopify($batch, null);

    $exporter->updateBatchState($batch->id, ExportHelper::STATE_PROCESSED);

    expect($batch->refresh()->summary)
        ->toMatchArray(['processed' => 1, 'created' => 1, 'skipped' => 0]);
});

it('processes a batch in pool and handles pinned limit fallback concurrently', function () {
    $metafield1 = ShopifyMetaFieldsConfig::factory()->create([
        'code'            => 'batch_meta_1_'.uniqid(),
        'attribute'       => 'Batch Meta 1',
        'attributeLabel'  => 'Batch Meta 1',
        'type'            => 'single_line_text_field',
        'ContentTypeName' => 'single_line_text_field',
        'ownerType'       => 'PRODUCT',
        'ownerTypeName'   => 'PRODUCT',
        'name_space'      => 'custom',
        'name_space_key'  => 'custom.batch_meta_1',
        'description'     => '',
        'pin'             => 1,
        'storefronts'     => 1,
    ]);

    $metafield2 = ShopifyMetaFieldsConfig::factory()->create([
        'code'            => 'batch_meta_2_'.uniqid(),
        'attribute'       => 'Batch Meta 2',
        'attributeLabel'  => 'Batch Meta 2',
        'type'            => 'single_line_text_field',
        'ContentTypeName' => 'single_line_text_field',
        'ownerType'       => 'PRODUCT',
        'ownerTypeName'   => 'PRODUCT',
        'name_space'      => 'custom',
        'name_space_key'  => 'custom.batch_meta_2',
        'description'     => '',
        'pin'             => 1,
        'storefronts'     => 1,
    ]);

    ['track' => $track, 'batch' => $batch] = metafieldExportRun([$metafield1->toArray(), $metafield2->toArray()]);

    $exporter = Mockery::mock(Exporter::class, [
        app(JobTrackBatchRepository::class),
        app(FlatItemBuffer::class),
        app(ShopifyCredentialRepository::class),
        app(ShoifyMetaFieldType::class),
        app(ShopifyMetaFieldRepository::class),
        app(ShopifyMetaobjectMappingRepository::class),
    ])->makePartial()->shouldAllowMockingProtectedMethods();

    $exporter->setExport($track)->setLogger(new NullLogger);

    $credential = ShopifyCredentialsConfig::factory()->make([
        'shopUrl' => 'test-store.myshopify.com',
    ]);

    (function () use ($credential): void {
        $this->credential = $credential;
        $this->credentialArray = [
            'shopUrl'     => 'test-store.myshopify.com',
            'apiVersion'  => '2025-01',
            'accessToken' => 'fake-token',
        ];
        $this->shopifyDefaultLocale = 'en_US';
        $this->currentConstraintsMap = [];
    })->call($exporter);

    $exporter->shouldReceive('requestGraphQlApiActionPool')->once()->andReturn([
        (string) $metafield1->id => [
            'code' => 200,
            'body' => [
                'data' => [
                    'metafieldDefinitionCreate' => [
                        'createdDefinition' => ['id' => 'gid://shopify/MetafieldDefinition/101'],
                        'userErrors'        => [],
                    ],
                ],
            ],
        ],
        (string) $metafield2->id => [
            'code' => 200,
            'body' => [
                'data' => [
                    'metafieldDefinitionCreate' => [
                        'createdDefinition' => null,
                        'userErrors'        => [
                            [
                                'code'    => 'PINNED_LIMIT_REACHED',
                                'message' => 'Limit of 50 pinned definitions.',
                                'field'   => ['definition'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $exporter->shouldReceive('requestGraphQlApiAction')
        ->with('metafieldDefinitionCreate', Mockery::type('array'), Mockery::on(function ($params) {
            return ($params['input']['pin'] ?? null) === false;
        }))
        ->once()
        ->andReturn([
            'code' => 200,
            'body' => [
                'data' => [
                    'metafieldDefinitionCreate' => [
                        'createdDefinition' => ['id' => 'gid://shopify/MetafieldDefinition/102'],
                        'userErrors'        => [],
                    ],
                ],
            ],
        ]);

    $exporter->prepareMetafieldShopify($batch, null);

    $metafield1->refresh();
    $metafield2->refresh();

    expect($metafield1->apiUrl)->toContain('gid://shopify/MetafieldDefinition/101');
    expect($metafield2->apiUrl)->toContain('gid://shopify/MetafieldDefinition/102');

    $exporter->updateBatchState($batch->id, ExportHelper::STATE_PROCESSED);

    expect($batch->refresh()->summary)
        ->toMatchArray(['processed' => 2, 'created' => 2, 'skipped' => 0]);
});

it('processes a batch in pool and adopts TAKEN definitions concurrently', function () {
    $metafield = ShopifyMetaFieldsConfig::factory()->create([
        'code'            => 'taken_meta_'.uniqid(),
        'attribute'       => 'Taken Meta',
        'attributeLabel'  => 'Taken Meta',
        'type'            => 'single_line_text_field',
        'ContentTypeName' => 'single_line_text_field',
        'ownerType'       => 'PRODUCT',
        'ownerTypeName'   => 'PRODUCT',
        'name_space'      => 'custom',
        'name_space_key'  => 'custom.taken_meta',
        'description'     => '',
        'pin'             => 1,
        'storefronts'     => 1,
    ]);

    ['track' => $track, 'batch' => $batch] = metafieldExportRun([$metafield->toArray()]);

    $exporter = Mockery::mock(Exporter::class, [
        app(JobTrackBatchRepository::class),
        app(FlatItemBuffer::class),
        app(ShopifyCredentialRepository::class),
        app(ShoifyMetaFieldType::class),
        app(ShopifyMetaFieldRepository::class),
        app(ShopifyMetaobjectMappingRepository::class),
    ])->makePartial()->shouldAllowMockingProtectedMethods();

    $exporter->setExport($track)->setLogger(new NullLogger);

    $credential = ShopifyCredentialsConfig::factory()->make([
        'shopUrl' => 'test-store.myshopify.com',
    ]);

    (function () use ($credential): void {
        $this->credential = $credential;
        $this->credentialArray = [
            'shopUrl'     => 'test-store.myshopify.com',
            'apiVersion'  => '2025-01',
            'accessToken' => 'fake-token',
        ];
        $this->shopifyDefaultLocale = 'en_US';
        $this->currentConstraintsMap = [];
    })->call($exporter);

    $exporter->shouldReceive('requestGraphQlApiActionPool')->twice()->andReturn(
        [
            (string) $metafield->id => [
                'code' => 200,
                'body' => [
                    'data' => [
                        'metafieldDefinitionCreate' => [
                            'createdDefinition' => null,
                            'userErrors'        => [
                                [
                                    'code'    => 'TAKEN',
                                    'message' => 'Name has already been taken.',
                                    'field'   => ['name'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        [
            (string) $metafield->id => [
                'code' => 200,
                'body' => [
                    'data' => [
                        'metafieldDefinitionUpdate' => [
                            'updatedDefinition' => ['id' => 'gid://shopify/MetafieldDefinition/777'],
                            'userErrors'        => [],
                        ],
                    ],
                ],
            ],
        ]
    );

    $exporter->prepareMetafieldShopify($batch, null);

    $metafield->refresh();

    expect($metafield->apiUrl)->toContain('gid://shopify/MetafieldDefinition/777');

    $exporter->updateBatchState($batch->id, ExportHelper::STATE_PROCESSED);

    expect($batch->refresh()->summary)
        ->toMatchArray(['processed' => 1, 'created' => 1, 'skipped' => 0]);
});
