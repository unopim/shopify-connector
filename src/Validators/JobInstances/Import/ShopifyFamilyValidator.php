<?php

namespace Webkul\Shopify\Validators\JobInstances\Import;

use Webkul\DataTransfer\Validators\JobInstances\Default\JobValidator;

class ShopifyFamilyValidator extends JobValidator
{
    protected array $rules = [
        'filters.credentials'      => 'required|integer|min:0',
        'filters.locale'           => 'required',
        'filters.attributegroupid' => 'required|integer|min:0',
    ];

    protected array $attributeNames = [
        'filters.credentials'      => 'Credentials',
        'filters.locale'           => 'Locale',
        'filters.attributegroupid' => 'AttributeGroupId',
    ];
}
