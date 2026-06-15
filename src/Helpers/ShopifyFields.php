<?php

namespace Webkul\Shopify\Helpers;

class ShopifyFields
{
    /**
     * Shopify Mapping Fields.
     *
     * @var array
     */
    public $mappingFields = [
        [
            'name' => 'title',
            'label' => 'shopify::app.shopify.fields.name',
            'types' => [
                'text',
            ],
            'tooltip' => 'supported attributes types: text',
        ], [
            'name' => 'descriptionHtml',
            'label' => 'shopify::app.shopify.fields.description',
            'types' => [
                'textarea',
                'text',
            ],
            'tooltip' => 'supported attributes types: text, textarea',
        ], [
            'name' => 'price',
            'label' => 'shopify::app.shopify.fields.price',
            'types' => [
                'price',
            ],
            'tooltip' => 'supported attributes types: price',
        ], [
            'name' => 'weight',
            'label' => 'shopify::app.shopify.fields.weight',
            'types' => [
                'number',
                'decimal',
            ],
            'tooltip' => 'supported attributes types: number, metric',
        ], [
            'name' => 'inventoryTracked',
            'label' => 'shopify::app.shopify.fields.inventory_tracked',
            'types' => [
                'boolean',
            ],
            'tooltip' => 'supported attributes types: boolean',
        ], [
            'name' => 'inventoryPolicy',
            'label' => 'shopify::app.shopify.fields.allow_purchase_out_of_stock',
            'types' => [
                'boolean',
            ],
            'tooltip' => 'supported attributes types: yes/no',
        ], [
            'name' => 'vendor',
            'label' => 'shopify::app.shopify.fields.vendor',
            'types' => [
                'text',
                'select',
            ],
            'tooltip' => 'supported attributes types: text, simple select',
        ], [
            'name' => 'productType',
            'label' => 'shopify::app.shopify.fields.product_type',
            'types' => [
                'text',
                'select',
            ],
            'tooltip' => 'supported attributes types: text, simple select',
        ], [
            'name' => 'tags',
            'label' => 'shopify::app.shopify.fields.tags',
            'types' => [
                'text',
                'select',
                'textarea',
                'multiselect',
            ],
            'tooltip' => 'supported attributes types: textarea, text, select, multiselect',
        ], [
            'name' => 'barcode',
            'label' => 'shopify::app.shopify.fields.barcode',
            'types' => [
                'text',
            ],
            'tooltip' => 'supported attributes types: text',

        ], [
            'name' => 'compareAtPrice',
            'label' => 'shopify::app.shopify.fields.compare_at_price',
            'types' => [
                'price',
            ],
            'tooltip' => 'supported attributes types: price',
        ], [
            'name' => 'metafields_global_title_tag',
            'label' => 'shopify::app.shopify.fields.seo_title',
            'types' => [
                'textarea',
            ],
            'tooltip' => 'supported attributes types: text',
        ], [
            'name' => 'metafields_global_description_tag',
            'label' => 'shopify::app.shopify.fields.seo_description',
            'types' => [
                'textarea',
            ],
            'tooltip' => 'supported attributes types: text, textarea',
        ], [
            'name' => 'handle',
            'label' => 'shopify::app.shopify.fields.handle',
            'types' => [
                'text',
            ],
            'tooltip' => 'supported attributes types: text (If multiple products have the same handle, only the last product will be exported.)',
        ], [
            'name' => 'taxable',
            'label' => 'shopify::app.shopify.fields.taxable',
            'types' => [
                'boolean',
            ],
            'tooltip' => 'supported attributes types: yes/no',
        ], [
            'name' => 'cost',
            'label' => 'shopify::app.shopify.fields.inventory_cost',
            'types' => [
                'price',
            ],
            'tooltip' => 'supported attributes types: price',
        ],
    ];

    /**
     * Get Shopify mapping fields.
     *
     * @return array The mapping fields for Shopify.
     */
    public function getMappingField()
    {
        return $this->mappingFields;
    }

    /**
     * Shopify collection mapping fields (category field -> collection field).
     *
     * @var array
     */
    public $collectionMappingFields = [
        [
            'name' => 'title',
            'label' => 'shopify::app.shopify.fields.name',
            'types' => ['text'],
            'tooltip' => 'supported attributes types: text',
        ], [
            'name' => 'descriptionHtml',
            'label' => 'shopify::app.shopify.fields.description',
            'types' => ['textarea', 'text'],
            'tooltip' => 'supported attributes types: text, textarea',
        ], [
            'name' => 'seoTitle',
            'label' => 'shopify::app.shopify.fields.seo_title',
            'types' => ['text', 'textarea'],
            'tooltip' => 'supported attributes types: text, textarea',
        ], [
            'name' => 'seoDescription',
            'label' => 'shopify::app.shopify.fields.seo_description',
            'types' => ['text', 'textarea'],
            'tooltip' => 'supported attributes types: text, textarea',
        ], [
            'name' => 'handle',
            'label' => 'shopify::app.shopify.fields.handle',
            'types' => ['text'],
            'tooltip' => 'supported attributes types: text (falls back to category code if empty)',
        ], [
            'name' => 'collectionType',
            'label' => 'shopify::app.shopify.fields.collection_type',
            'types' => ['boolean'],
            'tooltip' => 'supported attributes types: yes/no. Yes => Smart collection (default rule auto-added); No or unmapped => Manual.',
        ],
    ];

    /**
     * Get Shopify collection mapping fields.
     */
    public function getCollectionMappingField(): array
    {
        return $this->collectionMappingFields;
    }

    /**
     * Shopify collection sort order: enum value => translation key.
     * Single source of truth for the Product Sort dropdown and its validation.
     *
     * @var array<string, string>
     */
    public const COLLECTION_SORT_ORDER_OPTIONS = [
        'MANUAL' => 'shopify::app.shopify.export.mapping.collection.sort_order.options.manual',
        'BEST_SELLING' => 'shopify::app.shopify.export.mapping.collection.sort_order.options.best_selling',
        'ALPHA_ASC' => 'shopify::app.shopify.export.mapping.collection.sort_order.options.alpha_asc',
        'ALPHA_DESC' => 'shopify::app.shopify.export.mapping.collection.sort_order.options.alpha_desc',
        'PRICE_ASC' => 'shopify::app.shopify.export.mapping.collection.sort_order.options.price_asc',
        'PRICE_DESC' => 'shopify::app.shopify.export.mapping.collection.sort_order.options.price_desc',
        'CREATED' => 'shopify::app.shopify.export.mapping.collection.sort_order.options.created',
        'CREATED_DESC' => 'shopify::app.shopify.export.mapping.collection.sort_order.options.created_desc',
    ];

    /**
     * Sort order options shaped for the admin select control (id/name).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function getCollectionSortOrderOptions(): array
    {
        return array_map(
            fn (string $enum, string $labelKey): array => [
                'id' => $enum,
                'name' => trans($labelKey),
            ],
            array_keys(self::COLLECTION_SORT_ORDER_OPTIONS),
            array_values(self::COLLECTION_SORT_ORDER_OPTIONS),
        );
    }

    /**
     * Valid Shopify collection sort order enum values.
     *
     * @return array<int, string>
     */
    public function getCollectionSortOrderValues(): array
    {
        return array_keys(self::COLLECTION_SORT_ORDER_OPTIONS);
    }

    /**
     * Shopify product status options: enum value => translation key.
     * Single source of truth for the export status dropdown. The exported
     * status is one of these enum values; labels are translatable.
     *
     * @var array<string, string>
     */
    public const STATUS_OPTIONS = [
        'ACTIVE' => 'shopify::app.shopify.export.mapping.status.options.active',
        'DRAFT' => 'shopify::app.shopify.export.mapping.status.options.draft',
        'ARCHIVED' => 'shopify::app.shopify.export.mapping.status.options.archived',
        'UNLISTED' => 'shopify::app.shopify.export.mapping.status.options.unlisted',
    ];

    /**
     * Status options shaped for the admin select control (track-by id, label-by name).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function getStatusOptions(): array
    {
        return array_map(
            fn (string $enum, string $labelKey): array => [
                'id' => $enum,
                'name' => trans($labelKey),
            ],
            array_keys(self::STATUS_OPTIONS),
            array_values(self::STATUS_OPTIONS),
        );
    }

    /**
     * Valid Shopify status enum values.
     *
     * @return array<int, string>
     */
    public function getStatusEnumValues(): array
    {
        return array_keys(self::STATUS_OPTIONS);
    }

    /**
     * Shopify unit-price units mapped to their measure group.
     * Single source of truth for the Reference Unit dropdown, the referenceUnit
     * validation, and the export-time quantityUnit validation / measure-type guard.
     *
     * @var array<string, string>
     */
    public const UNIT_PRICE_UNITS = [
        'ML' => 'VOLUME', 'CL' => 'VOLUME', 'L' => 'VOLUME', 'M3' => 'VOLUME',
        'FLOZ' => 'VOLUME', 'PT' => 'VOLUME', 'QT' => 'VOLUME', 'GAL' => 'VOLUME',
        'MG' => 'WEIGHT', 'G' => 'WEIGHT', 'KG' => 'WEIGHT', 'OZ' => 'WEIGHT', 'LB' => 'WEIGHT',
        'MM' => 'LENGTH', 'CM' => 'LENGTH', 'M' => 'LENGTH', 'IN' => 'LENGTH', 'FT' => 'LENGTH', 'YD' => 'LENGTH',
        'FT2' => 'AREA', 'M2' => 'AREA',
        'ITEM' => 'COUNT',
    ];

    /**
     * Valid Shopify unit-price unit enum values.
     *
     * @return array<int, string>
     */
    public function getUnitPriceUnitValues(): array
    {
        return array_keys(self::UNIT_PRICE_UNITS);
    }

    /**
     * Unit options shaped for the admin select control (id = name = enum).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function getUnitPriceUnitOptions(): array
    {
        return array_map(
            fn (string $unit): array => ['id' => $unit, 'name' => $unit],
            array_keys(self::UNIT_PRICE_UNITS),
        );
    }

    /**
     * Measure group ('VOLUME'/'WEIGHT'/'LENGTH'/'AREA'/'COUNT') for a unit, or null.
     */
    public function getUnitPriceMeasure(?string $unit): ?string
    {
        return self::UNIT_PRICE_UNITS[$unit] ?? null;
    }
}
