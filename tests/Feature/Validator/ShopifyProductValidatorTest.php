<?php

use Webkul\Shopify\Validators\JobInstances\Export\ShopifyProductValidator;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->validator = new ShopifyProductValidator();
});

it('should passes validation with valid data', function () {
    $data = [
        'filters' => [
            'credentials' => 1,
            'channel'     => 'shopify_default',
            'currency'    => 'USD',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->passes())->toBeTrue();
});

it('should fails when credentials are missing', function () {
    $data = [
        'filters' => [
            'channel'  => 'shopify_default',
            'currency' => 'USD',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.credentials');
});

it('should fails when channel is missing', function () {
    $data = [
        'filters' => [
            'credentials' => 1,
            'currency'    => 'USD',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.channel');
});

it('should fails when currency is missing', function () {
    $data = [
        'filters' => [
            'credentials' => 1,
            'channel'     => 'shopify_default',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.currency');
});

it('should fails when credentials is not integer', function () {
    $data = [
        'filters' => [
            'credentials' => 'abc',
            'channel'     => 'shopify_default',
            'currency'    => 'USD',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.credentials');
});
