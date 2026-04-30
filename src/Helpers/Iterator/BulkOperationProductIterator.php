<?php

namespace Webkul\Shopify\Helpers\Iterator;

use Illuminate\Support\Facades\Log;
use Webkul\Shopify\Services\Bulk\Import\BulkProductFetcher;

/**
 * Iterates Shopify products fetched via bulkOperationRunQuery.
 *
 * BulkProductFetcher returns one JSONL per pass (products+variants pass,
 * relations pass). This iterator ingests both into a single in-memory map
 * keyed by Shopify GID, then yields products in the EXACT same shape as
 * Webkul\Shopify\Helpers\Iterator\ProductIterator so the downstream importer
 * (Importer::saveProductsData) does not need to change.
 *
 * Memory: for typical Shopify catalogs (under ~50k products) the in-memory
 * map is acceptable. For larger shops, switch to a streaming reassembly.
 */
class BulkOperationProductIterator implements \Iterator
{
    /** Shopify-translation key -> path inside the assembled product node. */
    protected const TRANSLATION_TARGETS = [
        'title' => ['title'],
        'body_html' => ['descriptionHtml'],
        'product_type' => ['productType'],
        'meta_title' => ['seo', 'title'],
        'meta_description' => ['seo', 'description'],
    ];

    /** Top-level product rows keyed by id. Merged across all passes. */
    protected array $productRows = [];

    /** Children keyed by __parentId. Merged across all passes. */
    protected array $rowsByParent = [];

    /** Order in which products were first encountered (preserves Shopify order). */
    protected array $productIds = [];

    protected int $index = 0;

    public function __construct(
        BulkProductFetcher $fetcher,
        array $credential,
        protected ?string $shopifyLocale = null,
    ) {
        try {
            $jsonlPaths = $fetcher->fetch($credential, $shopifyLocale);
        } catch (\Throwable $e) {
            Log::error('Shopify bulk import fetch failed', ['message' => $e->getMessage()]);
            throw $e;
        }

        foreach ($jsonlPaths as $path) {
            $this->ingest($path);
        }
    }

    public function current(): mixed
    {
        if ($this->index >= count($this->productIds)) {
            return null;
        }

        $id = $this->productIds[$this->index];

        return [
            'cursor' => null,
            'node' => $this->finalizeNode($id, $this->productRows[$id] ?? []),
        ];
    }

    public function key(): mixed
    {
        return $this->index;
    }

    public function next(): void
    {
        $this->index++;
    }

    public function rewind(): void
    {
        $this->index = 0;
    }

    public function valid(): bool
    {
        return $this->index < count($this->productIds);
    }

    /**
     * Stream a JSONL file into the rowsByParent / productRows maps.
     */
    protected function ingest(string $jsonlPath): void
    {
        $stream = @fopen($jsonlPath, 'r');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open Shopify bulk import JSONL file: '.$jsonlPath);
        }

        try {
            while (($line = fgets($stream)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);
                if (! is_array($row) || empty($row['id'])) {
                    continue;
                }

                if (empty($row['__parentId'])) {
                    if (! isset($this->productRows[$row['id']])) {
                        $this->productIds[] = $row['id'];
                        $this->productRows[$row['id']] = $row;
                    } else {
                        // Merge subsequent passes into the existing product (relations pass)
                        $this->productRows[$row['id']] = $row + $this->productRows[$row['id']];
                    }
                } else {
                    $this->rowsByParent[$row['__parentId']][] = $row;
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Build the wire shape (cursor + node) ProductIterator produces.
     */
    protected function finalizeNode(string $productId, array $product): array
    {
        $node = [
            'id' => $productId,
            'title' => $product['title'] ?? '',
            'description' => $product['description'] ?? '',
            'descriptionHtml' => $product['descriptionHtml'] ?? '',
            'handle' => $product['handle'] ?? '',
            'status' => $product['status'] ?? 'DRAFT',
            'productType' => $product['productType'] ?? '',
            'vendor' => $product['vendor'] ?? '',
            'tags' => $product['tags'] ?? [],
            'publishedAt' => $product['publishedAt'] ?? null,
            'createdAt' => $product['createdAt'] ?? null,
            'updatedAt' => $product['updatedAt'] ?? null,
            'seo' => [
                'title' => $product['seo']['title'] ?? null,
                'description' => $product['seo']['description'] ?? null,
            ],
            'options' => $product['options'] ?? [],
            'collections' => $this->wrapEdges($this->childrenOf($productId, 'Collection')),
            'media' => ['nodes' => $this->mediaNodes($productId)],
            'metafields' => $this->wrapEdges($this->childrenOf($productId, 'Metafield')),
            'resourcePublications' => ['nodes' => $this->childrenOf($productId, 'ResourcePublication')],
            'variants' => [
                'pageInfo' => ['hasNextPage' => false],
                'edges' => $this->variantEdges($productId),
            ],
        ];

        $this->applyTranslations($node, $product['translations'] ?? []);

        return $node;
    }

    /**
     * Variants for a product. Each variant gets its own nested children resolved.
     */
    protected function variantEdges(string $productId): array
    {
        $variantRows = $this->childrenOf($productId, 'ProductVariant');
        $edges = [];

        foreach ($variantRows as $variant) {
            $variantId = $variant['id'];
            $inventoryItem = $variant['inventoryItem'] ?? [];
            $inventoryItemId = $inventoryItem['id'] ?? null;

            // InventoryLevel rows may be parented to inventoryItem id (typical) or variant id.
            $inventoryLevels = [];
            if ($inventoryItemId) {
                $inventoryLevels = $this->childrenOf($inventoryItemId, 'InventoryLevel');
            }
            if (empty($inventoryLevels)) {
                $inventoryLevels = $this->childrenOf($variantId, 'InventoryLevel');
            }

            $variantNode = [
                'id' => $variantId,
                'title' => $variant['title'] ?? '',
                'sku' => $variant['sku'] ?? '',
                'price' => $variant['price'] ?? null,
                'compareAtPrice' => $variant['compareAtPrice'] ?? null,
                'barcode' => $variant['barcode'] ?? null,
                'taxable' => $variant['taxable'] ?? false,
                'inventoryQuantity' => $variant['inventoryQuantity'] ?? 0,
                'inventoryPolicy' => $variant['inventoryPolicy'] ?? null,
                'selectedOptions' => $variant['selectedOptions'] ?? [],
                'metafields' => $this->wrapEdges($this->childrenOf($variantId, 'Metafield')),
                'media' => ['nodes' => $this->mediaNodes($variantId)],
                'inventoryItem' => [
                    'id' => $inventoryItemId,
                    'tracked' => $inventoryItem['tracked'] ?? false,
                    'requiresShipping' => $inventoryItem['requiresShipping'] ?? true,
                    'unitCost' => $inventoryItem['unitCost'] ?? null,
                    'measurement' => $inventoryItem['measurement'] ?? null,
                    'inventoryLevels' => $this->wrapEdges($inventoryLevels),
                ],
            ];

            $edges[] = [
                'cursor' => null,
                'node' => $variantNode,
            ];
        }

        return $edges;
    }

    protected function mediaNodes(string $parentId): array
    {
        $rows = $this->rowsByParent[$parentId] ?? [];

        $mediaTypes = ['MediaImage', 'Video', 'ExternalVideo', 'Model3d'];

        return array_values(array_filter($rows, function ($row) use ($mediaTypes) {
            return in_array($this->resolveTypename($row), $mediaTypes, true);
        }));
    }

    /**
     * All children of a parent matching a __typename (e.g. ProductVariant,
     * Metafield, Collection). Bulk-operation JSONL does NOT include __typename
     * by default — we resolve it from the GID prefix (gid://shopify/<Type>/...).
     */
    protected function childrenOf(string $parentId, string $typename): array
    {
        $rows = $this->rowsByParent[$parentId] ?? [];

        return array_values(array_filter(
            $rows,
            fn ($row) => $this->resolveTypename($row) === $typename,
        ));
    }

    /**
     * Resolve a row's Shopify type. Prefers explicit __typename if present
     * (regular GraphQL responses), otherwise parses the GID prefix.
     */
    protected function resolveTypename(array $row): string
    {
        if (! empty($row['__typename'])) {
            return (string) $row['__typename'];
        }

        $gid = (string) ($row['id'] ?? '');
        if (! str_starts_with($gid, 'gid://shopify/')) {
            return '';
        }

        $rest = substr($gid, strlen('gid://shopify/'));
        $parts = explode('/', $rest, 2);

        return $parts[0] ?? '';
    }

    /**
     * Wrap a list of nodes in the {edges:[{cursor,node}]} GraphQL connection
     * shape that the downstream importer expects.
     */
    protected function wrapEdges(array $nodes): array
    {
        $edges = array_map(fn ($node) => ['cursor' => null, 'node' => $node], $nodes);

        return ['edges' => $edges];
    }

    /**
     * Apply Shopify translation entries (key/value pairs) onto the assembled
     * product node, mirroring ProductIterator::applyLocaleTranslations.
     */
    protected function applyTranslations(array &$node, array $translations): void
    {
        if (empty($translations) || empty($this->shopifyLocale)) {
            return;
        }

        foreach ($translations as $entry) {
            $key = $entry['key'] ?? null;
            $value = $entry['value'] ?? null;

            if ($key === null || $value === null) {
                continue;
            }

            $path = self::TRANSLATION_TARGETS[$key] ?? null;
            if ($path === null) {
                continue;
            }

            $this->setByPath($node, $path, $value);
        }
    }

    /**
     * Set a nested value on $node following $path (e.g. ['seo', 'title']).
     */
    protected function setByPath(array &$node, array $path, $value): void
    {
        $cursor = &$node;
        foreach ($path as $segment) {
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
        $cursor = $value;
    }
}
