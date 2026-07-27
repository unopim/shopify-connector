<?php

namespace Webkul\Shopify\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\Shopify\Contracts\ShopifyMetaobjectEntry;

class ShopifyMetaobjectEntryRepository extends Repository
{
    public function model(): string
    {
        return ShopifyMetaobjectEntry::class;
    }

    public function updateOrCreateByCode(string $type, string $code, array $values): void
    {
        $existing = $this->findWhere(['type' => $type, 'code' => $code])->first();

        if ($existing) {
            $this->update(['values' => $values], $existing->id);

            return;
        }

        $this->create(['type' => $type, 'code' => $code, 'values' => $values]);
    }
}
