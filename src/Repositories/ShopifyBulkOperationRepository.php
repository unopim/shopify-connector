<?php

namespace Webkul\Shopify\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\Shopify\Models\ShopifyBulkOperation;

class ShopifyBulkOperationRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return ShopifyBulkOperation::class;
    }
}
