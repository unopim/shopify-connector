<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders;

class PublishingBulkPayloadBuilder
{
    /**
     * Build JSONL payload lines for publishablePublish mutation.
     *
     * One product per line:
     * {
     *   "id": "gid://shopify/Product/123",
     *   "input": {
     *     "publicationIds": ["gid://shopify/Publication/456"]
     *   }
     * }
     *
     * @param  array  $entries  Successful productSet entries
     * @param  string|array  $publicationIds  Comma-separated or array of publication IDs
     * @return array  JSONL lines
     */
    public function build(array $entries, $publicationIds): array
    {
        if (empty($publicationIds)) {
            return [];
        }

        // Normalize to array of GIDs
        $publicationIds = is_array($publicationIds)
            ? $publicationIds
            : array_filter(explode(',', $publicationIds));

        if (empty($publicationIds)) {
            return [];
        }

        // Ensure all are GIDs
        $publicationIds = array_map(fn($id) => $this->ensureGid($id, 'Publication'), $publicationIds);

        $lines = [];

        foreach ($entries as $entry) {
            if (! empty($entry['user_errors']) || empty($entry['product']['id'])) {
                continue;
            }

            $productId = $entry['product']['id'];

            // Build input array: [ ['publicationId' => '...'], ... ]
            $input = array_map(fn($pid) => ['publicationId' => $pid], $publicationIds);

            $line = [
                'id' => $this->ensureGid($productId, 'Product'),
                'input' => $input,
            ];

            $lines[] = json_encode($line, JSON_UNESCAPED_SLASHES);
        }

        return $lines;
    }

    /**
     * Ensure an ID is in Shopify GID format.
     */
    protected function ensureGid(string $id, string $type): string
    {
        if (str_starts_with($id, 'gid://')) {
            return $id;
        }

        return "gid://shopify/{$type}/{$id}";
    }
}
