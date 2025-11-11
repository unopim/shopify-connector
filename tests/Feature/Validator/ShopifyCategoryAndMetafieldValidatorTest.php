<?php

use Illuminate\Support\Facades\Validator;
use Webkul\Shopify\Validators\JobInstances\Export\ShopifyCategoryAndMetafieldValidator;

beforeEach(function () {
    $this->validator = new ShopifyCategoryAndMetafieldValidator();
});

it('should passes validation with valid data', function () {
    $data = [
        'filters' => [
            'credentials' => 1,
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());
    expect($validator->passes())->toBeTrue();
});

it('should fails when credentials are missing', function () {
    $data = [
        'filters' => [
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.credentials');
});

it('should fails when credentials is not integer', function () {
    $data = [
        'filters' => [
            'credentials' => 'abc',
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.credentials');
});

it('should fails when credentials is less than zero', function () {
    $data = [
        'filters' => [
            'credentials' => -1,
        ],
    ];

    $validator = Validator::make($data, $this->validator->getValidatorRule());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('filters.credentials');
});
