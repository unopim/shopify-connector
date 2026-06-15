<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Attribute\Repositories\AttributeFamilyRepository;
use Webkul\Attribute\Repositories\AttributeGroupRepository;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Category\Repositories\CategoryFieldRepository;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Core\Repositories\ChannelRepository;
use Webkul\Core\Repositories\CurrencyRepository;
use Webkul\Core\Repositories\LocaleRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyExportMappingRepository;
use Webkul\Shopify\Services\Taxonomy\ShopifyTaxonomyLoader;

class OptionController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected ShopifyCredentialRepository $shopifyRepository,
        protected AttributeRepository $attributeRepository,
        protected ChannelRepository $channelRepository,
        protected CurrencyRepository $currencyRepository,
        protected LocaleRepository $localeRepository,
        protected AttributeGroupRepository $attributeGroupRepository,
        protected AttributeFamilyRepository $attributeFamilyRepository,
        protected ShopifyExportMappingRepository $shopifyExportMappingRepository,
        protected CategoryFieldRepository $categoryFieldRepository,
        protected CategoryRepository $categoryRepository,
    ) {}

    /**
     * Return All credentials
     */
    public function listShopifyCredential(): JsonResponse
    {
        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);
        $query = request()->get('query') ?? null;
        $shopifyRepo = $this->shopifyRepository;
        if ($query) {
            $shopifyRepo = $shopifyRepo->where('shopUrl', 'LIKE', '%'.$query.'%');
        }

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        $shopifyRepository = $shopifyRepo->where('active', 1);

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $shopifyRepository = $shopifyRepository->whereIn(
                'id',
                is_array($values) ? $values : [$values]
            );
        }

        $allActivateCredntial = $shopifyRepository->get()->toArray();
        $allCredential = [];

        foreach ($allActivateCredntial as $credentialArray) {
            $allCredential[] = [
                'id' => $credentialArray['id'],
                'label' => $credentialArray['shopUrl'],
            ];
        }

        return new JsonResponse([
            'options' => $allCredential,
        ]);
    }

    /**
     * Return All Channels
     */
    public function listChannel(): JsonResponse
    {
        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);
        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        $channelRepository = $this->channelRepository;

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $channelRepository = $channelRepository->whereIn(
                'code',
                is_array($values) ? $values : [$values]
            );
        }

        $allActivateChannel = $channelRepository->get()->toArray();

        $allChannel = [];

        foreach ($allActivateChannel as $channel) {
            $allChannel[] = [
                'id' => $channel['code'],
                'label' => $channel['name'] ?? $channel['code'],
            ];
        }

        return new JsonResponse([
            'options' => $allChannel,
        ]);
    }

    /**
     * Return All Currency
     */
    public function listCurrency(): JsonResponse
    {
        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];
        $selectedChannel = request()->get('channel');

        $currencyRepository = $this->currencyRepository->where('status', 1);

        if (! is_null($selectedChannel) && $selectedChannel !== '') {
            $selectedChannels = is_array($selectedChannel) ? $selectedChannel : [$selectedChannel];

            $currencyRepository = $currencyRepository->whereHas('channel', function ($query) use ($selectedChannels) {
                $query->whereIn('code', $selectedChannels);
            });
        }

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $currencyRepository = $currencyRepository->whereIn(
                'code',
                is_array($values) ? $values : [$values]
            );
        }

        $allCurrency = $currencyRepository->get()->map(function ($item) {
            return [
                'id' => $item->code,
                'label' => $item->name,
            ];
        });

        return new JsonResponse([
            'options' => $allCurrency,
        ]);
    }

    /**
     * Return All Locale
     */
    public function listLocale(): JsonResponse
    {
        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);
        $localeRepository = $this->localeRepository;
        $query = request()->get('query');
        $credentialId = request()->get('credentials');
        $selectedChannel = request()->get('channel');

        $credential = null;

        if (! empty($credentialId)) {
            $credential = $this->shopifyRepository->find($credentialId);
        }

        $mappedLocales = array_values(array_filter((array) ($credential?->storelocaleMapping ?? [])));

        if (! empty($mappedLocales)) {
            $localeRepository = $localeRepository->whereIn('code', $mappedLocales);
        }

        if (request()->has('channel') && empty($selectedChannel)) {
            return new JsonResponse([
                'options' => [],
            ]);
        }

        if (! empty($selectedChannel)) {
            $selectedChannels = is_array($selectedChannel) ? $selectedChannel : [$selectedChannel];

            $localeRepository = $localeRepository->whereHas('channel', function ($query) use ($selectedChannels) {
                $query->whereIn('code', $selectedChannels);
            });
        }

        if ($query) {
            $localeRepository = $localeRepository
                ->where(function ($builder) use ($query) {
                    $builder->where('code', 'LIKE', '%'.$query.'%')
                        ->orWhere('name', 'LIKE', '%'.$query.'%');
                });
        }

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        $localeRepository = $localeRepository->where('status', 1);

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $localeRepository = $localeRepository->whereIn(
                'code',
                is_array($values) ? $values : [$values]
            );
        }

        $allActivateLocale = $localeRepository->get()->toArray();

        $allLocale = array_map(function ($item) {
            return [
                'id' => $item['code'],
                'label' => $item['name'],
            ];
        }, $allActivateLocale);

        return new JsonResponse([
            'options' => $allLocale,
        ]);
    }

    /**
     * List attributes based on entity and query filters.
     */
    public function listAttributes(): JsonResponse
    {
        $entityName = request()->get('entityName');
        $notInclude = request()->get(0) ?? '';
        $fieldName = request()->get(1) ?? '';
        $page = request()->get('page');
        $query = request()->get('query') ?? '';
        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId', 'notInclude']);
        $attributeRepository = $this->attributeRepository;
        if (! empty($entityName)) {
            $entityName = json_decode($entityName);
            $attributeRepository = in_array('number', $entityName)
                ? $attributeRepository->whereIn('validation', $entityName)->where('type', '!=', 'price')
                : $attributeRepository->whereIn('type', $entityName);
        }

        if (! empty($query)) {
            $attributeRepository = $attributeRepository->where('code', 'LIKE', '%'.$query.'%');
        }

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];
            $attributeRepository = $attributeRepository->whereIn(
                $searchIdentifiers['columnName'],
                is_array($values) ? $values : [$values]
            );
            if (! empty($notInclude)) {
                $notIncludeValues = array_values(array_filter(
                    array_diff(array_values($notInclude), is_array($values) ? $values : [$values]),
                    fn ($value) => $value !== null && $value !== ''
                ));

                $attributeRepository = $attributeRepository->whereNotIn('code', $notIncludeValues);
            }
        } else {
            if (! empty($notInclude)) {
                unset($notInclude[$fieldName]);
                $notIncludeValues = array_values(array_filter(
                    array_values($notInclude),
                    fn ($value) => $value !== null && $value !== ''
                ));

                $attributeRepository = $attributeRepository->whereNotIn('code', $notIncludeValues);
            }
        }

        $attributes = $attributeRepository->orderBy('id')->paginate(20, ['*'], 'paginate', $page);

        $formattedoptions = [];

        $currentLocaleCode = core()->getRequestedLocaleCode();

        foreach ($attributes as $attribute) {
            $translatedLabel = $attribute->translate($currentLocaleCode)?->name;
            $formattedoptions[] = [
                'id' => $attribute->id,
                'code' => $attribute->code,
                'type' => $attribute?->type,
                'validation' => $attribute->validation,
                'label' => ! empty($translatedLabel) ? $translatedLabel : "[{$attribute->code}]",
            ];
        }

        return new JsonResponse([
            'options' => $formattedoptions,
            'page' => $attributes->currentPage(),
            'lastPage' => $attributes->lastPage(),
        ]);
    }

    /**
     * List active category fields for collection mapping, type-filtered by entityName.
     */
    public function listCategoryFields(): JsonResponse
    {
        $entityName = request()->get('entityName');
        $query = request()->get('query') ?? '';
        $page = request()->get('page');
        $identifiers = request()->input('identifiers');

        $repository = $this->categoryFieldRepository->where('status', 1);

        if (! empty($entityName)) {
            $types = json_decode($entityName);
            $repository = $repository->whereIn('type', is_array($types) ? $types : [$types]);
        }

        if (! empty($identifiers['columnName']) && isset($identifiers['values'])) {
            $values = $identifiers['values'];
            $repository = $repository->whereIn($identifiers['columnName'], is_array($values) ? $values : [$values]);
        } elseif (! empty($query)) {
            $repository = $repository->where('code', 'LIKE', '%'.$query.'%');
        }

        $fields = $repository->orderBy('id')->paginate(20, ['*'], 'paginate', $page);

        $currentLocaleCode = core()->getRequestedLocaleCode();
        $formattedoptions = [];

        foreach ($fields as $field) {
            $translatedLabel = $field->translate($currentLocaleCode)?->name;
            $formattedoptions[] = [
                'id' => $field->id,
                'code' => $field->code,
                'type' => $field->type,
                'label' => ! empty($translatedLabel) ? $translatedLabel : "[{$field->code}]",
            ];
        }

        return new JsonResponse([
            'options' => $formattedoptions,
            'page' => $fields->currentPage(),
            'lastPage' => $fields->lastPage(),
        ]);
    }

    /**
     * List image attributes.
     */
    public function listImageAttributes(): JsonResponse
    {
        $query = request()->get('query') ?? '';
        $queryParams = request()->except(['page', 'query', 'attributeId']);
        $formattedoptions = [];
        if (isset($queryParams['entityName'])) {
            $attributeRepository = $this->attributeRepository->where('type', $queryParams['entityName']);
        } elseif (isset($queryParams['mediaType'])) {
            $attributeRepository = $this->attributeRepository->whereIn('type', [$queryParams['mediaType'], 'asset']);
        } else {
            $attributeRepository = $this->attributeRepository;
        }
        $currentLocaleCode = core()->getRequestedLocaleCode();

        if (! empty($query)) {
            $attributeRepository = $attributeRepository->where('code', 'LIKE', '%'.$query.'%');
        }

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $attributeRepository = $attributeRepository->whereIn(
                $searchIdentifiers['columnName'],
                is_array($values) ? $values : [$values]
            );
        }

        $attributes = $attributeRepository->get();

        foreach ($attributes as $attribute) {
            $translatedLabel = $attribute->translate($currentLocaleCode)?->name;
            $formattedoptions[] = [
                'id' => $attribute->id,
                'code' => $attribute->code,
                'label' => ! empty($translatedLabel) ? $translatedLabel : "[{$attribute->code}]",
            ];
        }

        return new JsonResponse([
            'options' => $formattedoptions,
        ]);
    }

    /**
     * List Gallery attributes.
     */
    public function listGalleryAttributes(): JsonResponse
    {
        $query = request()->get('query') ?? '';

        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);

        $attributeRepository = $this->attributeRepository->where('type', 'gallery');

        $currentLocaleCode = core()->getRequestedLocaleCode();

        if (! empty($query)) {
            $attributeRepository = $attributeRepository->where('code', 'LIKE', '%'.$query.'%');
        }

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $attributeRepository = $attributeRepository->whereIn(
                $searchIdentifiers['columnName'],
                is_array($values) ? $values : [$values]
            );
        }

        $attributes = $attributeRepository->get();

        $formattedoptions = [];

        foreach ($attributes as $attribute) {
            $translatedLabel = $attribute->translate($currentLocaleCode)?->name;
            $formattedoptions[] = [
                'id' => $attribute->id,
                'code' => $attribute->code,
                'label' => ! empty($translatedLabel) ? $translatedLabel : "[{$attribute->code}]",
            ];
        }

        return new JsonResponse([
            'options' => $formattedoptions,
        ]);
    }

    public function listMetafieldAttributes(): JsonResponse
    {
        $queryParams = request()->except(['page', 'query', 'attributeId']);

        $query = request()->get('query') ?? '';
        $credentialData = $this->shopifyRepository->find($queryParams[0]);
        $metaFieldAttr = array_merge($credentialData?->extras['productMetafield'] ?? [], $credentialData?->extras['productVariantMetafield'] ?? []);

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];
        $entityName = $queryParams['entityName'];
        $attributeRepository = $this->attributeRepository->whereIn('code', $metaFieldAttr);

        if (! empty($entityName)) {
            $entityName = json_decode($entityName);
            $attributeRepository = in_array('number', $entityName)
                ? $attributeRepository->whereIn('validation', $entityName)
                : $attributeRepository->whereIn('type', $entityName);
        }

        if (! empty($query)) {
            $attributeRepository = $attributeRepository->where('code', 'LIKE', '%'.$query.'%');
        }

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $attributeRepository = $attributeRepository->whereIn(
                $searchIdentifiers['columnName'],
                is_array($values) ? $values : [$values]
            );
        }

        $attributes = $attributeRepository->get();

        $formattedoptions = [];
        $currentLocaleCode = core()->getRequestedLocaleCode();
        foreach ($attributes as $attribute) {
            $translatedLabel = $attribute->translate($currentLocaleCode)?->name;
            $formattedoptions[] = [
                'id' => $attribute->id,
                'code' => $attribute->code,
                'label' => ! empty($translatedLabel) ? $translatedLabel : "[{$attribute->code}]",
            ];
        }

        return new JsonResponse([
            'options' => $formattedoptions,
        ]);

    }

    public function selectedMetafieldAttributes(): JsonResponse
    {
        $id = request()->get('id');
        $shopifyMapping = $this->shopifyExportMappingRepository->find(3);
        $formattedoptions = [];

        $metaFieldMappings = $shopifyMapping->mapping['meta_fields'];
        $currentLocaleCode = core()->getRequestedLocaleCode();
        foreach ($metaFieldMappings as $key => $metaFieldMapping) {
            $metaFieldMapping = explode(',', $metaFieldMapping);
            $attributes = $this->attributeRepository->whereIn('code', $metaFieldMapping)->get();
            foreach ($attributes as $attribute) {
                $translatedLabel = $attribute->translate($currentLocaleCode)?->name;

                $formattedoptions[$key][] = [
                    'id' => $attribute->id,
                    'code' => $attribute->code,
                    'label' => ! empty($translatedLabel) ? $translatedLabel : "[{$attribute->code}]",
                ];
            }

        }

        return new JsonResponse($formattedoptions);
    }

    /**
     * List attribute Group.
     */
    public function listAttributeGroup(): JsonResponse
    {
        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);
        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];
        $attributeGroupRepository = $this->attributeGroupRepository;
        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];
            $attributeGroupRepository = $attributeGroupRepository->whereIn(
                'id',
                is_array($values) ? $values : [$values]
            );
        }
        $allAttributegroup = $attributeGroupRepository->get()->toArray();

        $attrGroupList = [];

        $attrGroupList = array_map(function ($item) {
            return [
                'id' => $item['id'],
                'label' => $item['name'] ?? $item['code'],
            ];
        }, $allAttributegroup);

        return new JsonResponse([
            'options' => $attrGroupList,
        ]);
    }

    /**
     * List of family.
     */
    public function listShopifyFamily(): JsonResponse
    {
        $query = request()->get('query') ?? '';

        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);

        $attributeFamilyRepository = $this->attributeFamilyRepository;

        $currentLocaleCode = core()->getRequestedLocaleCode();

        if (! empty($query)) {
            $attributeFamilyRepository = $attributeFamilyRepository->where('code', 'LIKE', '%'.$query.'%');
        }

        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $attributeFamilyRepository = $attributeFamilyRepository->whereIn(
                $searchIdentifiers['columnName'],
                is_array($values) ? $values : [$values]
            );
        }

        $attributesFamilies = $attributeFamilyRepository->get();

        $formattedoptions = [];

        foreach ($attributesFamilies as $attributesFamily) {
            $translatedLabel = $attributesFamily->translate($currentLocaleCode)?->name;
            $formattedoptions[] = [
                'id' => $attributesFamily->id,
                'code' => $attributesFamily->code,
                'label' => ! empty($translatedLabel) ? $translatedLabel : "[{$attributesFamily->code}]",
            ];
        }

        return new JsonResponse([
            'options' => $formattedoptions,
        ]);
    }

    /**
     * Async list of UnoPim categories (with breadcrumb labels) for taxonomy mapping.
     */
    public function listCategories(): JsonResponse
    {
        $query = (string) (request()->get('query') ?? '');
        $page = (int) (request()->get('page') ?: 1);
        $identifiers = request()->input('identifiers');
        $excludeIds = array_filter(array_map('intval', (array) request()->get('exclude_ids', [])));
        $locale = core()->getRequestedLocaleCode();

        $all = $this->categoryRepository->all();
        $byId = $all->keyBy('id');

        $rows = $all
            ->when(! empty($excludeIds), fn ($c) => $c->reject(fn ($cat) => in_array($cat->id, $excludeIds, true)))
            ->when(
                ! empty($identifiers['values']),
                fn ($c) => $c->whereIn('id', array_map('intval', (array) $identifiers['values']))
            )
            ->map(fn ($cat) => [
                'id' => $cat->id,
                'code' => $cat->code,
                'label' => $this->categoryBreadcrumb($cat, $byId, $locale),
            ])
            ->values();

        if (empty($identifiers['values']) && $query !== '') {
            $needle = strtolower($query);
            $rows = $rows->filter(fn ($r) => str_contains(strtolower($r['label']), $needle))->values();
        }

        $perPage = 20;
        $total = $rows->count();
        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return new JsonResponse([
            'options' => $slice->all(),
            'page' => $page,
            'lastPage' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    /**
     * Async list of Shopify taxonomy nodes from the bundled file.
     */
    public function listTaxonomyNodes(ShopifyTaxonomyLoader $loader): JsonResponse
    {
        $query = (string) (request()->get('query') ?? '');
        $page = (int) (request()->get('page') ?: 1);
        $identifiers = request()->input('identifiers');

        $all = collect($loader->all());

        if (! empty($identifiers['values'])) {
            $ids = (array) $identifiers['values'];
            $matched = $all->filter(fn ($e) => in_array($e['id'], $ids, true));
        } elseif ($query !== '') {
            $needle = strtolower($query);
            $matched = $all->filter(fn ($e) => str_contains(strtolower($e['path']), $needle));
        } else {
            $matched = $all;
        }

        $matched = $matched->values();
        $perPage = 50;
        $total = $matched->count();
        $slice = $matched->slice(($page - 1) * $perPage, $perPage)
            ->map(fn ($e) => ['id' => $e['id'], 'code' => $e['id'], 'label' => $e['path']])
            ->values();

        return new JsonResponse([
            'options' => $slice->all(),
            'page' => $page,
            'lastPage' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    /**
     * Build a "Parent > Child > Leaf" breadcrumb for a category (root node excluded).
     */
    protected function categoryBreadcrumb(object $category, $byId, string $locale): string
    {
        $labels = [];
        $cursor = $category;
        $guard = 0;

        while ($cursor && $guard++ < 20) {
            if ($cursor->parent_id !== null) {
                $name = $cursor->additional_data['locale_specific'][$locale]['name'] ?? null;
                $labels[] = ! empty($name) ? $name : "[{$cursor->code}]";
            }

            $cursor = $cursor->parent_id ? $byId->get($cursor->parent_id) : null;
        }

        return implode(' > ', array_reverse($labels)) ?: "[{$category->code}]";
    }
}
