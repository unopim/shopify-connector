<?php

namespace Webkul\Shopify\Helpers\Iterator;

use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class CategoryIterator implements \Iterator
{
    use ShopifyGraphqlRequest;

    private $cursor;

    private array $currentPageData;

    private int $currentKey;

    private $mergedOptions;

    public function __construct(private $credential)
    {
        $this->cursor = null;
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
        if ($this->currentKey === 0) {
            return;
        }
        $this->cursor = null;
        $this->currentPageData = [];
        $this->currentKey = 0;
        $this->fetchByCursor();
    }

    public function valid(): bool
    {
        return $this->currentPageData !== [];
    }

    public function setCursor($cursor): void
    {
        $this->cursor = $cursor;
        $this->fetchByCursor();
    }

    public function getCursor(): ?string
    {
        return $this->cursor;
    }

    private function fetchByCursor(): void
    {
        $this->currentPageData = [];
        try {
            $variables = [
                'first' => 10,
            ];
            if ($this->cursor) {
                $variables = [
                    'first'       => 10,
                    'afterCursor' => $this->cursor,
                ];

            }

            $mutationType = $this->cursor ? 'GetCollectionsByCursor' : 'manualCollectionGetting';
            $graphResponse = $this->requestGraphQlApiAction($mutationType, $this->credential, $variables);

            $edges = $graphResponse['body']['data']['collections']['edges'] ?? [];

            $this->currentPageData = $edges;

            $this->cursor = ! empty($edges) ? end($edges)['cursor'] : null;

        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        $this->currentKey = 0;
    }
}
