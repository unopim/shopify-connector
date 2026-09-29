<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shopify\Helpers\ShopifyFields;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyExportMappingRepository;

class ImportMappingController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected ShopifyExportMappingRepository $shopifyExportMappingRepository,
        protected ShopifyCredentialRepository $shopifyCredentialRepository
    ) {}

    /**
     * Display Shopify export mappings.
     */
    public function index(): View
    {
        $mappingFields = (new ShopifyFields)->getMappingField();
        $shopifyMapping = $this->shopifyExportMappingRepository->find(3);
        $shopifyCredentials = $this->shopifyCredentialRepository->all()->toArray();

        $attribute = [];

        foreach ($shopifyMapping->mapping['shopify_connector_settings'] ?? [] as $row => $value) {
            $attribute[$row] = $value;
        }

        $formattedShopifyMapping = $attribute;

        $mediaMapping = [];
        foreach ($shopifyMapping->mapping['mediaMapping'] ?? [] as $row => $value) {
            $mediaMapping[$row] = $value;
        }

        $unitPriceMapping = $shopifyMapping->mapping['unit_price'] ?? [];

        return view('shopify::import.mapping.index', ['mappingFields' => $mappingFields, 'formattedShopifyMapping' => $formattedShopifyMapping, 'shopifyMapping' => $shopifyMapping, 'shopifyCredentials' => $shopifyCredentials, 'mediaMapping' => $mediaMapping, 'unitPriceMapping' => $unitPriceMapping]);
    }

    /**
     * Create or update Shopify import mapping.
     */
    public function store(FormRequest $request)
    {
        $filteredData = array_filter($request->except(['_token', '_method']));
        $mappingFields = [];
        $filteredData = array_filter($filteredData, fn ($key): bool => ! str_starts_with($key, 'default_'), ARRAY_FILTER_USE_KEY);
        $mappingFieldss['mapping'] = [];
        $this->formatMediaMapping($filteredData, $mappingFields);
        $this->formatUnitPriceMapping($filteredData, $mappingFields);
        $duplicates = array_filter(array_count_values($filteredData), fn (int $count): bool => $count > 1);
        $duplicateKeys = array_keys(array_filter($filteredData, fn ($value): bool => isset($duplicates[$value])));

        if ($duplicateKeys !== []) {
            $duplicateKeys = array_map(fn ($value): string => 'default_'.$value, $duplicateKeys);

            $keysAsArray = array_fill_keys($duplicateKeys, 'Duplicate attribute mapping');

            $input = $request->except(['_token', '_method']);
            foreach ($duplicateKeys as $duplicateKey) {
                $field = str_replace('default_', '', $duplicateKey);
                $input[$field] = null;
                $input[$duplicateKey] = null;
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => trans('shopify::app.shopify.import.mapping.save_failed'), 'errors' => $keysAsArray], 422);
            }

            return to_route('admin.shopify.import-mappings', 3)
                ->withErrors($keysAsArray)
                ->withInput($input);
        }

        foreach ($filteredData as $row => $value) {
            $sectionName = 'shopify_connector_settings';
            $mappingFields[$sectionName][$row] = $value;
            $mappingFieldss['mapping'] = $mappingFields;
        }

        $shopifyMapping = $this->shopifyExportMappingRepository->find(3);

        if (is_null($shopifyMapping)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => trans('shopify::app.shopify.import.mapping.save_failed'), 'type' => 'error'], 422);
            }

            session()->flash('error', trans('shopify::app.shopify.import.mapping.save_failed'));

            return back();
        }

        if ($shopifyMapping && $shopifyMapping->toArray()['mapping'] != $mappingFieldss['mapping']) {
            $shopifyMapping = $this->shopifyExportMappingRepository->update($mappingFieldss, 3);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => trans('shopify::app.shopify.import.mapping.created')]);
        }

        session()->flash('success', trans('shopify::app.shopify.import.mapping.created'));

        return to_route('admin.shopify.import-mappings', 3);
    }

    public function formatMediaMapping(array &$filteredData, array &$mappingFields): void
    {
        $type = 'mediaType';
        $attributes = 'mediaAttributes';
        $section = 'mediaMapping';

        if (isset($filteredData[$type]) && isset($filteredData[$attributes])) {
            $mappingFields[$section][$type] = $filteredData[$type];
            $mappingFields[$section][$attributes] = $filteredData[$attributes];

            unset($filteredData[$attributes]);
            unset($filteredData[$type]);
        }
    }

    /**
     * Extract the import Unit Price mapping (quantity value/unit attributes only) into
     * mapping['unit_price'] and drop the fields so they never leak into the generic
     * attribute loop. Reference value/unit are export-only and intentionally skipped.
     */
    public function formatUnitPriceMapping(array &$filteredData, array &$mappingFields): void
    {
        $unitPrice = (new ShopifyFields)->buildUnitPriceMapping($filteredData, false);

        foreach (ShopifyFields::UNIT_PRICE_FORM_FIELDS as $key) {
            unset($filteredData[$key]);
        }

        if ($unitPrice) {
            $mappingFields['unit_price'] = $unitPrice;
        }
    }
}
