<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\DAM\Repositories\AssetRepository;
use Webkul\DataTransfer\Contracts\JobTrack as JobTrackContract;
use Webkul\DataTransfer\Helpers\Export;
use Webkul\Product\Services\ProductValueMapper;
use Webkul\Shopify\Exceptions\InvalidCredential;
use Webkul\Shopify\Helpers\Exporters\Product\ShopifyGraphQLDataFormatter;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyExportMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;
use Webkul\Shopify\Services\Bulk\Files\FileReferenceUploader;
use Webkul\Shopify\Services\Bulk\Media\AssetUrlResolver;
use Webkul\Shopify\Services\Bulk\PayloadBuilders\MediaBulkPayloadBuilder;
use Webkul\Shopify\Services\BulkOperationService;

class CoreProductBulkPayloadBuilder
{
    protected const TRANSLATABLE_METAFIELD_TYPES = ['single_line_text_field', 'multi_line_text_field', 'rich_text_field'];

    protected array $attributesAll = [];

    protected ?string $taxonomyAttributeCode = null;

    protected ?array $metafieldCategoryConstraintsMap = null;

    protected array $credentialAsArray = [];

    protected mixed $credential = null;

    protected mixed $exportMapping = null;

    protected mixed $settingMapping = null;

    protected array $productMetaFieldMapping = [];

    protected array $variantMetaFieldMapping = [];

    protected ?string $shopifyDefaultLocale = null;

    protected ?string $currency = null;

    protected ?string $jobChannel = null;

    public function __construct(
        protected ShopifyCredentialRepository $shopifyCredentialRepository,
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected ShopifyExportMappingRepository $shopifyExportMappingRepository,
        protected ShopifyMetaFieldRepository $shopifyMetaFieldRepository,
        protected AttributeRepository $attributeRepository,
        protected ShopifyGraphQLDataFormatter $shopifyGraphQLDataFormatter,
        protected ProductValueMapper $productValueMapper,
        protected FileReferenceUploader $fileReferenceUploader,
        protected AssetUrlResolver $assetUrlResolver,
        protected MediaBulkPayloadBuilder $mediaBulkPayloadBuilder,
    ) {}

    protected array $imageMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];

    protected ?AssetRepository $resolvedAssetRepository = null;

    protected bool $assetRepositoryResolved = false;

    protected function assetRepository(): ?AssetRepository
    {
        if (! $this->assetRepositoryResolved) {
            $this->assetRepositoryResolved = true;

            if (class_exists(AssetRepository::class)) {
                try {
                    $this->resolvedAssetRepository = app(AssetRepository::class);
                } catch (\Throwable $e) {
                    $this->resolvedAssetRepository = null;
                }
            }
        }

        return $this->resolvedAssetRepository;
    }

    /**
     * Build JSONL lines and manifest payload for a batch.
     */
    public function build(array $filters, array $batchRows, JobTrackContract $jobTrack): array
    {
        $this->initialize($filters, $jobTrack);
        $jobTrackId = $jobTrack->id;
        $products = $this->fetchProducts($batchRows);
        $groupedProducts = $this->groupProducts($products);

        $fileReference = $this->collectFileReferenceValues($products);

        $fileReferenceMap = $this->fileReferenceUploader->buildGidMap(
            $fileReference['values'],
            $this->credentialAsArray,
            $jobTrackId,
        );

        foreach ($fileReference['aliases'] as $assetId => $path) {
            if (isset($fileReferenceMap[$path])) {
                $fileReferenceMap[(string) $assetId] = $fileReferenceMap[$path];
            }
        }

        $this->shopifyGraphQLDataFormatter->setFileReferenceMap($fileReferenceMap);

        $lines = [];
        $manifestLines = [];
        $mediaCreated = false;
        $summary = [
            'processed' => count($groupedProducts),
            'created' => 0,
            'skipped' => 0,
        ];

        foreach ($groupedProducts as $group) {
            $payload = $this->buildPayloadForGroup($group, $jobTrackId);

            if (! $payload) {
                $summary['skipped']++;

                continue;
            }

            $summary['created']++;
            $lines[] = json_encode($payload['variables'], JSON_UNESCAPED_SLASHES);
            $manifestLines[] = $payload['manifest'];

            if (! empty($payload['manifest']['media_plan_items'])) {
                $mediaCreated = true;
            }
        }

        $metafieldSelection = $this->buildTranslatableMetafieldSelection();

        return [
            'lines' => $lines,
            'metafield_selection' => $metafieldSelection['selection'],
            'manifest' => [
                'job_track_id' => $jobTrackId,
                'shop_url' => $this->credential?->shopUrl,
                'credential_id' => $this->credential?->id,
                'credential' => $this->credentialAsArray,
                'channel' => $this->jobChannel,
                'currency' => $this->currency,
                'phase' => BulkOperationService::CORE_PRODUCT_PHASE,
                'media_created' => $mediaCreated,
                'metafield_aliases' => $metafieldSelection['aliases'],
                'follow_up_context' => [
                    'publishing' => true,
                    'media' => true,
                    'translations' => count($this->credential?->storelocaleMapping ?? []) > 1,
                    'publication_ids' => $this->credential?->extras['salesChannel'] ?? '',
                ],
                'lines' => $manifestLines,
            ],
            'summary' => $summary,
            'credential' => $this->credentialAsArray,
        ];
    }

    /**
     * Aliased singular `metafield(namespace, key){ id }` selections injected into the
     * productSetBulk mutation so each product's translatable metafield instance GIDs
     * return inline in the core result — no separate (SaaS-unsupported) read needed.
     * Only emitted when multiple locales are configured (translations are needed).
     *
     * @return array{selection: string, aliases: array<string, string>}
     */
    protected function buildTranslatableMetafieldSelection(): array
    {
        if (count($this->credential?->storelocaleMapping ?? []) < 2) {
            return ['selection' => '', 'aliases' => []];
        }

        $selections = [];
        $aliases = [];

        foreach ($this->productMetaFieldMapping as $def) {
            if (! empty($def['listvalue']) || ! in_array($def['type'] ?? null, self::TRANSLATABLE_METAFIELD_TYPES, true)) {
                continue;
            }

            $nsKey = explode('.', $def['name_space_key'] ?? '', 2);

            if (count($nsKey) !== 2) {
                continue;
            }

            $alias = 'mf_'.preg_replace('/[^a-zA-Z0-9]/', '_', $def['name_space_key']);
            $aliases[$alias] = $def['name_space_key'];
            $selections[] = sprintf('%s: metafield(namespace: "%s", key: "%s") { id }', $alias, $nsKey[0], $nsKey[1]);
        }

        return ['selection' => implode("\n      ", $selections), 'aliases' => $aliases];
    }

    /**
     * Initialize context for payload generation.
     */
    protected function initialize(array $filters, JobTrackContract $jobTrack): void
    {
        $this->currency = $filters['currency'] ?? null;
        $this->jobChannel = $filters['channel'] ?? null;
        $this->credential = $this->shopifyCredentialRepository->find($filters['credentials'] ?? null);

        if (! $this->credential?->active) {
            $jobTrack->state = Export::STATE_FAILED;

            $jobTrack->errors = [trans('shopify::app.shopify.export.errors.invalid-credential')];
            $jobTrack->save();

            throw new InvalidCredential;
        }

        $mappings = $this->shopifyExportMappingRepository->findMany([1, 2]);

        $this->exportMapping = $mappings->first();
        $this->settingMapping = $mappings->last();
        $this->productMetaFieldMapping = $this->shopifyMetaFieldRepository->where('ownerType', 'PRODUCT')->get()->toArray();
        $this->variantMetaFieldMapping = $this->shopifyMetaFieldRepository->where('ownerType', 'PRODUCTVARIANT')->get()->toArray();
        $this->attributesAll = $this->attributeRepository->all()->keyBy('code')->all();

        $defaultLanguage = array_values(array_filter($this->credential?->storeLocales ?? [], function ($language) {
            return isset($language['defaultlocale']) && $language['defaultlocale'] === true;
        }))[0] ?? null;

        $this->shopifyDefaultLocale = $this->credential?->storelocaleMapping[$defaultLanguage['locale'] ?? ''] ?? null;
        $this->credentialAsArray = $this->credential?->toApiArray() ?? [];

        $this->shopifyGraphQLDataFormatter->setInitialData(
            '',
            $this->currency ?? 'USD',
            $this->settingMapping,
            $this->attributesAll,
            $this->credential?->extras['locationAttributeMappings'] ?? []
        );
    }

    /**
     * @return array{values: array<int, array{path: string, content_type: string, url: string}>, aliases: array<string, string>}
     */
    protected function collectFileReferenceValues(array $products): array
    {
        $fileDefs = array_filter(
            array_merge($this->productMetaFieldMapping, $this->variantMetaFieldMapping),
            fn ($def) => ($def['type'] ?? null) === 'file_reference'
        );

        if (empty($fileDefs)) {
            return ['values' => [], 'aliases' => []];
        }

        $values = [];
        $aliases = [];

        $rows = [];
        $seenParents = [];

        foreach ($products as $product) {
            $rows[] = $product;

            $parent = $product['parent'] ?? null;
            if ($parent && empty($seenParents[$parent['sku']])) {
                $seenParents[$parent['sku']] = true;
                $rows[] = $parent;
            }
        }

        foreach ($rows as $product) {
            $rawData = $this->getAllAttributeValues($product);

            foreach ($fileDefs as $def) {
                $value = $rawData[$def['code']] ?? null;

                if (empty($value)) {
                    continue;
                }

                $attributeType = $this->attributesAll[$def['code']]?->type ?? null;

                if ($attributeType === 'asset') {
                    foreach ($this->expandAssetFileReferences($value) as $assetId => $entry) {
                        $values[$entry['path']] = $entry;
                        $aliases[(string) $assetId] = $entry['path'];
                    }

                    continue;
                }

                $contentType = json_decode($def['validations'] ?? '[]', true)['content_type'] ?? null;
                if (! $contentType) {
                    $contentType = $attributeType === 'image' ? 'IMAGE' : 'FILE';
                }

                foreach ((array) $value as $single) {
                    $path = (string) $single;
                    $values[$path] = [
                        'path' => $path,
                        'content_type' => $contentType,
                        'url' => $this->assetUrlResolver->resolveMedia($path)['url'] ?? '',
                    ];
                }
            }
        }

        return ['values' => array_values($values), 'aliases' => $aliases];
    }

    /**
     * @return array<int|string, array{path: string, content_type: string, url: string}>
     */
    protected function expandAssetFileReferences(mixed $rawValue): array
    {
        $assetRepository = $this->assetRepository();

        if (! $assetRepository) {
            return [];
        }

        $rawValue = is_array($rawValue) ? implode(',', $rawValue) : (string) $rawValue;
        $ids = array_filter(array_map('trim', explode(',', $rawValue)));

        if (empty($ids)) {
            return [];
        }

        $entries = [];

        foreach ($assetRepository->whereIn('id', $ids)->get() as $asset) {
            $asset = is_array($asset) ? $asset : $asset->toArray();
            $path = $asset['path'] ?? null;
            $mime = $asset['mime_type'] ?? null;

            if (empty($path) || empty($mime)) {
                continue;
            }

            $url = $this->assetUrlResolver->resolveAssetUrl($path);

            if ($url === '') {
                continue;
            }

            $entries[$asset['id']] = [
                'path' => $path,
                'content_type' => $this->assetFileContentType($mime),
                'url' => $url,
            ];
        }

        return $entries;
    }

    protected function assetFileContentType(string $mime): string
    {
        if (in_array($mime, $this->imageMimeTypes, true)) {
            return 'IMAGE';
        }

        return $mime === 'video/mp4' ? 'VIDEO' : 'FILE';
    }

    /**
     * Fetch product rows for the current batch.
     */
    protected function fetchProducts(array $batchRows): array
    {
        $skus = array_column($batchRows, 'sku');
        $tablePrefix = DB::getTablePrefix();

        return DB::table('products')
            ->leftJoin('attribute_families as aft', 'products.attribute_family_id', '=', 'aft.id')
            ->leftJoin('products as parent_products', 'products.parent_id', '=', 'parent_products.id')
            ->leftJoin('product_super_attributes as psa', function ($join) {
                $join->on('parent_products.id', '=', 'psa.product_id')
                    ->orOn('products.id', '=', 'psa.product_id');
            })
            ->leftJoin('attributes as attr', 'psa.attribute_id', '=', 'attr.id')
            ->select(
                'products.id',
                'products.sku',
                'products.status',
                'products.type',
                'products.values',
                'products.attribute_family_id',
                'products.additional',
                'aft.code as attribute_family_code',
                'parent_products.id as parent_id',
                'parent_products.sku as parent_sku',
                'parent_products.type as parent_type',
                'parent_products.status as parent_status',
                'parent_products.values as parent_values',
                'parent_products.attribute_family_id as parent_attribute_family_id',
                DB::raw("COALESCE(GROUP_CONCAT(DISTINCT {$tablePrefix}attr.code ORDER BY {$tablePrefix}attr.code ASC SEPARATOR ','), '') as super_attributes")
            )
            ->where(function ($query) use ($skus) {
                $query->whereIn('products.sku', $skus)
                    ->orWhereIn('parent_products.sku', $skus);
            })
            ->where('products.type', '!=', 'configurable')
            ->groupBy('products.id')
            ->get()
            ->map(function ($product) {
                $parent = $product?->parent_values ? [
                    'id' => $product->parent_id,
                    'sku' => $product->parent_sku,
                    'type' => $product->parent_type,
                    'status' => $product->parent_status,
                    'values' => json_decode($product->parent_values, true),
                    'attribute_family_id' => $product->parent_attribute_family_id,
                    'super_attributes' => $this->hydrateSuperAttributes(explode(',', $product->super_attributes)),
                ] : null;

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'type' => $product->type,
                    'parent' => $parent,
                    'status' => $product->status,
                    'values' => json_decode($product->values, true),
                    'parent_id' => $product->parent_id,
                    'attribute_family_id' => $product->attribute_family_id,
                    'additional' => $product->additional ? json_decode($product->additional, true) : [],
                    'super_attributes' => [],
                ];
            })
            ->all();
    }

    /**
     * Group rows by Shopify product.
     */
    protected function groupProducts(array $products): array
    {
        $grouped = [];

        foreach ($products as $product) {
            $groupSku = $product['parent']['sku'] ?? $product['sku'];

            if (! isset($grouped[$groupSku])) {
                $grouped[$groupSku] = [
                    'product_sku' => $groupSku,
                    'parent' => $product['parent'],
                    'variants' => [],
                ];
            }

            $grouped[$groupSku]['variants'][] = $product;
        }

        return array_values($grouped);
    }

    /**
     * @return array<int, array{namespace: string, key: string, type: string, value: string}>
     */
    protected function buildReferenceMetafields(array $productRow, array $metaFieldMapping): array
    {
        $defs = array_filter(
            $metaFieldMapping,
            fn ($d) => in_array($d['type'] ?? '', ['product_reference', 'variant_reference', 'collection_reference'], true)
        );

        if (empty($defs)) {
            return [];
        }

        $values = $productRow['values'] ?? [];
        $metafields = [];

        foreach ($defs as $def) {
            $cfg = json_decode($def['validations'] ?? '[]', true) ?: [];

            if ($def['type'] === 'collection_reference') {
                $gids = $this->resolveCollectionIds($values['categories'] ?? []);
            } else {
                $assocType = $cfg['association_type'] ?? 'related_products';
                $skus = $values['associations'][$assocType] ?? [];
                $field = ($cfg['reference_as'] ?? 'product') === 'variant' ? 'externalId' : 'relatedId';

                $gids = [];
                foreach ($skus as $sku) {
                    $row = ($this->findMapping($sku) ?? [])[0] ?? null;
                    $gid = $row[$field] ?? null;

                    if ($def['type'] === 'variant_reference' && ! $this->resolveVariantGid($gid)) {
                        logger()->warning('Shopify: variant_reference skipped — no variant GID', ['sku' => $sku]);

                        continue;
                    }

                    if ($gid) {
                        $gids[] = $gid;
                    }
                }
            }

            $gids = array_values(array_unique(array_filter($gids)));
            if (empty($gids)) {
                continue;
            }

            $isList = ! empty($def['listvalue']);
            $nsKey = explode('.', $def['name_space_key']);
            $metafields[] = [
                'namespace' => $nsKey[0],
                'key' => $nsKey[1],
                'type' => $isList ? 'list.'.$def['type'] : $def['type'],
                'value' => $isList ? json_encode($gids, JSON_UNESCAPED_SLASHES) : $gids[0],
            ];
        }

        return $metafields;
    }

    /**
     * Build a single productSet payload and manifest line.
     */
    protected function buildPayloadForGroup(array $group, int $jobTrackId): ?array
    {
        $parentData = $group['parent'] ?? null;
        $productSku = $group['product_sku'];
        $productMapping = $this->findMapping($productSku);
        $firstVariant = $group['variants'][0] ?? null;

        if (! $firstVariant) {
            return null;
        }
        $categoryCodes = $parentData ? ($parentData['values']['categories'] ?? []) : [];
        $parentMergedFields = $parentData ? $this->getAllAttributeValues($parentData) : [];
        $productMergedFields = $parentData ? $parentMergedFields : $this->getAllAttributeValues($firstVariant);
        $productOptions = $this->buildProductOptions($parentData, $group['variants']);

        $productIdentifierId = $productMapping[0]['relatedId'] ?? $productMapping[0]['externalId'] ?? null;

        $formattedProduct = $this->shopifyGraphQLDataFormatter->formatDataForGraphql(
            $this->getAllAttributeValues($firstVariant),
            $this->exportMapping->mapping ?? [],
            $this->shopifyDefaultLocale ?? 'en',
            $parentMergedFields,
            $this->productMetaFieldMapping,
            $this->variantMetaFieldMapping
        );

        $referenceMetafields = $this->buildReferenceMetafields($parentData ?? $firstVariant, $this->productMetaFieldMapping);
        if (! empty($referenceMetafields)) {
            $formattedProduct['metafields'] = array_merge(
                $formattedProduct['metafields'] ?? [],
                $referenceMetafields
            );
        }

        $productInput = $this->normalizeProductInput($formattedProduct, $productOptions);
        // Only send a handle when one is mapped; otherwise let Shopify auto-generate it from
        // the title. Slugify so it matches Shopify's stored handle for recreate-by-handle.
        if (! empty($productInput['handle'])) {
            $productInput['handle'] = Str::slug($productInput['handle']);
        }

        $taxonomyCode = $this->taxonomyAttributeCode();
        $productCategory = $taxonomyCode ? ($productMergedFields[$taxonomyCode] ?? '') : '';
        $productCategoryShort = $productCategory !== '' ? substr($productCategory, strrpos($productCategory, '/') + 1) : null;
        if ($productCategory !== '') {
            $productInput['category'] = $productCategory;
        }
        if (! empty($productInput['metafields'])) {
            $productInput['metafields'] = $this->filterMetafieldsByCategory($productInput['metafields'], $productCategoryShort);
        }

        $variantManifest = [];
        $variants = [];

        foreach ($group['variants'] as $variantRow) {
            $categoryCodes = array_merge($variantRow['values']['categories'] ?? [], $categoryCodes);
            $variantMapping = $this->findMapping($variantRow['sku']);
            $variantGid = $this->resolveVariantGid($variantMapping[0]['externalId'] ?? null);
            $variantMergedFields = $this->getAllAttributeValues($variantRow);
            $optionValues = $this->buildVariantOptionValues($parentData, $variantMergedFields);
            $formattedVariant = $this->shopifyGraphQLDataFormatter->formatDataForGraphql(
                $variantMergedFields,
                $this->exportMapping->mapping ?? [],
                $this->shopifyDefaultLocale ?? 'en',
                $parentMergedFields,
                $this->productMetaFieldMapping,
                $this->variantMetaFieldMapping,
                $variantGid !== null
            );

            $variantMetafields = ! empty($parentData) ? ($formattedVariant['metafields'] ?? []) : [];
            if (! empty($parentData)) {
                $variantMetafields = array_merge(
                    $variantMetafields,
                    $this->buildReferenceMetafields($variantRow, $this->variantMetaFieldMapping)
                );
            }

            $variants[] = $this->normalizeVariantInput(
                $formattedVariant['variant'] ?? [],
                $variantMetafields,
                $optionValues,
                $variantGid,
                ! empty($parentData)
            );
            $variantManifest[] = [
                'sku' => $variantRow['sku'],
                'has_media' => $this->variantHasMedia($variantMergedFields),
            ];
        }

        $categoryCodes = array_values(array_unique(array_filter($categoryCodes)));
        $productCollections = $this->resolveCollectionIds($categoryCodes);
        if (! empty($productCollections)) {
            $productInput['collections'] = $productCollections;
        }

        $productInput['variants'] = $variants;

        $mediaPlanItems = [];

        if (empty($this->credential->extras['saas'])) {
            $media = $this->mediaBulkPayloadBuilder->collectProductSetFiles(
                $productSku,
                array_column($variantManifest, 'sku'),
                (int) $this->credential->id,
                $this->credential->shopUrl,
                $this->jobChannel ?? 'default',
                $this->currency ?? 'USD',
                $this->credentialAsArray,
            );

            if (! empty($media['files'])) {
                $productInput['files'] = $media['files'];
            }

            $mediaPlanItems = $media['planItems'];
        }

        return [
            'variables' => [
                'identifier' => match (true) {
                    (bool) $productIdentifierId => ['id' => $productIdentifierId],
                    ! empty($productInput['handle']) => ['handle' => $productInput['handle']],
                    default => null,
                },
                'input' => $productInput,
            ],
            'manifest' => [
                'product_sku' => $productSku,
                'product_handle' => $productInput['handle'] ?? null,
                'variant_skus' => array_column($variantManifest, 'sku'),
                'media_plan_items' => $mediaPlanItems,
                'phase_context' => [
                    'publishing' => ! empty($this->credential?->extras['salesChannel']),
                    'translations' => count($this->credential?->storelocaleMapping ?? []) > 1,
                    'media' => collect($variantManifest)->contains('has_media', true),
                ],
            ],
        ];
    }

    /**
     * Hydrate super attributes from codes.
     */
    protected function hydrateSuperAttributes(array $codes): array
    {
        $superAttributes = [];

        foreach ($codes as $attributeCode) {
            $attribute = $this->attributesAll[$attributeCode] ?? null;

            if (! $attribute) {
                continue;
            }

            $superAttributes[] = [
                'id' => $attribute->id,
                'code' => $attribute->code,
                'name' => $attribute->name,
                'type' => $attribute->type,
                'translations' => $attribute->translations->toArray(),
            ];
        }

        return $superAttributes;
    }

    /**
     * Build product options from configurable attributes.
     */
    protected function buildProductOptions(?array $parentData, array $variants): array
    {
        if (empty($parentData['super_attributes'])) {
            return [[
                'name' => 'Title',
                'position' => 1,
                'values' => [[
                    'name' => 'Default Title',
                ]],
            ]];
        }

        $options = [];

        foreach ($parentData['super_attributes'] as $index => $attributeMeta) {
            if ($index > 2) {
                continue;
            }

            $optionName = $this->resolveOptionName($attributeMeta);
            $values = [];

            foreach ($variants as $variant) {
                $variantValues = $this->getAllAttributeValues($variant);
                $value = $variantValues[$attributeMeta['code']] ?? null;

                if (! $value || isset($values[$value])) {
                    continue;
                }

                $values[$value] = ['name' => $value];
            }

            if (empty($values)) {
                continue;
            }

            $options[] = [
                'name' => $optionName,
                'position' => $index + 1,
                'values' => array_values($values),
            ];
        }

        return $options;
    }

    /**
     * Build option values for a variant row.
     */
    protected function buildVariantOptionValues(?array $parentData, array $variantMergedFields): array
    {
        if (empty($parentData['super_attributes'])) {
            return [[
                'optionName' => 'Title',
                'name' => 'Default Title',
            ]];
        }

        $optionValues = [];

        foreach ($parentData['super_attributes'] as $attributeMeta) {
            $value = $variantMergedFields[$attributeMeta['code']] ?? null;

            if (! $value) {
                continue;
            }

            $optionValues[] = [
                'optionName' => $this->resolveOptionName($attributeMeta),
                'name' => $value,
            ];
        }

        return $optionValues;
    }

    /**
     * Normalize formatter output into ProductSetInput fields.
     */
    protected function normalizeProductInput(array $formattedProduct, array $productOptions): array
    {
        $productInput = array_filter([
            'title' => $formattedProduct['title'] ?? null,
            'status' => $formattedProduct['status'] ?? null,
            'handle' => $formattedProduct['handle'] ?? null,
            'vendor' => $formattedProduct['vendor'] ?? null,
            'descriptionHtml' => $formattedProduct['descriptionHtml'] ?? null,
            'productType' => $formattedProduct['productType'] ?? null,
            'tags' => $formattedProduct['tags'] ?? null,
            'seo' => $formattedProduct['seo'] ?? null,
            'metafields' => $formattedProduct['parentMetaFields'] ?? $formattedProduct['metafields'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== []);

        if (! empty($productOptions)) {
            $productInput['productOptions'] = $productOptions;
        }

        return $productInput;
    }

    /**
     * Normalize formatter output into ProductSet variant input fields.
     */
    protected function normalizeVariantInput(
        array $variantPayload,
        array $variantMetafields,
        array $optionValues,
        ?string $variantId,
        bool $includeVariantMetafields
    ): array {
        $inventoryItem = $variantPayload['inventoryItem'] ?? [];

        $variantInput = array_filter([
            'id' => $variantId,
            'price' => $variantPayload['price'] ?? null,
            'compareAtPrice' => $variantPayload['compareAtPrice'] ?? null,
            'barcode' => $variantPayload['barcode'] ?? null,
            'taxable' => $variantPayload['taxable'] ?? null,
            'inventoryPolicy' => $variantPayload['inventoryPolicy'] ?? null,
            'metafields' => $includeVariantMetafields ? ($variantMetafields ?: null) : null,
            'inventoryItem' => empty($inventoryItem) ? null : $inventoryItem,
            // Inventory quantities are synced inline through productSet; there is
            // no separate inventory phase, so this is the single source of truth.
            'inventoryQuantities' => $variantPayload['inventoryQuantities'] ?? null,
            'unitPriceMeasurement' => $variantPayload['unitPriceMeasurement'] ?? null,
            'showUnitPrice' => $variantPayload['showUnitPrice'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== []);

        // Shopify's productSet bulk input expects optionValues to be present
        // for variant rows, even when the product has no configurable options.
        $variantInput['optionValues'] = array_values($optionValues);

        return $variantInput;
    }

    /**
     * Use a mapping's externalId as a variant id only when it is a real
     * ProductVariant GID.
     *
     * For a simple product the variant SKU equals the product SKU, so
     * findMapping() can return the product mapping whose externalId is a
     * Product GID (this happens for products created by the sequential export
     * path, which records a single product-level mapping). Sending a Product
     * GID as a variant id makes Shopify reject the variant and the product
     * silently fails to update. Returning null instead lets productSet match
     * the existing variant by its option values.
     */
    protected function resolveVariantGid(?string $externalId): ?string
    {
        return is_string($externalId) && str_contains($externalId, '/ProductVariant/')
            ? $externalId
            : null;
    }

    /**
     * Merge current values according to UnoPim product scopes.
     */
    protected function getAllAttributeValues(array $rowData): array
    {
        return array_merge(
            $this->productValueMapper->getCommonFields($rowData),
            ['status' => $rowData['status'] == 1 ? 'true' : 'false'],
            $this->productValueMapper->getLocaleSpecificFields($rowData, $this->shopifyDefaultLocale ?? ''),
            $this->productValueMapper->getChannelSpecificFields($rowData, $this->jobChannel ?? ''),
            $this->productValueMapper->getChannelLocaleSpecificFields($rowData, $this->jobChannel ?? '', $this->shopifyDefaultLocale ?? '')
        );
    }

    /**
     * Resolve configured option labels.
     */
    protected function resolveOptionName(array $attributeMeta): string
    {
        if (! ($this->settingMapping->mapping['option_name_label'] ?? false)) {
            return $attributeMeta['code'];
        }

        $translation = array_values(array_filter($attributeMeta['translations'], function ($item) {
            return $item['locale'] === $this->shopifyDefaultLocale;
        }))[0] ?? null;

        return $translation['name'] ?? $attributeMeta['name'] ?? $attributeMeta['code'];
    }

    /**
     * Resolve collection ids for a grouped product.
     */
    protected function resolveCollectionIds(array $categoryCodes): array
    {
        return $this->shopifyMappingRepository
            ->whereIn('code', $categoryCodes)
            ->where('entityType', 'category')
            ->where('apiUrl', $this->credential?->shopUrl)
            ->pluck('externalId')
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    protected function taxonomyAttributeCode(): ?string
    {
        if ($this->taxonomyAttributeCode !== null) {
            return $this->taxonomyAttributeCode ?: null;
        }

        $attribute = collect($this->attributesAll)->first(fn ($attribute) => $attribute->type === 'shopify_taxonomy');

        return $this->taxonomyAttributeCode = ($attribute->code ?? '');
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function metafieldCategoryConstraints(): array
    {
        if ($this->metafieldCategoryConstraintsMap !== null) {
            return $this->metafieldCategoryConstraintsMap;
        }

        $map = [];

        foreach ($this->productMetaFieldMapping as $definition) {
            $categories = $definition['taxonomy_category'] ?? [];

            if (is_string($categories)) {
                $categories = json_decode($categories, true) ?: [];
            }

            $categories = (array) $categories;

            if ($categories !== [] && ! empty($definition['name_space_key'])) {
                $map[$definition['name_space_key']] = array_map(
                    fn ($gid) => substr((string) $gid, strrpos((string) $gid, '/') + 1),
                    $categories
                );
            }
        }

        return $this->metafieldCategoryConstraintsMap = $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $metafields
     * @return array<int, array<string, mixed>>
     */
    protected function filterMetafieldsByCategory(array $metafields, ?string $categoryShort): array
    {
        $constraints = $this->metafieldCategoryConstraints();

        if ($constraints === []) {
            return $metafields;
        }

        return array_values(array_filter($metafields, function ($metafield) use ($constraints, $categoryShort) {
            $nameSpaceKey = ($metafield['namespace'] ?? '').'.'.($metafield['key'] ?? '');

            if (! isset($constraints[$nameSpaceKey])) {
                return true;
            }

            return $categoryShort !== null && in_array($categoryShort, $constraints[$nameSpaceKey], true);
        }));
    }

    /**
     * Find a Shopify mapping by SKU.
     */
    protected function findMapping(string $sku): ?array
    {
        $mapping = $this->shopifyMappingRepository->where('code', $sku)
            ->where('entityType', 'product')
            ->where('apiUrl', $this->credential?->shopUrl)
            ->get()
            ->toArray();

        return empty($mapping) ? null : $mapping;
    }

    /**
     * Detect whether the variant has media configured in the export mapping.
     */
    protected function variantHasMedia(array $mergedFields): bool
    {
        $mediaMapping = $this->exportMapping->mapping['mediaMapping']['mediaAttributes'] ?? null;

        if (! $mediaMapping) {
            return false;
        }

        foreach (explode(',', $mediaMapping) as $attributeCode) {
            if (! empty($mergedFields[$attributeCode])) {
                return true;
            }
        }

        return false;
    }
}
