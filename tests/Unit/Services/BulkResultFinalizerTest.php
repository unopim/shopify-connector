<?php

use Webkul\Shopify\Services\BulkResultFinalizer;

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
