<?php

namespace Webkul\Shopify\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\Shopify\Contracts\ShopifyMetaobjectEntryMapping;

class ShopifyMetaobjectEntryMappingRepository extends Repository
{
    public function model(): string
    {
        return ShopifyMetaobjectEntryMapping::class;
    }

    public function gidFor(int $entryId, string $apiUrl): ?string
    {
        return $this->findOneWhere(['entry_id' => $entryId, 'api_url' => $apiUrl])?->gid;
    }

    public function entryIdForGid(string $gid, string $apiUrl): ?int
    {
        $mapping = $this->findOneWhere(['gid' => $gid, 'api_url' => $apiUrl]);

        return $mapping ? (int) $mapping->entry_id : null;
    }

    public function putGid(int $entryId, string $apiUrl, string $gid): void
    {
        $existing = $this->findOneWhere(['entry_id' => $entryId, 'api_url' => $apiUrl]);

        if ($existing) {
            $this->update(['gid' => $gid], $existing->id);

            return;
        }

        $this->create(['entry_id' => $entryId, 'api_url' => $apiUrl, 'gid' => $gid]);
    }
}
