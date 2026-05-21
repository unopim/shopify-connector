<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders;

class CollectionsBulkPayloadBuilder
{
    protected array $collectionProducts = [];

    /**
     * Build JSONL payload lines for collectionAddProducts mutation.
     *
     * Groups products by collection to minimize lines.
     * Each line: { "id": "gid://shopify/Collection/123", "productIds": ["gid://shopify/Product/1", ...] }
     * Max ~200 productIds per line to avoid payload limits.
     *
     * @param  array  $entries  Successful productSet entries
     * @param  int  $chunkSize  Max products per collection batch (default 200)
     * @return array JSONL lines
     */
    public function build(array $entries, int $chunkSize = 200): array
    {
        $this->collectionProducts = [];

        // Group products by collection
        foreach ($entries as $entry) {
            if (! empty($entry['user_errors']) || empty($entry['product']['id'])) {
                continue;
            }

            $productId = $entry['product']['id'];
            $manifest = $entry['manifest'] ?? [];
            $collections = $manifest['phase_context']['collections'] ?? [];

            foreach ($collections as $collectionId) {
                if (! isset($this->collectionProducts[$collectionId])) {
                    $this->collectionProducts[$collectionId] = [];
                }
                $this->collectionProducts[$collectionId][] = $productId;
            }
        }

        $lines = [];

        foreach ($this->collectionProducts as $collectionId => $productIds) {
            $productIds = array_unique($productIds);

            // Chunk if there are too many products
            $chunks = array_chunk($productIds, $chunkSize);

            foreach ($chunks as $chunk) {
                $line = [
                    'id' => $this->ensureGid($collectionId, 'Collection'),
                    'productIds' => array_map(fn ($id) => $this->ensureGid($id, 'Product'), $chunk),
                ];

                $lines[] = json_encode($line, JSON_UNESCAPED_SLASHES);
            }
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
