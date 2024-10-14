<?php

return [
    'shopifyProduct' => [
        'title'    => 'shopify::app.exporters.shopify.product',
        'exporter' => 'Webkul\Shopify\Helpers\Exporters\Product\Exporter',
        'source'   => 'Webkul\Product\Repositories\ProductRepository',
        'filters'  => [
            'fields' => [
                [
                    'name'       => 'credentials',
                    'title'      => 'Shopify credentials',
                    'required'   => true,
                    'validation' => 'required',
                    'type'       => 'select',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'shopify.credential.fetch-all',
                ], [
                    'name'       => 'channel',
                    'title'      => 'Channel',
                    'required'   => true,
                    'validation' => 'required',
                    'type'       => 'select',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'shopify.channel.fetch-all',
                ], [
                    'name'       => 'currency',
                    'title'      => 'Currency',
                    'required'   => true,
                    'type'       => 'select',
                    'validation' => 'required',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'shopify.currency.fetch-all',
                ], [
                    'name'     => 'productfilter',
                    'title'    => 'Product (SKU)',
                    'required' => false,
                    'type'     => 'textarea',
                ],
            ],
        ],
    ],

    'shopifyCategories' => [
        'title'    => 'shopify::app.exporters.shopify.category',
        'exporter' => 'Webkul\Shopify\Helpers\Exporters\Category\Exporter',
        'source'   => 'Webkul\Category\Repositories\CategoryRepository',
        'filters'  => [
            'fields' => [
                [
                    'name'       => 'credentials',
                    'title'      => 'Shopify credentials',
                    'required'   => true,
                    'validation' => 'required',
                    'type'       => 'select',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'shopify.credential.fetch-all',
                ],
            ],
        ],
    ],
];
