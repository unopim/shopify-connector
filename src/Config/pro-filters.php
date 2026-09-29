<?php

use Webkul\DataTransfer\Enums\ProductFilter;

return [
    'shopifyProduct' => [
        'filters' => [
            'fields' => [
                [
                    'name'       => 'locales',
                    'title'      => 'data_transfer::app.exporters.products.filters.locales',
                    'info'       => 'data_transfer::app.exporters.products.filters.locales-info',
                    'required'   => false,
                    'type'       => 'multiselect',
                    'full_width' => true,
                    'async'      => true,
                    'list_route' => 'admin.settings.data_transfer.exports.filters.locales',
                    'track_by'   => 'code',
                    'label_by'   => 'label',
                    'depends_on' => ['field' => 'channels', 'as' => 'channels'],
                ], [
                    'name'       => 'attributes',
                    'title'      => 'data_transfer::app.exporters.products.filters.attributes',
                    'info'       => 'data_transfer::app.exporters.products.filters.attributes-info',
                    'required'   => false,
                    'type'       => 'multiselect',
                    'full_width' => true,
                    'async'      => true,
                    'list_route' => 'admin.settings.data_transfer.exports.filters.attributes',
                    'track_by'   => 'code',
                    'label_by'   => 'label',
                ], [
                    'name'       => 'attribute_families',
                    'title'      => 'data_transfer::app.exporters.products.filters.attribute-families',
                    'required'   => false,
                    'type'       => 'multiselect',
                    'async'      => true,
                    'list_route' => 'admin.settings.data_transfer.exports.filters.attribute_families',
                    'track_by'   => 'code',
                    'label_by'   => 'label',
                ], [
                    'name'       => 'categories',
                    'title'      => 'data_transfer::app.exporters.products.filters.categories',
                    'required'   => false,
                    'type'       => 'multiselect',
                    'full_width' => true,
                    'async'      => true,
                    'list_route' => 'admin.settings.data_transfer.exports.filters.categories',
                    'track_by'   => 'code',
                    'label_by'   => 'label',
                ], [
                    'name'     => 'completeness',
                    'title'    => 'data_transfer::app.exporters.products.filters.completeness',
                    'required' => false,
                    'type'     => 'select',
                    'options'  => [
                        ['label' => 'data_transfer::app.exporters.products.filters.completeness-options.none', 'value' => 'none'],
                        ['label' => 'data_transfer::app.exporters.products.filters.completeness-options.at-least-one', 'value' => 'at_least_one'],
                        ['label' => 'data_transfer::app.exporters.products.filters.completeness-options.all', 'value' => 'all'],
                    ],
                ], [
                    'name'     => 'time_condition',
                    'title'    => 'data_transfer::app.exporters.products.filters.time-condition',
                    'required' => false,
                    'type'     => 'select',
                    'options'  => [
                        ['label' => 'data_transfer::app.exporters.products.filters.time-options.none', 'value' => 'none'],
                        ['label' => 'data_transfer::app.exporters.products.filters.time-options.last-n-days', 'value' => 'last_n_days'],
                        ['label' => 'data_transfer::app.exporters.products.filters.time-options.since-last-export', 'value' => 'since_last_export'],
                        ['label' => 'data_transfer::app.exporters.products.filters.time-options.between-dates', 'value' => 'between_dates'],
                    ],
                ], [
                    'name'         => 'time_value',
                    'title'        => 'data_transfer::app.exporters.products.filters.time-value',
                    'required'     => false,
                    'type'         => 'number',
                    'visible_when' => ['field' => 'time_condition', 'values' => ['last_n_days']],
                ], [
                    'name'         => 'time_date',
                    'title'        => 'data_transfer::app.exporters.products.filters.time-date',
                    'required'     => false,
                    'type'         => 'date',
                    'visible_when' => ['field' => 'time_condition', 'values' => ['between_dates']],
                ], [
                    'name'         => 'time_date_end',
                    'title'        => 'data_transfer::app.exporters.products.filters.time-date-end',
                    'required'     => false,
                    'type'         => 'date',
                    'visible_when' => ['field' => 'time_condition', 'values' => ['between_dates']],
                ], [
                    'name'         => 'custom_attributes',
                    'required'     => false,
                    'type'         => 'attribute-conditions',
                    'full_width'   => true,
                    'async'        => true,
                    'list_route'   => 'admin.settings.data_transfer.exports.filters.attributes',
                    'query_params' => ['exclude' => [ProductFilter::SKU->value]],
                ], [
                    'name'     => 'with_media',
                    'title'    => 'data_transfer::app.exporters.fields.with-media',
                    'required' => false,
                    'type'     => 'boolean',
                ], [
                    'name'     => 'with_associations',
                    'title'    => 'data_transfer::app.exporters.fields.with-associations',
                    'required' => false,
                    'type'     => 'boolean',
                ],
            ],
        ],
    ],

    'shopifyMetaobject' => [
        'filters' => [
            'fields' => [
                [
                    'name'       => 'currencies',
                    'title'      => 'shopify::app.shopify.job.currency',
                    'required'   => true,
                    'validation' => 'required',
                    'type'       => 'select',
                    'async'      => true,
                    'track_by'   => 'id',
                    'label_by'   => 'label',
                    'list_route' => 'shopify.currency.fetch-all',
                ],
            ],
        ],
    ],
];
