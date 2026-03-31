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
            'channel' => 'shopify_default',
            'currency' => 'USD',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->passes())->toBeTrue();
});

it('should fail when credentials are missing', function () {
    $data = [
        'filters' => [
            'channel' => 'shopify_default',
            'currency' => 'USD',
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
            'currency' => 'USD',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.channel');
});

it('should fail when currency is missing', function () {
    $data = [
        'filters' => [
            'credentials' => 1,
            'channel' => 'shopify_default',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.currency');
});

it('should fail when credentials is not integer', function () {
    $data = [
        'filters' => [
            'credentials' => 'abc',
            'channel' => 'shopify_default',
            'currency' => 'USD',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.credentials');
});
