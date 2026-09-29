<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;

class RealtimeController extends Controller
{
    public function __construct(protected ShopifyCredentialRepository $credentialRepository) {}

    public function index(): View
    {
        return view('shopify::realtime.index', ['realtimeSettings' => ['channel' => null, 'currency' => null]]);
    }

    public function credential(int $credentialId): View
    {
        return view('shopify::realtime.credential', [
            'credential' => $this->credentialRepository->findOrFail($credentialId),
            'enabled'    => false,
            'blocker'    => null,
            'blocking'   => false,
        ]);
    }
}
