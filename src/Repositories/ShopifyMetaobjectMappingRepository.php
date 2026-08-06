<?php

namespace Webkul\Shopify\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\Shopify\Contracts\ShopifyMetaobjectMapping;

class ShopifyMetaobjectMappingRepository extends Repository
{
    public function model(): string
    {
        return ShopifyMetaobjectMapping::class;
    }

    public function updateOrCreateByType(string $apiUrl, string $type, array $data): void
    {
        $existing = $this->findWhere(['api_url' => $apiUrl, 'type' => $type])->first();

        if ($existing) {
            $this->update($data, $existing->id);

            return;
        }

        $this->create(array_merge($data, ['api_url' => $apiUrl, 'type' => $type]));
    }
}
