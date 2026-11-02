<?php

use Webkul\Shopify\Helpers\Exporters\Product\ShopifyGraphQLDataFormatter;
use Webkul\Shopify\Helpers\MeasurementUnitMapper;

it('preserves decimals on compareAtPrice for mapped attribute and default value', function () {
    $formatter = new ShopifyGraphQLDataFormatter;
    $currencyProperty = new ReflectionProperty($formatter, 'currency');
    $currencyProperty->setAccessible(true);
    $currencyProperty->setValue($formatter, 'USD');

    $rawData = [
        'compare_price' => ['USD' => '19.99'],
        'sale_price'    => ['USD' => '14.50'],
    ];

    $refVariant = new ReflectionMethod($formatter, 'processVariantFields');
    $refVariant->setAccessible(true);
    $mapped = $refVariant->invoke($formatter, [], $rawData, 'compareAtPrice', 'compare_price', []);
    expect($mapped['variant']['compareAtPrice'])->toBe(19.99);

    $refDefault = new ReflectionMethod($formatter, 'applyDefaultVariantValue');
    $refDefault->setAccessible(true);
    $default = $refDefault->invoke($formatter, [], 'compareAtPrice', '29.95');
    expect($default['variant']['compareAtPrice'])->toBe(29.95);
});

it('resolves measurement with amount and with value keys', function () {
    $mapper = new MeasurementUnitMapper;

    $withAmount = $mapper->resolve('weight', [
        'family' => 'Weight',
        'unit'   => 'KILOGRAM',
        'amount' => '10.5',
    ]);
    expect($withAmount)->toBe([10.5, 'KILOGRAMS']);

    $withValue = $mapper->resolve('weight', [
        'family' => 'Weight',
        'unit'   => 'KILOGRAM',
        'value'  => '10.5',
    ]);
    expect($withValue)->toBe([10.5, 'KILOGRAMS']);

    $importedWithoutFamily = $mapper->resolve('weight', [
        'unit'  => 'KILOGRAM',
        'value' => '0.29',
    ]);
    expect($importedWithoutFamily)->toBe([0.29, 'KILOGRAMS']);
});

it('resolves measurement case-insensitively for family code', function () {
    $mapper = new MeasurementUnitMapper;

    $lowercaseFamily = $mapper->resolve('weight', [
        'family' => 'weight',
        'unit'   => 'KILOGRAM',
        'value'  => '5.25',
    ]);
    expect($lowercaseFamily)->toBe([5.25, 'KILOGRAMS']);

    $unitCode = $mapper->toUnopim('weight', 'weight', 'KILOGRAMS');
    expect($unitCode)->toBe('KILOGRAM');
});

it('skips empty non-measurement fallback metafields without producing null values', function () {
    $formatter = new ShopifyGraphQLDataFormatter;

    $productMetaField = [
        [
            'code'           => 'package_weight',
            'name_space_key' => 'custom.package_weight',
            'type'           => 'weight',
            'listvalue'      => false,
        ],
    ];

    $rawDataEmpty = ['package_weight' => null];
    $resultEmpty = $formatter->processProductMetaFieldDefintions($rawDataEmpty, 'en_US', $productMetaField, []);
    expect($resultEmpty)->toBeEmpty();

    $rawDataValid = ['package_weight' => '15.5'];
    $resultValid = $formatter->processProductMetaFieldDefintions($rawDataValid, 'en_US', $productMetaField, ['weight' => 'GRAMS']);
    expect($resultValid)->toHaveCount(1)
        ->and($resultValid[0]['value'])->toBe(json_encode(['value' => 15.5, 'unit' => 'GRAMS']));
});
