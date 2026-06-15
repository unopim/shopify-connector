<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shopify\Models\ShopifyCategoryTaxonomyMapping;
use Webkul\Shopify\Services\Taxonomy\ShopifyTaxonomyLoader;

class CategoryTaxonomyMappingController extends Controller
{
    public function __construct(
        protected ShopifyTaxonomyLoader $taxonomyLoader,
    ) {}

    /**
     * Save category→taxonomy mappings with sync semantics: the posted set is the
     * new truth; rows not present are deleted. Shape: mappings[<category_id>] = <taxonomy_gid>.
     */
    public function store(): JsonResponse
    {
        $mappings = (array) request()->input('mappings', []);

        $incomingIds = array_map('intval', array_keys($mappings));

        if ($incomingIds === []) {
            ShopifyCategoryTaxonomyMapping::query()->delete();
        } else {
            ShopifyCategoryTaxonomyMapping::query()->whereNotIn('unopim_category_id', $incomingIds)->delete();
        }

        foreach ($mappings as $categoryId => $taxonomyId) {
            $taxonomyId = trim((string) $taxonomyId);
            $node = $taxonomyId !== '' ? $this->taxonomyLoader->findById($taxonomyId) : null;

            if ($node === null) {
                ShopifyCategoryTaxonomyMapping::where('unopim_category_id', (int) $categoryId)->delete();

                continue;
            }

            ShopifyCategoryTaxonomyMapping::updateOrCreate(
                ['unopim_category_id' => (int) $categoryId],
                ['taxonomy_id' => $node['id'], 'taxonomy_path' => $node['path']],
            );
        }

        return new JsonResponse([
            'message' => trans('shopify::app.shopify.export.mapping.taxonomy.saved'),
        ]);
    }
}
