<?php

namespace Webkul\Shopify\Validators\JobInstances\Import;

use Webkul\DataTransfer\Validators\JobInstances\Default\JobValidator;

class ShopifyCategoryAndAttrValidator extends JobValidator
{
    protected array $rules = [
        'filters.credentials' => 'required|integer|min:0',
        'filters.locale'      => 'required',
    ];

    protected array $attributeNames = [
        'filters.credentials' => 'Credentials',
        'filters.locale'      => 'Locale',
    ];
}
