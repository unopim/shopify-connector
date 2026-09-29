<?php

namespace Webkul\Shopify\Helpers\Iterator;

use Illuminate\Support\Facades\Log;
use Webkul\Shopify\Services\Bulk\Import\BulkProductFetcher;

class BulkOperationProductIterator implements \Iterator
{
    protected const TRANSLATION_TARGETS = [
        'title'            => ['title'],
        'body_html'        => ['descriptionHtml'],
        'product_type'     => ['productType'],
        'meta_title'       => ['seo', 'title'],
        'meta_description' => ['seo', 'description'],
    ];

    protected array $productRows = [];

    protected array $rowsByParent = [];

    protected array $productIds = [];

    protected int $index = 0;

    public function __construct(
        BulkProductFetcher $fetcher,
        array $credential,
        protected ?string $shopifyLocale = null,
        protected ?string $statusFilter = null,
    ) {
        try {
            $jsonlPaths = $fetcher->fetch($credential, $shopifyLocale, $statusFilter);
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
            'node'   => $this->finalizeNode($id, $this->productRows[$id] ?? []),
        ];
    }

    public function key(): mixed
    {
        return $this->index;
    }

    public function next(): void
    {
        if (config('shopify-bulk-operations.import_release_jsonl_memory', true)) {
            $this->releaseProductAt($this->index);
        }

        $this->index++;
    }

    /**
     * Free the JSONL rows for the product we just yielded so peak memory does
     * not grow with the size of the catalog. The product row and all child rows
     * (variants, media, metafields, etc.) keyed under it are unset.
     */
    protected function releaseProductAt(int $index): void
    {
        $productId = $this->productIds[$index] ?? null;
        if ($productId === null) {
            return;
        }

        $product = $this->productRows[$productId] ?? null;
        if ($product !== null) {
            $variantRows = $this->childrenOf($productId, 'ProductVariant');

            foreach ($variantRows as $variant) {
                $vid = $variant['id'] ?? null;
                if ($vid) {
                    unset($this->rowsByParent[$vid]);

                    $invItemId = $variant['inventoryItem']['id'] ?? null;
                    if ($invItemId) {
                        unset($this->rowsByParent[$invItemId]);
                    }
                }
            }

            unset($this->rowsByParent[$productId], $this->productRows[$productId]);
        }
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
        throw_if($stream === false, \RuntimeException::class, 'Unable to open Shopify bulk import JSONL file: '.$jsonlPath);

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
            'id'              => $productId,
            'title'           => $product['title'] ?? '',
            'description'     => $product['description'] ?? '',
            'descriptionHtml' => $product['descriptionHtml'] ?? '',
            'handle'          => $product['handle'] ?? '',
            'status'          => $product['status'] ?? 'DRAFT',
            'productType'     => $product['productType'] ?? '',
            'vendor'          => $product['vendor'] ?? '',
            'tags'            => $product['tags'] ?? [],
            'category'        => ['id' => $product['category']['id'] ?? null],
            'publishedAt'     => $product['publishedAt'] ?? null,
            'createdAt'       => $product['createdAt'] ?? null,
            'updatedAt'       => $product['updatedAt'] ?? null,
            'seo'             => [
                'title'       => $product['seo']['title'] ?? null,
                'description' => $product['seo']['description'] ?? null,
            ],
            'options'              => $product['options'] ?? [],
            'collections'          => $this->wrapEdges($this->childrenOf($productId, 'Collection')),
            'media'                => ['nodes' => $this->mediaNodes($productId)],
            'metafields'           => $this->wrapEdges($this->childrenOf($productId, 'Metafield')),
            'resourcePublications' => ['nodes' => $this->childrenOf($productId, 'ResourcePublication')],
            'variants'             => [
                'pageInfo' => ['hasNextPage' => false],
                'edges'    => $this->variantEdges($productId),
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

            $inventoryLevels = [];
            if ($inventoryItemId) {
                $inventoryLevels = $this->childrenOf($inventoryItemId, 'InventoryLevel');
            }
            if ($inventoryLevels === []) {
                $inventoryLevels = $this->childrenOf($variantId, 'InventoryLevel');
            }

            $variantNode = [
                'id'                   => $variantId,
                'title'                => $variant['title'] ?? '',
                'sku'                  => $variant['sku'] ?? '',
                'price'                => $variant['price'] ?? null,
                'compareAtPrice'       => $variant['compareAtPrice'] ?? null,
                'barcode'              => $variant['barcode'] ?? null,
                'taxable'              => $variant['taxable'] ?? false,
                'inventoryQuantity'    => $variant['inventoryQuantity'] ?? 0,
                'inventoryPolicy'      => $variant['inventoryPolicy'] ?? null,
                'unitPriceMeasurement' => $variant['unitPriceMeasurement'] ?? null,
                'selectedOptions'      => $variant['selectedOptions'] ?? [],
                'metafields'           => $this->wrapEdges($this->childrenOf($variantId, 'Metafield')),
                'media'                => ['nodes' => $this->mediaNodes($variantId)],
                'inventoryItem'        => [
                    'id'               => $inventoryItemId,
                    'tracked'          => $inventoryItem['tracked'] ?? false,
                    'requiresShipping' => $inventoryItem['requiresShipping'] ?? true,
                    'unitCost'         => $inventoryItem['unitCost'] ?? null,
                    'measurement'      => $inventoryItem['measurement'] ?? null,
                    'inventoryLevels'  => $this->wrapEdges($inventoryLevels),
                ],
            ];

            $edges[] = [
                'cursor' => null,
                'node'   => $variantNode,
            ];
        }

        return $edges;
    }

    protected function mediaNodes(string $parentId): array
    {
        $rows = $this->rowsByParent[$parentId] ?? [];

        $mediaTypes = ['MediaImage', 'Video', 'ExternalVideo', 'Model3d'];

        return array_values(array_filter($rows, fn (array $row): bool => in_array($this->resolveTypename($row), $mediaTypes, true)));
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
            fn (array $row): bool => $this->resolveTypename($row) === $typename,
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
        $edges = array_map(fn ($node): array => ['cursor' => null, 'node' => $node], $nodes);

        return ['edges' => $edges];
    }

    /**
     * Apply Shopify translation entries (key/value pairs) onto the assembled
     * product node, mirroring ProductIterator::applyLocaleTranslations.
     */
    protected function applyTranslations(array &$node, array $translations): void
    {
        if ($translations === [] || empty($this->shopifyLocale)) {
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
