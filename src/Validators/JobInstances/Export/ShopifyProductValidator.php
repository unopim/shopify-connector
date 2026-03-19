<?php

namespace Webkul\Shopify\Validators\JobInstances\Export;

use Webkul\DataTransfer\Validators\JobInstances\Default\JobValidator;

class ShopifyProductValidator extends JobValidator
{
    /**
     * Stores validation rules for data
     */
    protected array $rules = [
        'filters.credentials' => 'required|integer|min:0',
        'filters.channel'     => 'required',
        'filters.currency'    => 'required',
    ];

    /**
     * Names to be used for attributes during generation of error message
     */
    protected array $attributeNames = [
        'filters.credentials' => 'Credentials',
        'filters.channel'     => 'Channel',
        'filters.currency'    => 'Currency',
    ];

    public function getValidatorRule(): array
    {
        return $this->rules;
    }
}
