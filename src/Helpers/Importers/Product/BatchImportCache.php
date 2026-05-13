<?php

namespace Webkul\Shopify\Helpers\Importers\Product;

use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\AttributeOptionProxy;
use Webkul\Attribute\Repositories\AttributeFamilyRepository;
use Webkul\Category\Models\CategoryProxy;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Product\Models\ProductProxy;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shopify\Models\ShopifyMappingConfig;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;

/**
 * Per-batch lookup cache for the Shopify product importer.
 *
 * Replaces N+1 calls to findOneByField('sku', ...), categoryRepository->where('code', ...),
 * shopifyMappingRepository->where('code', ...), and attribute->options()->where('code', ...)
 * with single bulk SELECTs per batch.
 *
 * Each map is null until prime() is called; methods fall back to a live DB read so the
 * cache is purely additive — callers without a primed cache still work.
 */
class BatchImportCache
{
    /** @var array<string, mixed>|null SKU → Product model */
    protected ?array $productsBySku = null;

    /** @var array<string, string>|null category code → category code (existence map) */
    protected ?array $categoryCodes = null;

    /** @var array<string, array<int, array>>|null mapping code → list of mapping rows */
    protected ?array $mappingsByCode = null;

    /** @var array<int, mixed>|null attribute family id → family model */
    protected ?array $familiesById = null;

    /** @var array<int, array<string, mixed>>|null attribute_id → [option_code → option model] */
    protected ?array $optionsByAttribute = null;

    protected ?string $shopUrl = null;

    public function __construct(
        protected ProductRepository $productRepository,
        protected CategoryRepository $categoryRepository,
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected AttributeFamilyRepository $attributeFamilyRepository,
    ) {}

    /**
     * Walk the batch rows once and prefetch every lookup the per-row code path needs.
     *
     * Safe to call multiple times — later calls extend the cache.
     */
    public function prime(array $batchRows, ?string $shopUrl, array $attributesByCode): void
    {
        $this->shopUrl = $shopUrl;

        // Initialize every map so subsequent getX() lookups can cache misses.
        // Without this, a default property value of null would make the "cache
        // a miss" branch never run for maps that aren't bulk-primed below
        // (e.g. familiesById is filled lazily, not from the batch rows).
        $this->productsBySku ??= [];
        $this->categoryCodes ??= [];
        $this->mappingsByCode ??= [];
        $this->familiesById ??= [];
        $this->optionsByAttribute ??= [];

        $skus = [];
        $codes = [];
        $handles = [];
        $familyIds = [];
        $variantOptionByAttr = [];

        foreach ($batchRows as $row) {
            $handle = $row['node']['handle'] ?? null;
            if ($handle) {
                $skus[$handle] = true;
                $codes[$handle] = true;
            }

            foreach (($row['node']['collections']['edges'] ?? []) as $c) {
                $h = $c['node']['handle'] ?? null;
                if ($h) {
                    $handles[$h] = true;
                }
            }

            foreach (($row['node']['variants']['edges'] ?? []) as $v) {
                $vsku = $v['node']['sku'] ?? null;
                if ($vsku) {
                    $vsku = preg_replace('/[^A-Za-z0-9_-]/', '', $vsku);
                    $skus[$vsku] = true;
                    $codes[$vsku] = true;
                }

                foreach (($v['node']['selectedOptions'] ?? []) as $opt) {
                    if (($opt['name'] ?? null) === 'Title' && ($opt['value'] ?? null) === 'Default Title') {
                        continue;
                    }
                    $attrCode = preg_replace('/[^A-Za-z0-9]+/', '_', strtolower($opt['name'] ?? ''));
                    if ($attrCode === '' || ! isset($attributesByCode[$attrCode])) {
                        continue;
                    }
                    $optionCode = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $opt['value'] ?? ''), '-');
                    if ($optionCode === '') {
                        continue;
                    }
                    $attrId = $attributesByCode[$attrCode]->id ?? null;
                    if ($attrId) {
                        $variantOptionByAttr[$attrId][$optionCode] = true;
                    }
                }
            }
        }

        $this->primeProducts(array_keys($skus));
        $this->primeCategories(array_keys($handles));
        $this->primeMappings(array_keys($codes));
        $this->primeOptions($variantOptionByAttr);
    }

    public function getProductBySku(?string $sku): mixed
    {
        if ($sku === null || $sku === '') {
            return null;
        }

        if ($this->productsBySku !== null && array_key_exists($sku, $this->productsBySku)) {
            return $this->productsBySku[$sku];
        }

        $product = $this->productRepository->findOneByField('sku', $sku);

        if ($this->productsBySku !== null) {
            $this->productsBySku[$sku] = $product;
        }

        return $product;
    }

    /**
     * Replace the cached Product for a SKU (e.g. after a create).
     */
    public function rememberProduct(string $sku, mixed $product): void
    {
        if ($this->productsBySku !== null) {
            $this->productsBySku[$sku] = $product;
        }
    }

    public function hasCategoryCode(string $code): bool
    {
        // Primed miss (null) and primed hit (string) are both stored — use
        // array_key_exists so null entries don't silently fall through.
        if ($this->categoryCodes !== null && array_key_exists($code, $this->categoryCodes)) {
            return $this->categoryCodes[$code] !== null;
        }

        $exists = (bool) $this->categoryRepository->where('code', $code)->first();

        if ($this->categoryCodes !== null) {
            $this->categoryCodes[$code] = $exists ? $code : null;
        }

        return $exists;
    }

    public function getMappingsByCode(string $code, string $entityType): array
    {
        if ($this->mappingsByCode !== null && isset($this->mappingsByCode[$code])) {
            return array_values(array_filter(
                $this->mappingsByCode[$code],
                fn ($row) => ($row['entityType'] ?? null) === $entityType,
            ));
        }

        if ($this->mappingsByCode !== null && array_key_exists($code, $this->mappingsByCode)) {
            // primed and confirmed empty
            return [];
        }

        $rows = $this->shopifyMappingRepository
            ->where('code', $code)
            ->where('entityType', $entityType)
            ->where('apiUrl', $this->shopUrl)
            ->get()
            ->toArray();

        if ($this->mappingsByCode !== null) {
            $this->mappingsByCode[$code] = $rows;
        }

        return $rows;
    }

    /**
     * Remember a freshly-written mapping so subsequent reads in the same batch see it.
     */
    public function rememberMapping(string $code, array $mappingRow): void
    {
        if ($this->mappingsByCode === null) {
            return;
        }
        $this->mappingsByCode[$code][] = $mappingRow;
    }

    public function getFamilyById(int $id): mixed
    {
        if ($this->familiesById !== null && array_key_exists($id, $this->familiesById)) {
            return $this->familiesById[$id];
        }

        $family = $this->attributeFamilyRepository->where('id', $id)->first();

        if ($this->familiesById !== null) {
            $this->familiesById[$id] = $family;
        }

        return $family;
    }

    public function getAttributeOption(int $attributeId, string $optionCode, callable $fallback): mixed
    {
        if ($this->optionsByAttribute !== null
            && isset($this->optionsByAttribute[$attributeId])
            && array_key_exists($optionCode, $this->optionsByAttribute[$attributeId])
        ) {
            return $this->optionsByAttribute[$attributeId][$optionCode];
        }

        $option = $fallback();

        if ($this->optionsByAttribute !== null) {
            $this->optionsByAttribute[$attributeId][$optionCode] = $option;
        }

        return $option;
    }

    protected function primeProducts(array $skus): void
    {
        $skus = array_values(array_unique(array_filter($skus)));
        if (empty($skus)) {
            $this->productsBySku = $this->productsBySku ?? [];

            return;
        }

        $map = $this->productsBySku ?? [];

        // Initialize every requested SKU as a confirmed miss so the cache short-circuits
        // a per-row findOneByField call instead of falling through to the DB.
        foreach ($skus as $sku) {
            if (! array_key_exists($sku, $map)) {
                $map[$sku] = null;
            }
        }

        foreach (array_chunk($skus, 1000) as $chunk) {
            $found = ProductProxy::query()
                ->whereIn('sku', $chunk)
                ->get();

            foreach ($found as $product) {
                $map[$product->sku] = $product;
            }
        }

        $this->productsBySku = $map;
    }

    protected function primeCategories(array $codes): void
    {
        $codes = array_values(array_unique(array_filter($codes)));
        if (empty($codes)) {
            $this->categoryCodes = $this->categoryCodes ?? [];

            return;
        }

        $map = $this->categoryCodes ?? [];

        foreach (array_chunk($codes, 1000) as $chunk) {
            $found = CategoryProxy::query()
                ->whereIn('code', $chunk)
                ->pluck('code');

            foreach ($found as $c) {
                $map[$c] = $c;
            }
        }

        // Mark misses so hasCategoryCode() can answer without hitting the DB.
        foreach ($codes as $code) {
            if (! isset($map[$code])) {
                $map[$code] = null;
            }
        }

        $this->categoryCodes = $map;
    }

    protected function primeMappings(array $codes): void
    {
        $codes = array_values(array_unique(array_filter($codes)));
        if (empty($codes) || $this->shopUrl === null) {
            $this->mappingsByCode = $this->mappingsByCode ?? [];

            return;
        }

        $table = (new ShopifyMappingConfig)->getTable();

        $map = $this->mappingsByCode ?? [];
        foreach ($codes as $c) {
            $map[$c] = $map[$c] ?? [];
        }

        foreach (array_chunk($codes, 1000) as $chunk) {
            $rows = DB::table($table)
                ->whereIn('code', $chunk)
                ->where('apiUrl', $this->shopUrl)
                ->get()
                ->toArray();

            foreach ($rows as $row) {
                $row = (array) $row;
                $map[$row['code']][] = $row;
            }
        }

        $this->mappingsByCode = $map;
    }

    protected function primeOptions(array $variantOptionByAttr): void
    {
        $this->optionsByAttribute = $this->optionsByAttribute ?? [];

        foreach ($variantOptionByAttr as $attrId => $optionCodes) {
            $codes = array_keys($optionCodes);
            if (empty($codes)) {
                continue;
            }

            $options = AttributeOptionProxy::query()
                ->where('attribute_id', $attrId)
                ->whereIn('code', $codes)
                ->get()
                ->keyBy('code');

            $existing = $this->optionsByAttribute[$attrId] ?? [];
            foreach ($codes as $code) {
                $existing[$code] = $options->get($code);
            }
            $this->optionsByAttribute[$attrId] = $existing;
        }
    }
}
