<?php

/**
 * What the two editions offer, as the comparison screen reads it.
 *
 * The single place this is written down: a feature named here is the same
 * feature the badges and notices name elsewhere, because both point at the
 * translation key rather than at a copy of the wording. `ce` says whether the
 * connector alone already ships it; everything else is what Pro adds.
 */
return [
    [
        'title'    => 'shopify::app.shopify.pro.comparison.groups.transfer',
        'features' => [
            ['name' => 'shopify::app.shopify.pro.comparison.features.product', 'ce' => true],
            ['name' => 'shopify::app.shopify.pro.comparison.features.category', 'ce' => true],
            ['name' => 'shopify::app.shopify.pro.comparison.features.metafield', 'ce' => true],
            ['name' => 'shopify::app.shopify.pro.comparison.features.metaobject', 'ce' => true],
            ['name' => 'shopify::app.shopify.pro.comparison.features.attribute-import', 'ce' => true],
        ],
    ], [
        'title'    => 'shopify::app.shopify.pro.comparison.groups.filters',
        'features' => [
            ['name' => 'shopify::app.shopify.pro.comparison.features.base-filters', 'ce' => true],
            ['name' => 'shopify::app.shopify.pro.export-filters', 'note' => 'shopify::app.shopify.pro.filters-note', 'ce' => false],
            ['name' => 'shopify::app.shopify.pro.attribute-conditions', 'note' => 'shopify::app.shopify.pro.conditions-note', 'ce' => false],
        ],
    ], [
        'title'    => 'shopify::app.shopify.pro.comparison.groups.mapping',
        'features' => [
            ['name' => 'shopify::app.shopify.pro.comparison.features.base-mapping', 'ce' => true],
            ['name' => 'shopify::app.shopify.pro.metafield-types', 'note' => 'shopify::app.shopify.pro.types-note', 'ce' => false],
            ['name' => 'shopify::app.shopify.pro.association-mapping', 'note' => 'shopify::app.shopify.pro.association-note', 'ce' => false],
            ['name' => 'shopify::app.shopify.pro.external-media', 'note' => 'shopify::app.shopify.pro.media-note', 'ce' => false],
        ],
    ], [
        'title'    => 'shopify::app.shopify.pro.comparison.groups.automation',
        'features' => [
            ['name' => 'shopify::app.shopify.pro.comparison.features.manual-run', 'ce' => true],
            ['name' => 'shopify::app.shopify.pro.schedule', 'note' => 'shopify::app.shopify.pro.schedule-note', 'ce' => false],
            ['name' => 'shopify::app.shopify.pro.realtime', 'note' => 'shopify::app.shopify.pro.realtime-note', 'ce' => false],
        ],
    ], [
        'title'    => 'shopify::app.shopify.pro.comparison.groups.pricing',
        'features' => [
            ['name' => 'shopify::app.shopify.pro.comparison.features.base-price', 'ce' => true],
            ['name' => 'shopify::app.shopify.pro.comparison.features.catalogs', 'ce' => false],
        ],
    ],
];
