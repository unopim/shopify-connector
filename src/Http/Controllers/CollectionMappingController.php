<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shopify\Helpers\ShopifyFields;
use Webkul\Shopify\Http\Requests\CollectionMappingForm;
use Webkul\Shopify\Repositories\ShopifyExportMappingRepository;

class CollectionMappingController extends Controller
{
    /**
     * Config row id that stores the collection mapping.
     */
    public const CONFIG_ID = 4;

    public function __construct(
        protected ShopifyExportMappingRepository $shopifyExportMappingRepository,
    ) {}

    /**
     * Display the collection mapping screen.
     */
    public function index(): View
    {
        $shopifyMapping = $this->shopifyExportMappingRepository->find(self::CONFIG_ID);

        return view('shopify::export.mapping.collection', [
            'collectionFields' => (new ShopifyFields)->getCollectionMappingField(),
            'sortOrderOptions' => (new ShopifyFields)->getCollectionSortOrderOptions(),
            'collectionMapping' => $shopifyMapping->mapping['collection_mapping'] ?? [],
            'mediaMapping' => $shopifyMapping->mapping['mediaMapping'] ?? [],
            'sortOrder' => $shopifyMapping->mapping['sort_order'] ?? '',
        ]);
    }

    /**
     * Create or update the collection mapping.
     */
    public function store(CollectionMappingForm $request)
    {
        $data = $request->except(['_token', '_method']);

        $mapping = [];

        if (! empty($data['mediaAttributes'])) {
            $mapping['mediaMapping'] = [
                'mediaType' => 'image',
                'mediaAttributes' => $data['mediaAttributes'],
            ];
        }

        if (! empty($data['sort_order'])) {
            $mapping['sort_order'] = $data['sort_order'];
        }

        unset($data['mediaAttributes'], $data['mediaType'], $data['sort_order']);

        $mapping['collection_mapping'] = array_filter($data);

        $config = $this->shopifyExportMappingRepository->find(self::CONFIG_ID);

        if (is_null($config)) {
            session()->flash('error', trans('shopify::app.shopify.export.mapping.collection.save_failed'));

            return redirect()->back();
        }

        if ($config->mapping != $mapping) {
            $this->shopifyExportMappingRepository->update(['mapping' => $mapping], self::CONFIG_ID);
        }

        session()->flash('success', trans('shopify::app.shopify.export.mapping.collection.created'));

        return redirect()->route('admin.shopify.collection-mappings', self::CONFIG_ID);
    }
}
