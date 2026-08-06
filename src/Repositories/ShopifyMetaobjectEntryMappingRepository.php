<?php

namespace Webkul\Shopify\Repositories;

use Illuminate\Support\Facades\DB;
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
        $entryId = DB::table('wk_shopify_metaobject_entry_mappings as m')
            ->join('wk_shopify_metaobject_entries as e', 'e.id', '=', 'm.entry_id')
            ->where('m.gid', $gid)
            ->where('m.api_url', $apiUrl)
            ->value('m.entry_id');

        return $entryId ? (int) $entryId : null;
    }

    /**
     * @param  array<int, int>  $entryIds
     */
    public function deleteForEntries(array $entryIds): void
    {
        if (! empty($entryIds)) {
            DB::table('wk_shopify_metaobject_entry_mappings')->whereIn('entry_id', $entryIds)->delete();
        }
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
