<?php

namespace Webkul\Shopify\Helpers\Exporters\Category;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Webkul\Category\Repositories\CategoryFieldRepository;
use Webkul\DAM\Repositories\AssetRepository;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Helpers\Exporters\AbstractExporter;
use Webkul\DataTransfer\Jobs\Export\File\FlatItemBuffer as FileExportFileBuffer;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\Shopify\Exceptions\InvalidCredential;
use Webkul\Shopify\Exceptions\InvalidLocale;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyExportMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Traits\DataMappingTrait;
use Webkul\Shopify\Traits\ResolvesDamAssetRepository;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;
use Webkul\Shopify\Traits\StagesShopifyAsset;
use Webkul\Shopify\Traits\TranslationTrait;

class Exporter extends AbstractExporter
{
    use DataMappingTrait;
    use ResolvesDamAssetRepository;
    use ShopifyGraphqlRequest;
    use StagesShopifyAsset;
    use TranslationTrait;

    public const BATCH_SIZE = 10;

    public const COLLECTION_NOT_EXIST = 'Collection does not exist';

    public const UNOPIM_ENTITY_NAME = 'category';

    public const UPDATE_PUBLISH_CHANNEL = 'publishablePublish';

    public const UPDATE_UNPUBLISH_CHANNEL = 'unpublishableUnpublish';

    protected $credential;

    protected $credentialArray;

    protected $publicationId = [];

    protected $shopifyDefaultLocale;

    protected $collectionMapping;

    /**
     * Category field rows already read, keyed by code.
     *
     * @var array<string, ?object>
     */
    protected array $categoryFieldCache = [];

    protected bool $exportsFile = false;

    /**
     * Create a new instance of the exporter.
     */
    public function __construct(
        protected JobTrackBatchRepository $exportBatchRepository,
        protected FileExportFileBuffer $exportFileBuffer,
        protected ShopifyCredentialRepository $shopifyRepository,
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected ShopifyExportMappingRepository $shopifyExportMappingRepository,
        protected CategoryFieldRepository $categoryFieldRepository,
    ) {
        parent::__construct($exportBatchRepository, $exportFileBuffer);
    }

    /**
     * Initializes the channels and locales for the export process.
     */
    public function initialize(): void
    {
        $this->initCredential();

        $this->initPublications();

        $this->initDefaultLocale();

        $this->collectionMapping = $this->shopifyExportMappingRepository->find(4);
    }

    /**
     * Initialize credentials data from filters
     */
    protected function initCredential(): void
    {
        $filters = $this->getFilters();

        $this->credential = $this->shopifyRepository->find($filters['credentials']);

        if (! $this->credential?->active) {
            $this->jobLogger->warning(trans('shopify::app.shopify.export.errors.invalid-credential'));

            $this->export->state = ExportHelper::STATE_FAILED;
            $this->export->errors = [trans('shopify::app.shopify.export.errors.invalid-credential')];
            $this->export->save();

            throw new InvalidCredential;
        }

        $this->credentialArray = $this->credential->toApiArray();
    }

    /**
     * Initialize publication from credentials data
     */
    protected function initPublications(): void
    {
        if (empty($this->credential->extras['salesChannel'])) {
            return;
        }

        $salesChannel = explode(',', $this->credential->extras['salesChannel']);

        foreach ($salesChannel as $value) {
            $this->publicationId[] = [
                'publicationId' => $value,
            ];
        }
    }

    /**
     * Initialize default locale from credentials data
     */
    protected function initDefaultLocale(): void
    {
        if ($this->credential->storeLocales) {
            $defaultLanguage = array_values(array_filter($this->credential->storeLocales, fn (array $language): bool => isset($language['defaultlocale']) && $language['defaultlocale'] === true))[0] ?? null;

            $this->shopifyDefaultLocale = $this->credential->storelocaleMapping[$defaultLanguage['locale']] ?? null;
        }

        if (empty($this->shopifyDefaultLocale)) {
            $this->export->state = ExportHelper::STATE_FAILED;

            $this->export->errors = [trans('shopify::app.shopify.export.errors.invalid-locale')];

            $this->export->save();

            throw new InvalidLocale;
        }
    }

    /**
     * Start the export process
     */
    public function exportBatch(JobTrackBatchContract $batch, $filePath): bool
    {
        Event::dispatch('shopify.category.export.before', $batch);

        $this->initialize();

        $this->prepareCategoriesShopify($batch, $filePath);

        $this->updateBatchState($batch->id, ExportHelper::STATE_PROCESSED);

        Event::dispatch('shopify.category.export.after', $batch);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function getResults()
    {
        return $this->source->with('parent_category')->orderBy('id', 'desc')->all()?->getIterator();
    }

    public function prepareCategoriesShopify(JobTrackBatchContract $batch, mixed $filePath): void
    {
        $fieldMap = $this->collectionMapping?->mapping['collection_mapping'] ?? [];

        foreach ($batch->data as $rawData) {
            $mapping = $this->checkMappingInDb($rawData) ?? null;

            $category = $this->buildCollectionPayload($rawData, $fieldMap);

            if (empty($category['title'])) {
                $this->jobLogger->warning(
                    trans('shopify::app.shopify.export.mapping.collection.errors.empty_title', ['code' => $rawData['code'] ?? ''])
                );
                $this->skippedItemsCount++;

                continue;
            }

            if (empty($mapping)) {
                $responseData = $this->apiRequestShopify($category);
                $resultCollection = $responseData['body']['data']['collectionCreate'] ?? [];
                if (! empty($resultCollection['userErrors'])) {
                    $this->logWarning($resultCollection['userErrors'], $rawData['code']);
                    $this->skippedItemsCount++;

                    continue;
                }

                $this->handleAfterApiRequest($rawData, $responseData, $mapping, $this->export->id, $category);

                $this->createdItemsCount++;
            } else {
                $category['id'] = $mapping[0]['externalId'];
                $responseData = $this->apiRequestShopify($category, $category['id']);
                $resultCollection = $responseData['body']['data']['collectionUpdate'] ?? [];
                if (! empty($resultCollection['userErrors'])) {
                    $resultCollection = $this->handleAfterApiRequest($rawData, $responseData, $mapping, $this->export->id, $category);

                    if (! empty($resultCollection['userErrors']) || $resultCollection === []) {
                        $this->skippedItemsCount++;
                        $this->logWarning($resultCollection['userErrors'], $rawData['code']);

                        continue;
                    }
                }

                $this->createdItemsCount++;
            }

            if (empty($resultCollection['userErrors']) && ! empty($this->publicationId)) {
                $this->updateSalesChannel($resultCollection, $this->publicationId);
            }

            $this->categoryTranslation($this->shopifyDefaultLocale, $rawData, $this->credential,
                $this->credentialArray, $resultCollection['collection'] ?? [], $fieldMap);
        }
    }

    /**
     * Update sales channel of the collection
     */
    public function updateSalesChannel($collectionResult, $publicationIds): void
    {
        $collectionId = $collectionResult['collection']['id'];
        $existingPublications = $collectionResult['collection']['resourcePublications']['edges'] ?? [];

        $existingIds = array_map(fn (array $item) => $item['node']['publication']['id'], $existingPublications);
        $newIds = array_column($publicationIds, 'publicationId');
        sort($existingIds);
        sort($newIds);
        if ($existingIds !== $newIds) {
            $this->requestGraphQlApiAction(self::UPDATE_PUBLISH_CHANNEL, $this->credentialArray, [
                'id'    => $collectionId,
                'input' => $publicationIds,
            ]);

            $removePublication = array_values(array_diff($existingIds, $newIds));
            if ($removePublication !== []) {
                $this->requestGraphQlApiAction(self::UPDATE_UNPUBLISH_CHANNEL, $this->credentialArray, [
                    'id'    => $collectionId,
                    'input' => array_map(fn ($id): array => ['publicationId' => $id], $removePublication),
                ]);
            }
        }
    }

    /**
     * log Warning generate
     */
    public function logWarning(array $data, string $code): void
    {
        if ($data !== [] && ! empty($code)) {
            $error = json_encode($data, true);

            $this->jobLogger->warning(
                "Warning for Category with code: {$code}, : {$error}"
            );
        }
    }

    /**
     * Get locale-specific fields from the raw data.
     */
    private function getLocaleSpecificFields(array $data, ?string $locale): array
    {
        if (! is_array($data['additional_data'])) {
            return [];
        }

        if (! array_key_exists('locale_specific', $data['additional_data'])) {
            return [];
        }

        return $data['additional_data']['locale_specific'][$locale] ?? [];
    }

    /**
     * Merge common and locale-specific category field values for a locale.
     */
    private function getMergedFields(array $data, ?string $locale): array
    {
        $additional = $data['additional_data'] ?? [];

        if (! is_array($additional)) {
            return [];
        }

        $common = is_array($additional['common'] ?? null) ? $additional['common'] : [];
        $localeSpecific = is_array($additional['locale_specific'][$locale] ?? null) ? $additional['locale_specific'][$locale] : [];

        return array_merge($common, $localeSpecific);
    }

    /**
     * Build the Shopify CollectionInput payload from the mapping config.
     *
     * @param  array<string, string>  $fieldMap
     */
    private function buildCollectionPayload(array $rawData, array $fieldMap): array
    {
        $merged = $this->getMergedFields($rawData, $this->shopifyDefaultLocale);
        $config = $this->collectionMapping?->mapping ?? [];

        $category = [];

        $titleCode = $fieldMap['title'] ?? null;
        $category['title'] = $titleCode ? ($merged[$titleCode] ?? '') : '';

        foreach (['descriptionHtml' => 'descriptionHtml', 'handle' => 'handle'] as $mapKey => $payloadKey) {
            if (! empty($fieldMap[$mapKey]) && ! empty($merged[$fieldMap[$mapKey]])) {
                $category[$payloadKey] = $merged[$fieldMap[$mapKey]];
            }
        }

        $seo = [];
        foreach (['seoTitle' => 'title', 'seoDescription' => 'description'] as $mapKey => $seoKey) {
            if (! empty($fieldMap[$mapKey]) && ! empty($merged[$fieldMap[$mapKey]])) {
                $seo[$seoKey] = $merged[$fieldMap[$mapKey]];
            }
        }
        if ($seo !== []) {
            $category['seo'] = $seo;
        }

        if (! empty($config['sort_order'])) {
            $category['sortOrder'] = $config['sort_order'];
        }

        if (! empty($category['title']) && $this->isSmartCollection($fieldMap, $merged)) {
            $category['ruleSet'] = $this->defaultSmartRuleSet($category['title']);
        }

        $imageUrl = $this->resolveCollectionImageUrl($config, $merged, $rawData['code'] ?? '');
        if (! empty($imageUrl)) {
            $category['image'] = ['src' => $imageUrl];
        }

        return $category;
    }

    /**
     * Resolve whether the mapped collection-type boolean attribute is truthy.
     * True => Smart collection; false or unmapped => Manual.
     *
     * @param  array<string, string>  $fieldMap
     */
    private function isSmartCollection(array $fieldMap, array $merged): bool
    {
        $code = $fieldMap['collectionType'] ?? null;

        if (empty($code)) {
            return false;
        }

        $value = strtolower(trim((string) ($merged[$code] ?? '')));

        return in_array($value, ['1', 'true', 'yes'], true);
    }

    /**
     * Default rule set for a smart collection: products whose title contains the
     * collection title. Used when the type resolves to Smart but no per-rule
     * configuration exists.
     */
    private function defaultSmartRuleSet(string $title): array
    {
        return [
            'appliedDisjunctively' => false,
            'rules'                => [
                [
                    'column'    => 'TITLE',
                    'relation'  => 'CONTAINS',
                    'condition' => $title,
                ],
            ],
        ];
    }

    /**
     * Resolve the mapped collection image attribute to a public URL.
     */
    protected function resolveCollectionImageUrl(array $config, array $merged, string $categoryCode = ''): ?string
    {
        $mediaAttr = $config['mediaMapping']['mediaAttributes'] ?? '';

        if (empty($mediaAttr)) {
            return null;
        }

        $code = is_array($mediaAttr) ? ($mediaAttr[0] ?? '') : trim(explode(',', $mediaAttr)[0]);

        if (empty($code) || empty($merged[$code])) {
            return null;
        }

        $value = $merged[$code];

        if ($this->categoryField($code)?->type === 'asset') {
            return $this->stageCollectionAsset($value, $categoryCode);
        }

        $path = is_array($value) ? ($value[0] ?? '') : (string) $value;

        if (empty($path)) {
            return null;
        }

        $encodedPath = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        $url = Storage::url($encodedPath);

        if (! $this->isPubliclyReachableUrl($url)) {
            $this->jobLogger->warning(
                trans('shopify::app.shopify.export.mapping.collection.errors.image_skipped', ['code' => $categoryCode])
            );

            return null;
        }

        return $url;
    }

    /**
     * Whether Shopify can fetch a URL from the public internet. Loopback,
     * private/reserved IPs and local-only hostnames are not reachable.
     */
    /**
     * Read a category field once per export, since a batch carries several
     * categories and they all map through the same field.
     */
    protected function categoryField(string $code): ?object
    {
        return $this->categoryFieldCache[$code] ??= $this->categoryFieldRepository->findOneByField('code', $code);
    }

    /**
     * Hand Shopify the first image the mapped asset field points at.
     *
     * A Shopify collection carries one image, so the assets beyond the first are
     * reported rather than silently dropped, and an asset that is not an image is
     * reported too: the collection is still worth exporting without a picture.
     * The bytes are staged rather than linked, so an install the internet cannot
     * reach exports its pictures all the same.
     */
    protected function stageCollectionAsset(mixed $value, string $categoryCode): ?string
    {
        $ids = array_values(array_filter(array_map(
            trim(...),
            explode(',', is_array($value) ? implode(',', $value) : (string) $value),
        )));

        if ($ids === [] || ! $this->assetRepository() instanceof AssetRepository) {
            return null;
        }

        $assets = $this->assetRepository()->findWhereIn('id', $ids);

        if ($assets->isEmpty()) {
            $this->jobLogger?->warning(
                trans('shopify::app.shopify.export.mapping.collection.errors.asset_missing', ['code' => $categoryCode])
            );

            return null;
        }

        $images = $assets->filter(fn (object $asset): bool => str_starts_with((string) $asset->mime_type, 'image/'));

        if ($images->isEmpty()) {
            $this->jobLogger?->warning(
                trans('shopify::app.shopify.export.mapping.collection.errors.asset_not_image', ['code' => $categoryCode])
            );

            return null;
        }

        if ($images->count() > 1) {
            $this->jobLogger?->warning(trans('shopify::app.shopify.export.mapping.collection.errors.asset_extra', [
                'code'  => $categoryCode,
                'count' => $images->count() - 1,
            ]));
        }

        $source = $this->stageAssetUpload($images->first()->toArray(), $this->credential->toApiArray());

        if (empty($source)) {
            $this->jobLogger?->warning(
                trans('shopify::app.shopify.export.mapping.collection.errors.asset_failed', ['code' => $categoryCode])
            );
        }

        return $source ?: null;
    }

    private function isPubliclyReachableUrl(?string $url): bool
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        if ($host === 'localhost' || ! str_contains($host, '.')) {
            return false;
        }

        return array_all(['.local', '.localhost', '.test', '.invalid', '.example', '.internal'], fn (string $suffix): bool => ! str_ends_with($host, $suffix));
    }

    /**
     * Make an API request to Shopify to create or update a category.
     */
    public function apiRequestShopify($category, $id = null): array
    {
        $mutationType = $id ? 'updateCollection' : 'createCollection';

        return $this->requestGraphQlApiAction($mutationType, $this->credentialArray, ['input' => $category]);
    }
}
