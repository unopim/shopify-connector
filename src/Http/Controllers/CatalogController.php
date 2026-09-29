<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;

/**
 * The catalogs screen as the connector alone can serve it: the tab, and what it
 * offers to a store without Pro. Reading and writing catalogs is Pro's, and the
 * package binds its own controller over this one to do it.
 */
class CatalogController extends Controller
{
    public function __construct(protected ShopifyCredentialRepository $credentialRepository) {}

    public function index(int $credentialId): View
    {
        return view('shopify::catalogs.index', [
            'credential' => $this->credentialRepository->findOrFail($credentialId),
        ]);
    }
}
