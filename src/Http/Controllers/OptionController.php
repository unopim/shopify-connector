<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Core\Repositories\ChannelRepository;
use Webkul\Core\Repositories\CurrencyRepository;
use Webkul\Core\Repositories\LocaleRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;

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
    ) {}

    /**
     * Return All credentials
     */
    public function listShopifyCredential(): JsonResponse
    {
        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);
        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];
        $shopifyRepository = $this->shopifyRepository->where('active', 1);

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
                'id'    => $credentialArray['id'],
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
                'id'    => $channel['code'],
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

        $currencyRepository = $this->currencyRepository->where('status', 1);

        if (! empty($searchIdentifiers)) {
            $values = $searchIdentifiers['values'] ?? [];

            $currencyRepository = $currencyRepository->whereIn(
                'code',
                is_array($values) ? $values : [$values]
            );
        }

        $allActivateCurrency = $currencyRepository->get()->toArray();

        $allCurrency = array_map(function ($item) {
            return [
                'id'    => $item['code'],
                'label' => $item['name'],
            ];
        }, $allActivateCurrency);

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
        $searchIdentifiers = isset($queryParams['identifiers']['columnName']) ? $queryParams['identifiers'] : [];
        $localeRepository = $this->localeRepository->where('status', 1);

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
                'id'    => $item['code'],
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
        $page = request()->get('page');
        $query = request()->get('query') ?? '';
        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);

        $attributeRepository = $this->attributeRepository;

        if (! empty($entityName)) {
            $entityName = json_decode($entityName);
            $attributeRepository = in_array('number', $entityName)
                ? $attributeRepository->whereIn('validation', $entityName)
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
        }

        $attributes = $attributeRepository->orderBy('id')->paginate(20, ['*'], 'paginate', $page);

        $formattedoptions = [];

        $currentLocaleCode = core()->getRequestedLocaleCode();

        foreach ($attributes as $attribute) {
            $translatedLabel = $attribute->translate($currentLocaleCode)?->name;

            $formattedoptions[] = [
                'id'    => $attribute->id,
                'code'  => $attribute->code,
                'label' => ! empty($translatedLabel) ? $translatedLabel : "[{$attribute->code}]",
            ];
        }

        return new JsonResponse([
            'options'  => $formattedoptions,
            'page'     => $attributes->currentPage(),
            'lastPage' => $attributes->lastPage(),
        ]);
    }

    /**
     * List image attributes.
     */
    public function listImageAttributes(): JsonResponse
    {
        $query = request()->get('query') ?? '';

        $queryParams = request()->except(['page', 'query', 'entityName', 'attributeId']);

        $attributeRepository = $this->attributeRepository->where('type', 'image');

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
                'id'    => $attribute->id,
                'code'  => $attribute->code,
                'label' => ! empty($translatedLabel) ? $translatedLabel : "[{$attribute->code}]",
            ];
        }

        return new JsonResponse([
            'options' => $formattedoptions,
        ]);
    }
}
