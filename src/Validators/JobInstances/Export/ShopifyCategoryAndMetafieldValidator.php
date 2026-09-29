<?php

namespace Webkul\Shopify\Validators\JobInstances\Export;

use Webkul\DataTransfer\Validators\JobInstances\Default\JobValidator;

class ShopifyCategoryAndMetafieldValidator extends JobValidator
{
    protected array $rules = [
        'filters.credentials' => 'required|integer|min:0',
    ];

    protected array $attributeNames = [
        'filters.credentials' => 'Credentials',
    ];

    public function getValidatorRule(): array
    {
        return $this->rules;
    }
}
