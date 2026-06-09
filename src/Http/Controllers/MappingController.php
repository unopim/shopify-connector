<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shopify\Helpers\ShoifyMetaFieldType;
use Webkul\Shopify\Helpers\ShopifyFields;
use Webkul\Shopify\Http\Requests\ExportMappingForm;
use Webkul\Shopify\Repositories\ShopifyExportMappingRepository;

class MappingController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected ShopifyExportMappingRepository $shopifyExportMappingRepository,
    ) {}

    /**
     * Display Shopify export mappings.
     */
    public function index(): View
    {
        $mappingFields = (new ShopifyFields)->getMappingField();
        $statusOptions = (new ShopifyFields)->getStatusOptions();
        $shopifyMapping = $this->shopifyExportMappingRepository->first();

        $object = (new ShoifyMetaFieldType);
        $metaFieldTypeInShopify = $object->getMetaFieldTypeInShopify();
        $attribute = [];
        $metafieldattrs = [];

        foreach ($shopifyMapping->mapping['shopify_connector_settings'] ?? [] as $row => $value) {
            $attribute[$row] = $value;
        }

        $formattedShopifyMapping = $attribute;
        $metafieldattr = [];

        foreach ($shopifyMapping->mapping['shopify_connector_others'] ?? [] as $row => $value) {
            $metafieldattrs[$row] = $value;
        }

        $formattedOtherMapping = $metafieldattrs;
        $shopifyDefaultMapping = [];

        foreach ($shopifyMapping->mapping['shopify_connector_defaults'] ?? [] as $row => $value) {
            $shopifyDefaultMapping[$row] = $value;
        }

        $mediaMapping = [];
        foreach ($shopifyMapping->mapping['mediaMapping'] ?? [] as $row => $value) {
            $mediaMapping[$row] = $value;
        }

        $unitPriceUnitOptions = (new ShopifyFields)->getUnitPriceUnitOptions();
        $unitPriceMapping = $shopifyMapping->mapping['unit_price'] ?? [];

        return view('shopify::export.mapping.index', compact('mappingFields', 'statusOptions', 'unitPriceUnitOptions', 'unitPriceMapping', 'formattedShopifyMapping', 'shopifyDefaultMapping', 'formattedOtherMapping', 'shopifyMapping', 'mediaMapping', 'metaFieldTypeInShopify'));
    }

    /**
     * Create or update Shopify export mapping.
     *
     * @param  FormRequest  $request
     */
    public function store(ExportMappingForm $request)
    {
        $data = $request->except(['_token', '_method']);
        $filteredData = array_filter($data);
        $mappingFields = [];

        $this->formatMediaMapping($filteredData, $mappingFields);

        $this->formatUnitMapping($filteredData, $mappingFields);

        $this->formatUnitPriceMapping($request, $filteredData, $mappingFields);

        foreach ($filteredData as $row => $value) {

            $sectionName = 'shopify_connector_settings';

            if (str_contains($row, 'default_')) {
                $sectionName = 'shopify_connector_defaults';
                $row = str_replace('default_', '', $row);
            }

            if (str_contains($row, 'meta_fields_')) {
                $sectionName = 'shopify_connector_others';
            }

            $mappingFields[$sectionName][$row] = $value;
        }

        $shopifyMapping = $this->shopifyExportMappingRepository->first();

        if (is_null($shopifyMapping)) {

            session()->flash('error', trans('shopify::app.shopify.export.mapping.save_failed'));

            return redirect()->back();
        }

        if ($shopifyMapping && $shopifyMapping->toArray()['mapping'] != $mappingFields) {
            $shopifyMapping = $this->shopifyExportMappingRepository->update(['mapping' => $mappingFields], 1);
        }

        session()->flash('success', trans('shopify::app.shopify.export.mapping.created'));

        return redirect()->route('admin.shopify.export-mappings', 1);
    }

    public function formatMediaMapping(array &$filteredData, array &$mappingFields)
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

    public function formatUnitMapping(array &$filteredData, array &$mappingFields)
    {
        $mappingFields['unit']['weight'] = $filteredData['weightunit'] ?? null;
        $mappingFields['unit']['volume'] = $filteredData['volumeunit'] ?? null;
        $mappingFields['unit']['dimension'] = $filteredData['dimensionunit'] ?? null;
    }

    /**
     * Extract the Unit Price fields into mapping['unit_price'] and drop them from
     * $filteredData. Read from the request so a falsy show/reference value survives
     * array_filter(). Skipped when both quantity attributes are not set (blank = no-op).
     */
    public function formatUnitPriceMapping(ExportMappingForm $request, array &$filteredData, array &$mappingFields)
    {
        foreach (['unit_price_quantity_value', 'unit_price_quantity_unit', 'unit_price_reference_value', 'unit_price_reference_unit'] as $key) {
            unset($filteredData[$key]);
        }

        $quantityValueAttr = $request->input('unit_price_quantity_value');
        $quantityUnitAttr = $request->input('unit_price_quantity_unit');

        if (empty($quantityValueAttr) || empty($quantityUnitAttr)) {
            return;
        }

        $mappingFields['unit_price'] = [
            'quantityValueAttr' => $quantityValueAttr,
            'quantityUnitAttr' => $quantityUnitAttr,
            'referenceValue' => (int) ($request->input('unit_price_reference_value') ?: 100),
            'referenceUnit' => $request->input('unit_price_reference_unit') ?: 'AUTO',
        ];
    }
}
