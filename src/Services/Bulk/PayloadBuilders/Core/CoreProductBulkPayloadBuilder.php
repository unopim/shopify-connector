<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders\Core;

use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\DataTransfer\Contracts\JobTrack as JobTrackContract;
use Webkul\DataTransfer\Helpers\Export;
use Webkul\Product\Models\Product;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Services\ProductValueMapper;
use Webkul\Product\Services\VariantValueResolver;
use Webkul\Shopify\Exceptions\InvalidCredential;
use Webkul\Shopify\Exceptions\InvalidLocale;
use Webkul\Shopify\Helpers\Exporters\Product\ShopifyGraphQLDataFormatter;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyExportMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectEntryMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectEntryRepository;
use Webkul\Shopify\Services\Bulk\Files\FileReferenceUploader;
use Webkul\Shopify\Services\Bulk\Media\AssetUrlResolver;
use Webkul\Shopify\Services\Bulk\PayloadBuilders\MediaBulkPayloadBuilder;
use Webkul\Shopify\Services\BulkOperationService;
use Webkul\Shopify\Services\ShopifyClientFactory;
use Webkul\Shopify\Traits\ResolvesDamAssetRepository;

class CoreProductBulkPayloadBuilder
{
    use ResolvesDamAssetRepository;

    protected const TRANSLATABLE_METAFIELD_TYPES = ['single_line_text_field', 'multi_line_text_field', 'rich_text_field'];

    protected array $attributesAll = [];

    protected ?string $taxonomyAttributeCode = null;

    protected ?array $metafieldCategoryConstraintsMap = null;

    protected array $fileReferenceMap = [];

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
        protected ShopifyMetaobjectEntryRepository $metaobjectEntryRepository,
        protected ShopifyMetaobjectEntryMappingRepository $metaobjectEntryMappingRepository,
        protected ShopifyClientFactory $clientFactory,
        protected ProductRepository $productRepository,
        protected VariantValueResolver $variantValueResolver,
    ) {}

    protected array $imageMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];

    /** @var array<string, string>|null */
    protected ?array $metaobjectEntryGidMap = null;

    /**
     * In-memory cache for product mappings keyed by SKU.
     *
     * @var array<string, array|null>
     */
    protected array $productMappingsBySkuCache = [];

    /**
     * In-memory cache for category GIDs keyed by category code.
     *
     * @var array<string, string|null>
     */
    protected array $categoryMappingCache = [];

    /**
     * Build JSONL lines and manifest payload for a batch.
     */
    public function build(array $filters, array $batchRows, JobTrackContract $jobTrack): array
    {
        $this->initialize($filters, $jobTrack);
        $jobTrackId = $jobTrack->id;
        $products = $this->fetchProducts($batchRows);
        $groupedProducts = $this->groupProducts($products);

        $allBatchSkus = [];
        foreach ($groupedProducts as $group) {
            if (! empty($group['product_sku'])) {
                $allBatchSkus[] = $group['product_sku'];
            }
            foreach ($group['variants'] ?? [] as $v) {
                if (! empty($v['sku'])) {
                    $allBatchSkus[] = $v['sku'];
                }
            }
        }
        $allBatchSkus = array_values(array_unique(array_filter($allBatchSkus)));

        $this->prewarmProductMappings($allBatchSkus);
        $this->mediaBulkPayloadBuilder->prewarmMediaMappings($allBatchSkus, $this->credential?->shopUrl);

        $candidateMediaGids = $this->mediaBulkPayloadBuilder->getPrewarmedGids($allBatchSkus);
        if ($candidateMediaGids !== []) {
            $this->mediaBulkPayloadBuilder->prewarmMediaGids($candidateMediaGids, $this->credentialAsArray);
        }

        $fileReferenceMap = $this->fileReferenceGidMap($products, $jobTrackId);

        $this->fileReferenceMap = $fileReferenceMap;

        $this->shopifyGraphQLDataFormatter->setFileReferenceMap($fileReferenceMap);

        $lines = [];
        $manifestLines = [];
        $mediaCreated = false;
        $summary = [
            'processed' => count($groupedProducts),
            'created'   => 0,
            'skipped'   => 0,
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
            'lines'               => $lines,
            'metafield_selection' => $metafieldSelection['selection'],
            'manifest'            => $this->bulkMeta($jobTrackId, $mediaCreated, $metafieldSelection, $manifestLines),
            'summary'             => $summary,
            'credential'          => $this->credentialAsArray,
        ];
    }

    /**
     * Collect productSet media files and plan items for a product.
     *
     * @param  array<int, string>  $variantSkus
     * @return array{files: array<int, mixed>, planItems: array<int, mixed>}
     */
    protected function collectProductSetMedia(string $productSku, array $variantSkus): array
    {
        if (! empty($this->credential->extras['saas'])) {
            return ['files' => [], 'planItems' => []];
        }

        $media = $this->mediaBulkPayloadBuilder->collectProductSetFiles(
            $productSku,
            $variantSkus,
            (int) $this->credential->id,
            $this->credential->shopUrl,
            $this->jobChannel ?? 'default',
            $this->currency ?? 'USD',
            $this->credentialAsArray,
        );

        return [
            'files'     => $media['files'] ?? [],
            'planItems' => $media['planItems'] ?? [],
        ];
    }

    /**
     * Pre-upload file_reference values and return assetPath => File GID map.
     *
     * @param  array<int, mixed>  $products
     * @return array<string, string>
     */
    protected function fileReferenceGidMap(array $products, int $jobTrackId): array
    {
        $fileReference = $this->collectFileReferenceValues($products);

        $fileReferenceMap = $this->fileReferenceUploader->buildGidMap(
            $fileReference['values'],
            $this->credentialAsArray,
            $jobTrackId,
        );

        $mediaMappings = $this->shopifyMappingRepository
            ->where('entityType', 'productImage')
            ->where('apiUrl', $this->credential?->shopUrl)
            ->get(['code', 'externalId']);

        $mediaGidsByPath = $mediaMappings->mapWithKeys(function ($mapping): array {
            $parts = explode('|', (string) $mapping->code, 2);

            return [($parts[1] ?? $parts[0]) => $mapping->externalId];
        })->all();

        foreach ($fileReference['aliases'] as $assetId => $path) {
            if (! isset($fileReferenceMap[$path]) && isset($mediaGidsByPath[$path])) {
                $fileReferenceMap[$path] = $mediaGidsByPath[$path];
            }

            if (isset($fileReferenceMap[$path])) {
                $fileReferenceMap[(string) $assetId] = $fileReferenceMap[$path];
            }
        }

        return $fileReferenceMap;
    }

    /**
     * Build manifest line stored alongside core bulk operation.
     *
     * @param  array{selection: string, aliases: array<string, string>}  $metafieldSelection
     * @param  array<int, mixed>  $manifestLines
     * @return array<string, mixed>
     */
    protected function bulkMeta(int $jobTrackId, bool $mediaCreated, array $metafieldSelection, array $manifestLines): array
    {
        return [
            'job_track_id'      => $jobTrackId,
            'shop_url'          => $this->credential?->shopUrl,
            'credential_id'     => $this->credential?->id,
            'credential'        => $this->credentialAsArray,
            'channel'           => $this->jobChannel,
            'currency'          => $this->currency,
            'phase'             => BulkOperationService::CORE_PRODUCT_PHASE,
            'media_created'     => $mediaCreated,
            'metafield_aliases' => $metafieldSelection['aliases'],
            'follow_up_context' => [
                'publishing'      => true,
                'media'           => true,
                'translations'    => count($this->credential?->storelocaleMapping ?? []) > 1,
                'publication_ids' => $this->credential?->extras['salesChannel'] ?? '',
                'references'      => $this->hasDeferredReferenceMetafields(),
            ],
            'lines' => $manifestLines,
        ];
    }

    protected function hasDeferredReferenceMetafields(): bool
    {
        return collect($this->productMetaFieldMapping)->contains(
            fn (array $definition): bool => in_array(
                $definition['type'] ?? null,
                ['product_reference', 'variant_reference'],
                true,
            )
        );
    }

    /**
     * Selections for translatable metafield instance GIDs in productSet.
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
        $this->currency = $filters['currencies'] ?? null;
        $this->jobChannel = $filters['channels'] ?? null;
        $this->credential = $this->shopifyCredentialRepository->find($filters['credentials'] ?? null);

        if (! $this->credential?->active) {
            $jobTrack->state = Export::STATE_FAILED;

            $jobTrack->errors = [trans('shopify::app.shopify.export.errors.invalid-credential')];
            $jobTrack->save();

            throw new InvalidCredential;
        }

        if (! $this->credential->storeLocales) {
            $jobTrack->state = Export::STATE_FAILED;

            $jobTrack->errors = [trans('shopify::app.shopify.export.errors.invalid-locale')];

            $jobTrack->save();

            throw new InvalidLocale;
        }

        $mappings = $this->shopifyExportMappingRepository->findMany([1, 2])->keyBy('id');

        $this->exportMapping = $mappings->get(1);
        $this->settingMapping = $mappings->get(2);
        $this->productMetaFieldMapping = $this->shopifyMetaFieldRepository->where('ownerType', 'PRODUCT')->get()->toArray();
        $this->variantMetaFieldMapping = $this->shopifyMetaFieldRepository->where('ownerType', 'PRODUCTVARIANT')->get()->toArray();
        $this->attributesAll = $this->attributeRepository->all()->keyBy('code')->all();

        $defaultLanguage = array_values(array_filter($this->credential?->storeLocales ?? [], fn (array $language): bool => isset($language['defaultlocale']) && $language['defaultlocale'] === true))[0] ?? null;

        $this->shopifyDefaultLocale = $this->credential?->storelocaleMapping[$defaultLanguage['locale'] ?? ''] ?? null;
        $this->credentialAsArray = $this->credential?->toApiArray() ?? [];

        $this->shopifyGraphQLDataFormatter->setInitialData(
            '',
            $this->currency ?? 'USD',
            $this->settingMapping,
            $this->attributesAll,
            $this->credential?->extras['locationAttributeMappings'] ?? []
        );

        $this->productMappingsBySkuCache = [];
        $this->categoryMappingCache = [];
        $this->mediaBulkPayloadBuilder->resetCaches();
    }

    /**
     * @return array{values: array<int, array{path: string, content_type: string, url: string}>, aliases: array<string, string>}
     */
    protected function collectFileReferenceValues(array $products): array
    {
        $fileSources = $this->collectFileSources();

        if ($fileSources === []) {
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

            foreach ($fileSources as $code => $configuredType) {
                $value = $rawData[$code] ?? null;

                if (empty($value)) {
                    continue;
                }

                $attributeType = $this->attributesAll[$code]?->type ?? null;

                if ($attributeType === 'asset') {
                    foreach ($this->expandAssetFileReferences($value) as $assetId => $entry) {
                        $values[$entry['path']] = $entry;
                        $aliases[(string) $assetId] = $entry['path'];
                    }

                    continue;
                }

                $contentType = $configuredType ?: (in_array($attributeType, ['image', 'gallery'], true) ? 'IMAGE' : 'FILE');

                foreach ((array) $value as $single) {
                    $path = (string) $single;
                    $values[$path] = [
                        'path' => $path,

                        'content_type' => $attributeType === 'gallery'
                            ? $this->pathFileContentType($path)
                            : $contentType,
                        'url' => $this->assetUrlResolver->resolveMedia($path)['url'] ?? '',
                    ];
                }
            }
        }

        return ['values' => array_values($values), 'aliases' => $aliases];
    }

    protected function collectFileSources(): array
    {
        $sources = [];

        foreach (array_merge($this->productMetaFieldMapping, $this->variantMetaFieldMapping) as $def) {
            if (($def['type'] ?? null) !== 'file_reference' || empty($def['code'])) {
                continue;
            }

            $contentType = json_decode($def['validations'] ?? '[]', true)['content_type'] ?? null;
            $sources[$def['code']] = $this->preferContentType($sources[$def['code']] ?? null, $contentType);
        }

        return $sources;
    }

    /**
     * Pick most compatible content type when asset is shared across definitions.
     */
    protected function preferContentType(?string $current, ?string $incoming): ?string
    {
        $rank = ['IMAGE' => 3, 'VIDEO' => 2, 'FILE' => 1];

        return ($rank[$incoming] ?? 0) > ($rank[$current] ?? 0) ? $incoming : $current;
    }

    /**
     * @return array<int|string, array{path: string, content_type: string, url: string}>
     */
    protected function expandAssetFileReferences(mixed $rawValue): array
    {
        $assetRepository = $this->assetRepository();

        if (! is_object($assetRepository)) {
            return [];
        }

        $rawValue = is_array($rawValue) ? implode(',', $rawValue) : (string) $rawValue;
        $ids = array_filter(array_map(trim(...), explode(',', $rawValue)));

        if ($ids === []) {
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
                'path'         => $path,
                'content_type' => $this->assetFileContentType($mime),
                'url'          => $url,
                'asset'        => $asset,
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
     * Map file path to Shopify file content type by extension.
     */
    protected function pathFileContentType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            return 'IMAGE';
        }

        return $extension === 'mp4' ? 'VIDEO' : 'FILE';
    }

    /**
     * Fetch batch products as flat leaf-variant rows.
     */
    protected function fetchProducts(array $batchRows): array
    {
        $skus = array_column($batchRows, 'sku');

        $roots = $this->productRepository->getModel()->newQuery()
            ->whereIn('sku', $skus)
            ->where(static function ($q): void {
                $q->whereNull('parent_id')->orWhere('parent_id', 0);
            })
            ->with(['super_attributes', 'variants.variants'])
            ->get();

        $rows = [];

        foreach ($roots as $root) {
            if ($root->type !== 'configurable') {
                $rows[] = $this->buildLeafRow($root, null);

                continue;
            }

            $parentData = $this->buildParentData(
                $root,
                $this->hydrateSuperAttributes($root->super_attributes->pluck('code')->all())
            );

            foreach ($this->collectLeafVariants($root) as $leaf) {
                $rows[] = $this->buildLeafRow($leaf, $parentData);
            }
        }

        return $rows;
    }

    /**
     * Flatten configurable product to sellable leaf variants.
     *
     * @return array<int, Product>
     */
    protected function collectLeafVariants(Product $root): array
    {
        $leaves = [];

        foreach ($root->variants as $child) {
            if ($child->type === 'variant_group') {
                $child->setRelation('parent', $root);

                foreach ($child->variants as $leaf) {
                    $leaf->setRelation('parent', $child);
                    $leaves[] = $leaf;
                }

                continue;
            }

            $child->setRelation('parent', $root);
            $leaves[] = $child;
        }

        return $leaves;
    }

    /**
     * @param  array<int, array>  $superAttributes
     */
    protected function buildParentData(Product $root, array $superAttributes): array
    {
        return [
            'id'                  => $root->id,
            'sku'                 => $root->sku,
            'type'                => $root->type,
            'status'              => $root->status,
            'values'              => $root->values ?? [],
            'attribute_family_id' => $root->attribute_family_id,
            'super_attributes'    => $superAttributes,
        ];
    }

    protected function buildLeafRow(Product $product, ?array $parentData): array
    {
        return [
            'id'                  => $product->id,
            'sku'                 => $product->sku,
            'type'                => $product->type,
            'parent'              => $parentData,
            'status'              => $product->status,
            'values'              => $this->variantValueResolver->resolve($product),
            'parent_id'           => $product->parent_id,
            'attribute_family_id' => $product->attribute_family_id,
            'additional'          => $product->additional ?? [],
            'super_attributes'    => [],
        ];
    }

    /**
     * Group rows by Shopify product.
     */
    protected function groupProducts(array $products): array
    {
        $grouped = [];

        foreach ($products as $product) {
            $groupSku = $product['parent']['sku'] ?? $product['sku'];

            $grouped[$groupSku] ??= [
                'product_sku' => $groupSku,
                'parent'      => $product['parent'],
                'variants'    => [],
            ];

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
            fn (array $d): bool => ($d['type'] ?? '') === 'collection_reference'
        );

        if ($defs === []) {
            return [];
        }

        $values = $productRow['values'] ?? [];
        $metafields = [];

        foreach ($defs as $def) {
            $gids = [];

            if ($def['type'] === 'collection_reference') {
                $gids = $this->resolveCollectionIds($values['categories'] ?? []);
            }

            $gids = array_values(array_unique(array_filter($gids)));
            if ($gids === []) {
                continue;
            }

            $isList = ! empty($def['listvalue']);
            $nsKey = explode('.', $def['name_space_key']);
            $metafields[] = [
                'namespace' => $nsKey[0],
                'key'       => $nsKey[1],
                'type'      => $isList ? 'list.'.$def['type'] : $def['type'],
                'value'     => $isList ? json_encode($gids, JSON_UNESCAPED_SLASHES) : $gids[0],
            ];
        }

        return $metafields;
    }

    /**
     * Strip reference metafields handled by dedicated builders.
     *
     * @param  array<int, array<string, mixed>>  $mapping
     * @return array<int, array<string, mixed>>
     */
    protected function genericMetafieldMapping(array $mapping): array
    {
        return array_values(array_filter(
            $mapping,
            fn (array $definition): bool => ! in_array(
                $definition['type'] ?? null,
                ['product_reference', 'variant_reference', 'collection_reference', 'metaobject_reference'],
                true
            )
        ));
    }

    /**
     * Preloaded type::code => entry GID map.
     *
     * @return array<string, string>
     */
    protected function metaobjectEntryGidMap(): array
    {
        if ($this->metaobjectEntryGidMap !== null) {
            return $this->metaobjectEntryGidMap;
        }

        $shopUrl = $this->credentialAsArray['shopUrl'] ?? '';
        $gidByEntry = $this->metaobjectEntryMappingRepository->findWhere(['api_url' => $shopUrl])->pluck('gid', 'entry_id');

        $map = [];

        foreach ($this->metaobjectEntryRepository->all(['id', 'type', 'code']) as $entry) {
            if (! empty($gidByEntry[$entry->id])) {
                $map[$entry->type.'::'.$entry->code] = $gidByEntry[$entry->id];
            }
        }

        return $this->metaobjectEntryGidMap = $map;
    }

    /**
     * Point the product's metaobject_reference metafields at their entry GIDs.
     */
    protected function buildMetaobjectMetafields(array $productRow): array
    {
        if (! $this->clientFactory->supportsMetaobject($this->credentialAsArray)) {
            return [];
        }

        $defs = array_filter(
            $this->productMetaFieldMapping,
            fn (array $d): bool => ($d['type'] ?? '') === 'metaobject_reference'
        );

        if ($defs === []) {
            return [];
        }

        $values = $this->getAllAttributeValues($productRow);
        $gidMap = $this->metaobjectEntryGidMap();
        $metafields = [];

        foreach ($defs as $def) {
            $type = json_decode($def['validations'] ?? '[]', true)['metaobject_type'] ?? null;
            $codes = array_filter(array_map(trim(...), explode(',', (string) ($values[$def['code']] ?? ''))));

            if (! $type || $codes === []) {
                continue;
            }

            $gids = [];

            foreach ($codes as $code) {
                if (! empty($gidMap[$type.'::'.$code])) {
                    $gids[] = $gidMap[$type.'::'.$code];
                }
            }

            if ($gids === []) {
                continue;
            }

            $isList = ! empty($def['listvalue']);
            $nsKey = explode('.', $def['name_space_key']);

            $metafields[] = [
                'namespace' => $nsKey[0],
                'key'       => $nsKey[1] ?? '',
                'type'      => $isList ? 'list.metaobject_reference' : 'metaobject_reference',
                'value'     => $isList ? json_encode($gids, JSON_UNESCAPED_SLASHES) : $gids[0],
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
            $this->genericMetafieldMapping($this->productMetaFieldMapping),
            $this->genericMetafieldMapping($this->variantMetaFieldMapping)
        );

        $referenceMetafields = $this->buildReferenceMetafields($parentData ?? $firstVariant, $this->productMetaFieldMapping);
        if ($referenceMetafields !== []) {
            $metafieldBucket = array_key_exists('parentMetaFields', $formattedProduct)
                ? 'parentMetaFields'
                : 'metafields';

            $formattedProduct[$metafieldBucket] = array_merge(
                $formattedProduct[$metafieldBucket] ?? [],
                $referenceMetafields
            );
        }

        $metaobjectMetafields = $this->buildMetaobjectMetafields($parentData ?? $firstVariant);
        if ($metaobjectMetafields !== []) {
            $metafieldBucket = array_key_exists('parentMetaFields', $formattedProduct)
                ? 'parentMetaFields'
                : 'metafields';

            $formattedProduct[$metafieldBucket] = array_merge(
                $formattedProduct[$metafieldBucket] ?? [],
                $metaobjectMetafields
            );
        }

        $productInput = $this->normalizeProductInput($formattedProduct, $productOptions);

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
                $this->genericMetafieldMapping($this->productMetaFieldMapping),
                $this->genericMetafieldMapping($this->variantMetaFieldMapping),
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
                'sku'       => $variantRow['sku'],
                'has_media' => $this->variantHasMedia($variantMergedFields),
                'inventory' => $this->shopifyGraphQLDataFormatter->buildLocationInventory($variantMergedFields),
            ];
        }

        $categoryCodes = array_values(array_unique(array_filter($categoryCodes)));
        $productCollections = $this->resolveCollectionIds($categoryCodes);
        if ($productCollections !== []) {
            $productInput['collections'] = $productCollections;
        }

        $productInput['variants'] = $variants;

        $media = $this->collectProductSetMedia($productSku, array_column($variantManifest, 'sku'));

        if (! empty($media['files'])) {
            $productInput['files'] = $media['files'];
        }

        $mediaPlanItems = $media['planItems'];

        return [
            'variables' => [
                'identifier' => match (true) {
                    (bool) $productIdentifierId      => ['id' => $productIdentifierId],
                    ! empty($productInput['handle']) => ['handle' => $productInput['handle']],
                    default                          => null,
                },
                'input' => $productInput,
            ],
            'manifest' => [
                'product_sku'       => $productSku,
                'product_handle'    => $productInput['handle'] ?? null,
                'variant_skus'      => array_column($variantManifest, 'sku'),
                'variant_inventory' => collect($variantManifest)
                    ->filter(fn ($variant): bool => ! empty($variant['inventory']))
                    ->mapWithKeys(fn ($variant): array => [$variant['sku'] => $variant['inventory']])
                    ->all(),
                'media_plan_items' => $mediaPlanItems,
                'phase_context'    => [
                    'publishing'   => ! empty($this->credential?->extras['salesChannel']),
                    'translations' => count($this->credential?->storelocaleMapping ?? []) > 1,
                    'media'        => collect($variantManifest)->contains('has_media', true),
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
                'id'           => $attribute->id,
                'code'         => $attribute->code,
                'name'         => $attribute->name,
                'type'         => $attribute->type,
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
                'name'     => 'Title',
                'position' => 1,
                'values'   => [[
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

            if ($values === []) {
                continue;
            }

            $options[] = [
                'name'     => $optionName,
                'position' => $index + 1,
                'values'   => array_values($values),
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
                'name'       => 'Default Title',
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
                'name'       => $value,
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
            'title'           => $formattedProduct['title'] ?? null,
            'status'          => $formattedProduct['status'] ?? null,
            'handle'          => $formattedProduct['handle'] ?? null,
            'vendor'          => $formattedProduct['vendor'] ?? null,
            'descriptionHtml' => $formattedProduct['descriptionHtml'] ?? null,
            'productType'     => $formattedProduct['productType'] ?? null,
            'tags'            => $formattedProduct['tags'] ?? null,
            'seo'             => $formattedProduct['seo'] ?? null,
            'metafields'      => $formattedProduct['parentMetaFields'] ?? $formattedProduct['metafields'] ?? null,
        ], fn ($value): bool => ! is_null($value) && $value !== []);

        if ($productOptions !== []) {
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
            'id'              => $variantId,
            'price'           => $variantPayload['price'] ?? null,
            'compareAtPrice'  => $variantPayload['compareAtPrice'] ?? null,
            'barcode'         => $variantPayload['barcode'] ?? null,
            'taxable'         => $variantPayload['taxable'] ?? null,
            'inventoryPolicy' => $variantPayload['inventoryPolicy'] ?? null,
            'metafields'      => $includeVariantMetafields ? ($variantMetafields ?: null) : null,
            'inventoryItem'   => empty($inventoryItem) ? null : $inventoryItem,

            'inventoryQuantities'  => $variantPayload['inventoryQuantities'] ?? null,
            'unitPriceMeasurement' => $variantPayload['unitPriceMeasurement'] ?? null,
            'showUnitPrice'        => $variantPayload['showUnitPrice'] ?? null,
        ], fn ($value): bool => ! is_null($value) && $value !== []);

        $variantInput['optionValues'] = array_values($optionValues);

        return $variantInput;
    }

    /**
     * Validate and return variant GID from mapping.
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

        $translation = array_values(array_filter($attributeMeta['translations'], fn (array $item): bool => $item['locale'] === $this->shopifyDefaultLocale))[0] ?? null;

        return $translation['name'] ?? $attributeMeta['name'] ?? $attributeMeta['code'];
    }

    /**
     * Resolve collection ids for grouped product.
     *
     * @param  array<int, string>  $categoryCodes
     * @return array<int, string>
     */
    protected function resolveCollectionIds(array $categoryCodes): array
    {
        $categoryCodes = array_values(array_unique(array_filter($categoryCodes)));
        if ($categoryCodes === []) {
            return [];
        }

        $needed = array_values(array_filter($categoryCodes, fn ($code): bool => ! array_key_exists($code, $this->categoryMappingCache)));

        if ($needed !== [] && ! empty($this->credential?->shopUrl)) {
            $rows = $this->shopifyMappingRepository
                ->whereIn('code', $needed)
                ->where('entityType', 'category')
                ->where('apiUrl', $this->credential->shopUrl)
                ->get(['code', 'externalId']);

            $mappedCodes = [];
            foreach ($rows as $row) {
                $this->categoryMappingCache[$row->code] = $row->externalId;
                $mappedCodes[$row->code] = true;
            }

            foreach ($needed as $code) {
                if (! isset($mappedCodes[$code])) {
                    $this->categoryMappingCache[$code] = null;
                }
            }
        }

        $gids = [];
        foreach ($categoryCodes as $code) {
            $gid = $this->categoryMappingCache[$code] ?? null;
            if ($gid) {
                $gids[] = $gid;
            }
        }

        return array_values(array_unique($gids));
    }

    protected function taxonomyAttributeCode(): ?string
    {
        if ($this->taxonomyAttributeCode !== null) {
            return $this->taxonomyAttributeCode ?: null;
        }

        $attribute = collect($this->attributesAll)->first(fn ($attribute): bool => $attribute->type === 'shopify_taxonomy');

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
                    fn ($gid): string => substr((string) $gid, strrpos((string) $gid, '/') + 1),
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

        return array_values(array_filter($metafields, function (array $metafield) use ($constraints, $categoryShort): bool {
            $nameSpaceKey = ($metafield['namespace'] ?? '').'.'.($metafield['key'] ?? '');

            if (! isset($constraints[$nameSpaceKey])) {
                return true;
            }

            return $categoryShort !== null && in_array($categoryShort, $constraints[$nameSpaceKey], true);
        }));
    }

    /**
     * Prewarm product mappings for batch SKUs in bulk.
     *
     * @param  array<int, string>  $skus
     */
    protected function prewarmProductMappings(array $skus): void
    {
        if (empty($this->credential?->shopUrl) || $skus === []) {
            return;
        }

        $skusToFetch = array_values(array_filter($skus, fn (string $sku): bool => ! array_key_exists($sku, $this->productMappingsBySkuCache)));

        if ($skusToFetch === []) {
            return;
        }

        foreach (array_chunk($skusToFetch, 500) as $chunk) {
            $mappings = $this->shopifyMappingRepository
                ->whereIn('code', $chunk)
                ->where('entityType', 'product')
                ->where('apiUrl', $this->credential->shopUrl)
                ->get();

            $grouped = [];
            foreach ($mappings as $mapping) {
                $grouped[$mapping->code][] = $mapping->toArray();
            }

            foreach ($chunk as $sku) {
                $this->productMappingsBySkuCache[$sku] = $grouped[$sku] ?? null;
            }
        }
    }

    /**
     * Find a Shopify mapping by SKU.
     */
    protected function findMapping(string $sku): ?array
    {
        if (array_key_exists($sku, $this->productMappingsBySkuCache)) {
            return $this->productMappingsBySkuCache[$sku];
        }

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

        return array_any(explode(',', $mediaMapping), fn ($attributeCode): bool => ! empty($mergedFields[$attributeCode]));
    }
}
