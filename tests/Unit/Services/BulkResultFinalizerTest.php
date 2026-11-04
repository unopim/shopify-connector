<?php

use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\DataTransfer\Repositories\JobTrackRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;
use Webkul\Shopify\Services\BulkResultFinalizer;
use Webkul\Shopify\Services\PhaseOrchestrator;
use Webkul\Shopify\Services\PhaseProgressTracker;

it('removes deleted variant ids before stale product recreation', function () {
    $finalizer = resolve(BulkResultFinalizer::class);
    $method = new ReflectionMethod(BulkResultFinalizer::class, 'removeStaleVariantIdentifiers');
    $method->setAccessible(true);

    $variables = [
        'input' => [
            'variants' => [
                [
                    'id'            => 'gid://shopify/ProductVariant/46498289975343',
                    'inventoryItem' => ['sku' => 'aurex-pulse-portable-speaker'],
                ],
            ],
        ],
    ];

    $errors = [[
        'code' => 'PRODUCT_VARIANT_DOES_NOT_EXIST',
    ]];
    $arguments = [&$variables, $errors];
    $method->invokeArgs($finalizer, $arguments);

    expect($variables['input']['variants'][0])->not->toHaveKey('id');
});

it('keeps variant ids when Shopify did not report a missing variant', function () {
    $finalizer = resolve(BulkResultFinalizer::class);
    $method = new ReflectionMethod(BulkResultFinalizer::class, 'removeStaleVariantIdentifiers');
    $method->setAccessible(true);

    $variables = [
        'input' => [
            'variants' => [['id' => 'gid://shopify/ProductVariant/1']],
        ],
    ];

    $errors = [['code' => 'INVALID_INPUT']];
    $arguments = [&$variables, $errors];
    $method->invokeArgs($finalizer, $arguments);

    expect($variables['input']['variants'][0]['id'])->toBe('gid://shopify/ProductVariant/1');
});

it('builds recreate variables with handle identifier and unsets files', function () {
    $finalizer = resolve(BulkResultFinalizer::class);
    $method = new ReflectionMethod(BulkResultFinalizer::class, 'buildRecreateVariables');
    $method->setAccessible(true);

    $variables = [
        'identifier' => ['id' => 'gid://shopify/Product/old-id'],
        'input'      => [
            'title'    => 'Test Stale Product',
            'files'    => [['originalSource' => 'https://example.com/test.jpg']],
            'variants' => [
                [
                    'id'            => 'gid://shopify/ProductVariant/old-var-id',
                    'inventoryItem' => ['sku' => 'TEST-SKU'],
                ],
            ],
        ],
    ];

    $manifestLine = [
        'product_sku'    => 'TEST-SKU',
        'product_handle' => 'test-stale-product',
    ];

    $userErrors = [
        ['code' => 'PRODUCT_DOES_NOT_EXIST', 'message' => 'Product does not exist'],
        ['code' => 'PRODUCT_VARIANT_DOES_NOT_EXIST', 'message' => 'Variant does not exist'],
    ];

    $result = $method->invoke($finalizer, $variables, $manifestLine, $userErrors);

    expect($result)->not->toBeNull();
    expect($result['identifier'])->toBe(['handle' => 'test-stale-product']);
    expect($result['input'])->not->toHaveKey('files');
    expect($result['input']['variants'][0])->not->toHaveKey('id');
});

it('recreates stale products concurrently via pooled requests', function () {
    $finalizer = new class(resolve(ShopifyMappingRepository::class), resolve(PhaseOrchestrator::class), resolve(JobTrackBatchRepository::class), resolve(JobTrackRepository::class), resolve(PhaseProgressTracker::class), resolve(ShopifyMetaFieldRepository::class)) extends BulkResultFinalizer
    {
        public array $capturedOperations = [];

        protected function requestGraphQlApiActionPool(array $operations, ?array $credential = []): array
        {
            $this->capturedOperations = $operations;

            $responses = [];
            foreach ($operations as $key => $op) {
                $responses[$key] = [
                    'code' => 200,
                    'body' => [
                        'data' => [
                            'productSet' => [
                                'userErrors' => [],
                                'product'    => [
                                    'id'       => 'gid://shopify/Product/new-'.$key,
                                    'handle'   => 'handle-'.$key,
                                    'media'    => ['nodes' => []],
                                    'variants' => [
                                        'nodes' => [
                                            [
                                                'id'            => 'gid://shopify/ProductVariant/new-var-'.$key,
                                                'sku'           => 'SKU-'.$key,
                                                'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/inv-'.$key, 'sku' => 'SKU-'.$key],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            }

            return $responses;
        }

        protected function applyRecreatedProduct(array $manifestLine, array $product, ?int $jobTrackId, ?string $shopUrl): void
        {
            // Swallowed for unit test
        }
    };

    $queue = [
        0 => [
            'index'        => 0,
            'sku'          => 'SKU-0',
            'manifestLine' => ['product_sku' => 'SKU-0', 'product_handle' => 'handle-0'],
            'inputLine'    => ['input' => ['title' => 'Product 0', 'variants' => []]],
            'userErrors'   => [['code' => 'PRODUCT_DOES_NOT_EXIST']],
            'cleared'      => ['SKU-0'],
        ],
        1 => [
            'index'        => 1,
            'sku'          => 'SKU-1',
            'manifestLine' => ['product_sku' => 'SKU-1', 'product_handle' => 'handle-1'],
            'inputLine'    => ['input' => ['title' => 'Product 1', 'variants' => []]],
            'userErrors'   => [['code' => 'PRODUCT_DOES_NOT_EXIST']],
            'cleared'      => ['SKU-1'],
        ],
    ];

    $method = new ReflectionMethod($finalizer, 'recreateStaleProductsPool');
    $method->setAccessible(true);

    $results = $method->invoke($finalizer, $queue, ['token' => 'dummy'], 1, 'test.myshopify.com');

    expect($finalizer->capturedOperations)->toHaveCount(2);
    expect($finalizer->capturedOperations[0]['endpoint'])->toBe('productSet');
    expect($finalizer->capturedOperations[1]['endpoint'])->toBe('productSet');
    expect($results['recreated'])->toHaveCount(2);
    expect($results['failed'])->toBeEmpty();
    expect($results['recreated'][0]['sku'])->toBe('SKU-0');
    expect($results['recreated'][1]['sku'])->toBe('SKU-1');
});

it('detects throttled responses and extracts available rate limit points', function () {
    $finalizer = resolve(BulkResultFinalizer::class);

    $isThrottled = new ReflectionMethod(BulkResultFinalizer::class, 'isThrottledResponse');
    $isThrottled->setAccessible(true);

    $extractPoints = new ReflectionMethod(BulkResultFinalizer::class, 'extractAvailablePoints');
    $extractPoints->setAccessible(true);

    expect($isThrottled->invoke($finalizer, ['code' => 429, 'body' => []]))->toBeTrue();
    expect($isThrottled->invoke($finalizer, [
        'code' => 200,
        'body' => [
            'errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
        ],
    ]))->toBeTrue();
    expect($isThrottled->invoke($finalizer, ['code' => 200, 'body' => ['data' => []]]))->toBeFalse();

    $responses = [
        '0' => [
            'body' => [
                'extensions' => [
                    'cost' => [
                        'throttleStatus' => [
                            'currentlyAvailable' => 1250,
                            'restoreRate'        => 100,
                        ],
                    ],
                ],
            ],
        ],
    ];

    expect($extractPoints->invoke($finalizer, $responses))->toBe(1250);
});
