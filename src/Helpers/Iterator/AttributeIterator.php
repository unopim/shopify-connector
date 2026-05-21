<?php

namespace Webkul\Shopify\Helpers\Iterator;

use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class AttributeIterator implements \Iterator
{
    use ShopifyGraphqlRequest;

    private $cursor;

    private $currentPageData;

    private $currentKey;

    private $credential;

    private $shopifyLocale;

    private array $translationCache = [];

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
        $this->currentKey = 0;

        try {
            do {
                $variables = [];
                if ($this->cursor) {
                    $variables = [
                        'first' => 50,
                        'afterCursor' => $this->cursor,
                    ];
                }

                $mutationType = $this->cursor ? 'productOptionByCursor' : 'productGettingOptions';
                $graphResponse = $this->requestGraphQlApiAction($mutationType, $this->credential, $variables);

                $edges = $graphResponse['body']['data']['products']['edges'] ?? [];

                $previousCursor = $this->cursor;
                // Update the cursor for the next page
                $this->cursor = ! empty($edges) ? end($edges)['cursor'] : null;
                $this->currentPageData = $this->formatedAttributeAndOption($edges);

                // A page can hold only simple products (no variant options) and
                // so yield no attributes. Keep paging until attributes are found
                // or the product list is exhausted, instead of ending early.
            } while (
                empty($this->currentPageData)
                && ! empty($edges)
                && ! empty($this->cursor)
                && $this->cursor !== $previousCursor
            );
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        $this->currentKey = 0;
    }

    /**
     * Formating Attribute and attriute Option
     */
    public function formatedAttributeAndOption(array $options): array
    {
        $optionsArray = [];
        foreach ($options as $option) {
            $productOptions = $option['node']['options'] ?? [];
            foreach ($productOptions as $productOption) {
                // Shopify exposes option values as `values`; the SaaS proxy's
                // product list returns them only under `optionValues`. Derive
                // the value names from whichever the response carries.
                $optionValueNames = $productOption['values']
                    ?? array_column($productOption['optionValues'] ?? [], 'name');

                if (($productOption['name'] ?? '') === 'Title'
                    && in_array('Default Title', (array) $optionValueNames, true)) {
                    continue;
                }

                $optionLabel = $productOption['name'] ?? '';
                $optionValues = $productOption['optionValues'] ?? [];
                $optionValueLabels = [];
                $modifiedArray = [];

                if (! empty($this->shopifyLocale) && ! empty($productOption['id'])) {
                    $translatedOptionLabel = $this->getTranslatedLabel($productOption['id']);

                    if (! empty($translatedOptionLabel)) {
                        $optionLabel = $translatedOptionLabel;
                    }
                }

                if (! empty($optionValues)) {
                    foreach ($optionValues as $optionValue) {
                        $defaultLabel = $optionValue['name'] ?? '';
                        $normalizedCode = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $defaultLabel), '-');
                        $translatedLabel = $defaultLabel;

                        if (! empty($this->shopifyLocale) && ! empty($optionValue['id'])) {
                            $fetchedTranslation = $this->getTranslatedLabel($optionValue['id']);

                            if (! empty($fetchedTranslation)) {
                                $translatedLabel = $fetchedTranslation;
                            }
                        }

                        if ($normalizedCode === '') {
                            continue;
                        }

                        $modifiedArray[] = $normalizedCode;
                        $optionValueLabels[$normalizedCode] = $translatedLabel;
                    }
                } else {
                    foreach ($productOption['values'] ?? [] as $optionValue) {
                        $normalizedCode = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $optionValue), '-');

                        if ($normalizedCode === '') {
                            continue;
                        }

                        $modifiedArray[] = $normalizedCode;
                        $optionValueLabels[$normalizedCode] = $optionValue;
                    }
                }

                $name = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $productOption['name'] ?? ''));

                if (! isset($optionsArray[$name])) {
                    $optionsArray[$name] = [
                        'name' => $name,
                        'label' => $optionLabel,
                        'type' => 'select',
                        'code' => array_values(array_unique($modifiedArray)),
                        'labels' => $optionValueLabels,
                    ];
                } else {
                    $optionsArray[$name]['code'] = array_values(array_unique(array_merge(
                        $optionsArray[$name]['code'],
                        $modifiedArray
                    )));

                    $optionsArray[$name]['labels'] = array_merge(
                        $optionsArray[$name]['labels'],
                        $optionValueLabels
                    );
                }
            }
        }

        return array_values($optionsArray);
    }

    /**
     * Fetch translated label for option/optionValue by Shopify locale.
     */
    private function getTranslatedLabel(string $resourceId): ?string
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
            $translatedName = collect($translations)->firstWhere('key', 'name')['value'] ?? null;

            $this->translationCache[$cacheKey] = $translatedName;

            return $translatedName;
        } catch (\Throwable $e) {
            $this->translationCache[$cacheKey] = null;

            return null;
        }
    }
}
