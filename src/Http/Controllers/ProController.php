<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;

/**
 * The screen every Pro mark on the connector leads to: what each edition
 * offers, side by side, and the one way out to the store that sells Pro.
 */
class ProController extends Controller
{
    public function index(): View
    {
        return view('shopify::pro.compare', [
            'groups' => config('shopify.pro.comparison', []),
        ]);
    }
}
