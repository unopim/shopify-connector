<?php

namespace Webkul\Shopify\Services\Bulk\Phases\Export;

use Illuminate\Support\Collection;
use Webkul\Product\Repositories\ProductAssociationRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Services\ProductValueMapper;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;
use Webkul\Shopify\Services\Bulk\Phases\BasePhaseService;
use Webkul\Shopify\Services\BulkOperationService;

class ReferenceMetafieldPhaseService extends BasePhaseService
{
    public function __construct(
        protected ShopifyMetaFieldRepository $metaFieldRepository,
        protected ShopifyMappingRepository $mappingRepository,
        protected ProductRepository $productRepository,
        protected ProductAssociationRepository $associationRepository,
        protected ProductValueMapper $productValueMapper,
        BulkOperationService $bulkOperationService,
        ShopifyBulkOperationRepository $bulkOperationRepository,
        ShopifyCredentialRepository $credentialRepository,
    ) {
        parent::__construct($bulkOperationService, $bulkOperationRepository, $credentialRepository);
    }

    protected function buildPayloadLines(array $operationData): array
    {
        $entries = $this->successfulEntries($operationData);
        $skus = array_values(array_filter(array_map(
            fn (array $entry): ?string => $entry['manifest']['product_sku'] ?? null,
            $entries,
        )));

        if ($skus === []) {
            return [];
        }

        $definitions = $this->referenceDefinitions();

        if ($definitions === []) {
            return [];
        }

        $products = $this->productRepository->getModel()->newQuery()
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy('sku');
        $mappings = $this->productMappingsForReferences($operationData, $definitions);
        $lines = [];

        foreach ($entries as $entry) {
            $sku = $entry['manifest']['product_sku'] ?? null;
            $product = $products->get($sku);

            if (! $product || empty($entry['product']['id'])) {
                continue;
            }

            $values = $this->scopedValues($product);
            $metafields = $this->buildMetafields($product, $values, $definitions, $mappings);

            if ($metafields !== []) {
                $lines[] = json_encode([
                    'metafields' => array_map(
                        fn (array $metafield): array => array_merge($metafield, ['ownerId' => $entry['product']['id']]),
                        $metafields,
                    ),
                ], JSON_UNESCAPED_SLASHES);
            }
        }

        return $lines;
    }

    protected function getPhaseName(): string
    {
        return 'references';
    }

    protected function getMutationKey(): string
    {
        return 'metafieldsSetReferenceBulk';
    }

    protected function getManifestMutationName(): string
    {
        return 'metafieldsSet';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function referenceDefinitions(): array
    {
        $selectedAttributes = array_filter((array) ($this->manifest['selected_attributes'] ?? []));

        return $this->metaFieldRepository->where('ownerType', 'PRODUCT')->get()->filter(
            fn (object $definition): bool => in_array($definition->type, ['product_reference', 'variant_reference'], true)
                && ($selectedAttributes === [] || in_array($definition->code, $selectedAttributes, true))
        )->map(fn (object $definition): array => $definition->toArray())->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function successfulEntries(array $operationData): array
    {
        $entries = [];

        foreach ($operationData['entries'] ?? [] as $entry) {
            $sku = $entry['manifest']['product_sku'] ?? null;

            if ($sku && empty($entry['user_errors']) && ! empty($entry['product']['id'])) {
                $entries[$sku] = $entry;
            }
        }

        $recreatedSkus = (array) (($this->coreBulkOperation->meta ?? [])['result_summary']['recreated_after_stale_mapping'] ?? []);
        $recreatedGids = $this->productGidsForSkus($recreatedSkus);

        foreach ($operationData['entries'] ?? [] as $entry) {
            $sku = $entry['manifest']['product_sku'] ?? null;

            if (! in_array($sku, $recreatedSkus, true) || ! $recreatedGids->get($sku)) {
                continue;
            }

            $entry['user_errors'] = [];
            $entry['product'] = ['id' => $recreatedGids->get($sku)];
            $entries[$sku] = $entry;
        }

        return array_values($entries);
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @return Collection<string, string>
     */
    protected function productMappingsForReferences(array $operationData, array $definitions): Collection
    {
        $skus = [];

        foreach ($operationData['entries'] ?? [] as $entry) {
            $product = $this->productRepository->findOneByField('sku', $entry['manifest']['product_sku'] ?? '');

            if (! $product) {
                continue;
            }

            foreach ($definitions as $definition) {
                $validation = json_decode($definition['validations'] ?? '[]', true) ?: [];
                $type = $validation['association_type'] ?? 'related_products';
                $skus = array_merge($skus, (array) ($product->values['associations'][$type] ?? []));

                foreach ($this->associationRepository->getLinksForProduct((int) $product->id) as $link) {
                    if ($link->associationType?->code === $type && $link->relatedProduct?->sku) {
                        $skus[] = $link->relatedProduct->sku;
                    }
                }
            }
        }

        $skus = array_values(array_unique(array_filter($skus)));

        if ($skus === []) {
            return collect();
        }

        return $this->mappingRepository
            ->where('entityType', 'product')
            ->where('apiUrl', $this->credential->shopUrl)
            ->whereIn('code', $skus)
            ->get(['code', 'externalId', 'relatedId'])
            ->mapWithKeys(function (object $mapping): array {
                return [$mapping->code => [
                    'product' => $mapping->relatedId ?: (str_contains((string) $mapping->externalId, '/Product/') ? $mapping->externalId : null),
                    'variant' => str_contains((string) $mapping->externalId, '/ProductVariant/') ? $mapping->externalId : null,
                ]];
            });
    }

    protected function scopedValues(object $product): array
    {
        $values = (array) ($product->values ?? []);
        $locale = $this->defaultLocale();
        $channel = $this->manifest['channel'] ?? 'default';

        return array_merge(
            $this->productValueMapper->getCommonFields(['values' => $values]),
            $this->productValueMapper->getLocaleSpecificFields(['values' => $values], $locale),
            $this->productValueMapper->getChannelSpecificFields(['values' => $values], $channel),
            $this->productValueMapper->getChannelLocaleSpecificFields(['values' => $values], $channel, $locale),
        );
    }

    protected function defaultLocale(): string
    {
        foreach ((array) ($this->credential->storeLocales ?? []) as $locale) {
            if (! empty($locale['defaultlocale'])) {
                return $locale['locale'];
            }
        }

        return '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @param  Collection<string, array<string, ?string>>  $mappings
     * @return array<int, array<string, string>>
     */
    protected function buildMetafields(object $product, array $values, array $definitions, Collection $mappings): array
    {
        $metafields = [];

        foreach ($definitions as $definition) {
            $validation = json_decode($definition['validations'] ?? '[]', true) ?: [];
            $associationType = $validation['association_type'] ?? 'related_products';

            $skus = array_merge(
                (array) ($values['associations'][$associationType] ?? []),
                $this->associationSkus((int) $product->id, $associationType),
            );
            $field = ($validation['reference_as'] ?? 'product') === 'variant' ? 'variant' : 'product';
            $gids = collect($skus)->map(fn (string $sku): ?string => $mappings->get($sku)[$field] ?? null)->filter()->unique()->values()->all();

            if ($gids === []) {
                continue;
            }

            [$namespace, $key] = array_pad(explode('.', $definition['name_space_key'] ?? '', 2), 2, '');
            $metafields[] = [
                'namespace' => $namespace,
                'key'       => $key,
                'type'      => ! empty($definition['listvalue']) ? 'list.'.$definition['type'] : $definition['type'],
                'value'     => ! empty($definition['listvalue']) ? json_encode($gids, JSON_UNESCAPED_SLASHES) : $gids[0],
            ];
        }

        return $metafields;
    }

    /**
     * @return array<int, string>
     */
    protected function associationSkus(int $productId, string $associationType): array
    {
        return collect($this->associationRepository->getLinksForProduct($productId))
            ->filter(fn (object $link): bool => $link->associationType?->code === $associationType)
            ->map(fn (object $link): ?string => $link->relatedProduct?->sku)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $skus
     * @return Collection<string, string>
     */
    protected function productGidsForSkus(array $skus): Collection
    {
        if ($skus === []) {
            return collect();
        }

        return $this->mappingRepository
            ->where('entityType', 'product')
            ->where('apiUrl', $this->credential?->shopUrl)
            ->whereIn('code', $skus)
            ->get(['code', 'externalId', 'relatedId'])
            ->mapWithKeys(fn (object $mapping): array => [
                $mapping->code => str_contains((string) $mapping->externalId, '/Product/')
                    ? $mapping->externalId
                    : $mapping->relatedId,
            ])
            ->filter();
    }
}
