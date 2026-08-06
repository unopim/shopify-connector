<?php

namespace Webkul\Shopify\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\Shopify\Contracts\ShopifyMetaobjectDefinition;

class ShopifyMetaobjectDefinitionRepository extends Repository
{
    public function model(): string
    {
        return ShopifyMetaobjectDefinition::class;
    }
}
