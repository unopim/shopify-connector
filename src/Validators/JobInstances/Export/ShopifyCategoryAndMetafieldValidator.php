<?php

namespace Webkul\Shopify\Validators\JobInstances\Export;

use Webkul\DataTransfer\Validators\JobInstances\Default\JobValidator;

class ShopifyCategoryAndMetafieldValidator extends JobValidator
{
    /**
     * Stores validation rules for data
     */
    protected array $rules = [
        'filters.credentials' => 'required|integer|min:0',
    ];

    /**
     * Names to be used for attributes during generation of error message
     */
    protected array $attributeNames = [
        'filters.credentials' => 'Credentials',
    ];

    public function getValidatorRule(): array
    {
        return $this->rules;
    }
}
