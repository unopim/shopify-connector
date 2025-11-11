<?php

namespace Tests\Unit\Validators;

use Webkul\Shopify\Validators\JobInstances\Export\ShopifyProductValidator;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ShopifyProductValidatorTest extends TestCase
{
    protected ShopifyProductValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ShopifyProductValidator();
    }

    /** @test */
    public function it_passes_validation_with_valid_data()
    {
        $data = [
            'filters' => [
                'credentials' => 1,
                'channel'     => 'shopify_default',
                'currency'    => 'USD',
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
                // 'credentials' => missing
                'channel'  => 'shopify_default',
                'currency' => 'USD',
            ],
        ];

        $validator = Validator::make($data, $this->validator->getValidatorRule());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('filters.credentials', $validator->errors()->toArray());
    }

    /** @test */
    public function it_fails_when_channel_is_missing()
    {
        $data = [
            'filters' => [
                'credentials' => 1,
                // 'channel' missing
                'currency' => 'USD',
            ],
        ];

        $validator = Validator::make($data, $this->validator->getValidatorRule());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('filters.channel', $validator->errors()->toArray());
    }

    /** @test */
    public function it_fails_when_currency_is_missing()
    {
        $data = [
            'filters' => [
                'credentials' => 1,
                'channel'     => 'shopify_default',
                // 'currency' missing
            ],
        ];

        $validator = Validator::make($data, $this->validator->getValidatorRule());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('filters.currency', $validator->errors()->toArray());
    }

    /** @test */
    public function it_fails_when_credentials_is_not_integer()
    {
        $data = [
            'filters' => [
                'credentials' => 'abc', // invalid
                'channel'     => 'shopify_default',
                'currency'    => 'USD',
            ],
        ];

        $validator = Validator::make($data, $this->validator->getValidatorRule());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('filters.credentials', $validator->errors()->toArray());
    }
}
