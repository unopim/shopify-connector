<?php

namespace Webkul\Shopify\Validators\JobInstances\Export;

use Webkul\DataTransfer\Validators\JobInstances\Default\JobValidator;

class ShopifyProductValidator extends JobValidator
{
    protected array $rules = [
        'filters.credentials' => 'required|integer|min:0',
        'filters.channels'    => 'required',
        'filters.currencies'  => 'required',
        'filters.status'      => 'nullable|in:enable,disable,all',
        'filters.sku'         => 'nullable|string',
    ];

    protected array $attributeNames = [
        'filters.credentials' => 'Credentials',
        'filters.channels'    => 'Channel',
        'filters.currencies'  => 'Currency',
        'filters.status'      => 'Status',
        'filters.sku'         => 'SKU',
    ];

    public function getValidatorRule(): array
    {
        return $this->rules;
    }
}
