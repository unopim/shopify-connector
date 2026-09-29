<?php

use Illuminate\Support\Facades\Validator;
use Webkul\Shopify\Validators\JobInstances\Export\ShopifyProductValidator;

beforeEach(function () {
    $this->validator = new ShopifyProductValidator;
});

it('should pass validation with valid data', function () {
    $data = [
        'filters' => [
            'credentials' => 1,
            'channels'    => 'shopify_default',
            'currencies'  => 'USD',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->passes())->toBeTrue();
});

it('should fail when credentials are missing', function () {
    $data = [
        'filters' => [
            'channels'   => 'shopify_default',
            'currencies' => 'USD',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.credentials');
});

it('should fail when channel is missing', function () {
    $data = [
        'filters' => [
            'credentials' => 1,
            'currencies'  => 'USD',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.channels');
});

it('should fail when currency is missing', function () {
    $data = [
        'filters' => [
            'credentials' => 1,
            'channels'    => 'shopify_default',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.currencies');
});

it('should fail when credentials is not integer', function () {
    $data = [
        'filters' => [
            'credentials' => 'abc',
            'channels'    => 'shopify_default',
            'currencies'  => 'USD',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.credentials');
});

it('accepts core status values including all', function () {
    foreach (['enable', 'disable', 'all'] as $status) {
        $validator = Validator::make([
            'filters' => [
                'credentials' => 1,
                'channels'    => 'shopify_default',
                'currencies'  => 'USD',
                'status'      => $status,
            ],
        ], $this->validator->getValidatorRule());

        expect($validator->passes())->toBeTrue();
    }
});

it('rejects an unknown status value', function () {
    $validator = Validator::make([
        'filters' => [
            'credentials' => 1,
            'channels'    => 'shopify_default',
            'currencies'  => 'USD',
            'status'      => 'archived',
        ],
    ], $this->validator->getValidatorRule());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.status');
});

it('accepts a sku list as the identifiers control serialises it', function () {
    $validator = Validator::make([
        'filters' => [
            'credentials' => 1,
            'channels'    => 'shopify_default',
            'currencies'  => 'USD',
            'sku'         => 'SKU-1,SKU-2',
        ],
    ], $this->validator->getValidatorRule());

    expect($validator->passes())->toBeTrue();
});

it('rejects a sku list sent as an array', function () {
    $validator = Validator::make([
        'filters' => [
            'credentials' => 1,
            'channels'    => 'shopify_default',
            'currencies'  => 'USD',
            'sku'         => ['SKU-1', 'SKU-2'],
        ],
    ], $this->validator->getValidatorRule());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.sku');
});
