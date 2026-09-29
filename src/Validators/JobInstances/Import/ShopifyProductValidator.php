<?php

namespace Webkul\Shopify\Validators\JobInstances\Import;

use Webkul\DataTransfer\Validators\JobInstances\Default\JobValidator;

class ShopifyProductValidator extends JobValidator
{
    protected array $rules = [
        'filters.credentials' => 'required|integer|min:0',
        'filters.locale'      => 'required',
        'filters.channel'     => 'required',
        'filters.currency'    => 'required',
        'filters.status'      => 'nullable|in:active,draft,archived,unlisted',
    ];

    protected array $attributeNames = [
        'filters.credentials' => 'Credentials',
        'filters.locale'      => 'Locale',
        'filters.channel'     => 'Channel',
        'filters.currency'    => 'Currency',
        'filters.status'      => 'Status',
    ];
}
