<?php

namespace Webkul\Shopify\Helpers\Iterator;

use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class ProductIterator implements \Iterator
{
    use ShopifyGraphqlRequest;

    private $cursor;                // Tracks the current cursor for pagination

    private $currentPageData;       // Holds data for the current page

    private $currentKey;            // Tracks the current index within the current page

    private $credential;            // Credentials for Shopify API

    private ?string $shopifyLocale;

    private array $translationCache = [];

    private $mergedOptions;

    public function __construct($credential, ?string $shopifyLocale = null)
    {
        $this->credential = $credential;
        $this->shopifyLocale = $shopifyLocale;
        $this->cursor = null;       // Start with no cursor (first page)
        $this->currentPageData = [];
        $this->currentKey = 0;
        $this->fetchByCursor();
    }

    public function current(): mixed
    {
        return $this->currentPageData[$this->currentKey] ?? null;
    }

    public function key(): mixed
    {
        return $this->currentKey;
    }

    public function next(): void
    {
        $this->currentKey++;
        if ($this->currentKey >= count($this->currentPageData)) {
            $this->fetchByCursor();
        }
    }

    public function rewind(): void
    {
        if ($this->currentKey == 0) {
            return;
        }
        $this->cursor = null;       // Reset to the first page
        $this->currentPageData = [];
        $this->currentKey = 0;
        $this->fetchByCursor();     // Fetch the first page again
    }

    public function valid(): bool
    {
        return ! empty($this->currentPageData);
    }

    public function setCursor($cursor): void
    {
        $this->cursor = $cursor;
        $this->fetchByCursor();     // Fetch data based on the provided cursor
    }

    public function getCursor(): ?string
    {
        return $this->cursor;
    }

    private function fetchByCursor(): void
    {
        $this->currentPageData = [];
        try {
            $variables = [];
            if ($this->cursor) {
                $variables = [
                    'first' => 20,
                    'afterCursor' => $this->cursor,
                ];
            }

            $mutationType = $this->cursor ? 'productAllvalueGettingByCursor' : 'productAllvalueGetting';

            $graphResponse = $this->requestGraphQlApiAction($mutationType, $this->credential, $variables);

            $graphqlProducts = ! empty($graphResponse['body']['data']['products']['edges'])
            ? $graphResponse['body']['data']['products']['edges']
            : [];

            if (! empty($this->shopifyLocale)) {
                $graphqlProducts = $this->applyLocaleTranslations($graphqlProducts);
            }

            $this->currentPageData = $graphqlProducts;
            // Update the cursor for the next page
            $this->cursor = ! empty($graphqlProducts) ? end($graphqlProducts)['cursor'] : null;
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        $this->currentKey = 0;
    }

    protected function applyLocaleTranslations(array $edges): array
    {
        if (config('shopify-bulk-operations.import_bulk_translations', true)) {
            $this->primeTranslationsForPage($edges);
        }

        foreach ($edges as $index => $edge) {
            $resourceId = $edge['node']['id'] ?? null;
            if (empty($resourceId)) {
                continue;
            }

            $translations = $this->getTranslations($resourceId);
            if (empty($translations)) {
                continue;
            }

            if (array_key_exists('title', $translations)) {
                $edges[$index]['node']['title'] = $translations['title'];
            }

            if (array_key_exists('body_html', $translations)) {
                $edges[$index]['node']['descriptionHtml'] = $translations['body_html'];
            }

            if (array_key_exists('product_type', $translations)) {
                $edges[$index]['node']['productType'] = $translations['product_type'];
            }

            if (array_key_exists('meta_title', $translations)) {
                $edges[$index]['node']['seo']['title'] = $translations['meta_title'];
            }

            if (array_key_exists('meta_description', $translations)) {
                $edges[$index]['node']['seo']['description'] = $translations['meta_description'];
            }
        }

        return $edges;
    }

    /**
     * Prefetch translations for every product in the current page in ONE GraphQL
     * call via translatableResourcesByIds. Falls back silently if the bulk call
     * errors — the per-resource getTranslations() path will still run.
     */
    protected function primeTranslationsForPage(array $edges): void
    {
        if (empty($this->shopifyLocale)) {
            return;
        }

        $resourceIds = [];
        foreach ($edges as $edge) {
            $id = $edge['node']['id'] ?? null;
            if (! $id) {
                continue;
            }
            $cacheKey = $id.'|'.$this->shopifyLocale;
            if (array_key_exists($cacheKey, $this->translationCache)) {
                continue;
            }
            $resourceIds[] = $id;
        }

        if (empty($resourceIds)) {
            return;
        }

        try {
            $response = $this->requestGraphQlApiAction('getBulkTranslations', $this->credential, [
                'resourceIds' => $resourceIds,
                'locale' => $this->shopifyLocale,
            ]);

            $payload = $response['body']['data']['translatableResourcesByIds'] ?? [];
            $nodes = $payload['nodes'] ?? null;
            if (! is_array($nodes) && isset($payload['edges']) && is_array($payload['edges'])) {
                $nodes = array_map(fn ($e) => $e['node'] ?? [], $payload['edges']);
            }
            if (! is_array($nodes)) {
                return;
            }

            $returnedIds = [];
            foreach ($nodes as $node) {
                $rid = $node['resourceId'] ?? null;
                if (! $rid) {
                    continue;
                }
                $returnedIds[$rid] = true;
                $this->translationCache[$rid.'|'.$this->shopifyLocale] = collect($node['translations'] ?? [])
                    ->filter(fn ($item) => isset($item['key']))
                    ->pluck('value', 'key')
                    ->toArray();
            }

            // Resources that did not come back have no translations — cache an
            // empty result so the per-resource path doesn't re-query.
            foreach ($resourceIds as $rid) {
                if (! isset($returnedIds[$rid])) {
                    $this->translationCache[$rid.'|'.$this->shopifyLocale] = [];
                }
            }
        } catch (\Throwable) {
            // swallow — per-resource fallback in getTranslations() will run
        }
    }

    protected function getTranslations(string $resourceId): array
    {
        $cacheKey = $resourceId.'|'.$this->shopifyLocale;
        if (array_key_exists($cacheKey, $this->translationCache)) {
            return $this->translationCache[$cacheKey];
        }

        try {
            $response = $this->requestGraphQlApiAction('getCollectionTranslations', $this->credential, [
                'resourceId' => $resourceId,
                'locale' => $this->shopifyLocale,
            ]);

            $translations = $response['body']['data']['translatableResource']['translations'] ?? [];
            $this->translationCache[$cacheKey] = collect($translations)
                ->filter(fn ($item) => isset($item['key']))
                ->pluck('value', 'key')
                ->toArray();
        } catch (\Throwable $e) {
            $this->translationCache[$cacheKey] = [];
        }

        return $this->translationCache[$cacheKey];
    }
}
