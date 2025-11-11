<?php

namespace Tests\Unit\Validators;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;
use Webkul\Shopify\Validators\JobInstances\Export\ShopifyCategoryAndMetafieldValidator;

class ShopifyCategoryAndMetafieldValidatorTest extends TestCase
{
    protected ShopifyCategoryAndMetafieldValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ShopifyCategoryAndMetafieldValidator();
    }

    /** @test */
    public function it_passes_validation_with_valid_data()
    {
        $data = [
            'filters' => [
                'credentials' => 1,
            ],
        ];

        $validator = Validator::make($data, $this->validator->getValidatorRule());

        $this->assertTrue($validator->passes());
    }

    /** @test */
    public function it_fails_when_credentials_are_missing()
    {
        $data = [
            'filters' => [
                // missing credentials
            ],
        ];

        $validator = Validator::make($data, $this->validator->getValidatorRule());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('filters.credentials', $validator->errors()->toArray());
    }

    /** @test */
    public function it_fails_when_credentials_is_not_integer()
    {
        $data = [
            'filters' => [
                'credentials' => 'abc',
            ],
        ];

        $validator = Validator::make($data, $this->validator->getValidatorRule());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('filters.credentials', $validator->errors()->toArray());
    }

    /** @test */
    public function it_fails_when_credentials_is_less_than_zero()
    {
        $data = [
            'filters' => [
                'credentials' => -1,
            ],
        ];

        $validator = Validator::make($data, $this->validator->getValidatorRule());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('filters.credentials', $validator->errors()->toArray());
    }
}
