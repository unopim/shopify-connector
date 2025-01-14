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
     *
     * @return void
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
        $metafieldattrs = [];

        foreach ($shopifyMapping->mapping['shopify_connector_settings'] ?? [] as $row => $value) {
            $attribute[$row] = $value;
        }

        $formattedShopifyMapping = $attribute;
        $metafieldattr = [];

        return view('shopify::import.mapping.index', compact('mappingFields', 'formattedShopifyMapping', 'shopifyMapping', 'shopifyCredentials'));
    }

    /**
     * Create or update Shopify import mapping.
     */
    public function store(FormRequest $request)
    {
        $filteredData = array_filter($request->except(['_token', '_method']));

        $filteredData = array_filter($filteredData, fn ($key) => ! str_starts_with($key, 'default_'), ARRAY_FILTER_USE_KEY);
        $duplicates = array_filter(array_count_values($filteredData), fn ($count) => $count > 1);
        $duplicateKeys = array_keys(array_filter($filteredData, fn ($value) => isset($duplicates[$value])));

        if (! empty($duplicateKeys)) {
            $duplicateKeys = array_map(function ($value) {
                return 'default_'.$value;
            }, $duplicateKeys);

            $keysAsArray = array_fill_keys($duplicateKeys, 'Duplicate attribute mapping');

            return redirect()->route('admin.shopify.import-mappings', 3)
                ->withErrors($keysAsArray)
                ->withInput();
        }

        $mappingFields = [];

        foreach ($filteredData as $row => $value) {
            $sectionName = 'shopify_connector_settings';
            $mappingFields[$sectionName][$row] = $value;
            $mappingFieldss['mapping'] = $mappingFields;
        }

        $shopifyMapping = $this->shopifyExportMappingRepository->find(3);

        if ($shopifyMapping && $shopifyMapping->toArray()['mapping'] != $mappingFieldss['mapping']) {
            $shopifyMapping = $this->shopifyExportMappingRepository->update($mappingFieldss, 3);
        }

        session()->flash('success', trans('shopify::app.shopify.export.mapping.created'));

        return redirect()->route('admin.shopify.import-mappings', 3);
    }
}
