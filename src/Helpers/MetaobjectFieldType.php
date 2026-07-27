<?php

namespace Webkul\Shopify\Helpers;

class MetaobjectFieldType
{
    protected static array $attributeTypeMap = [
        'text' => 'single_line_text_field',
        'textarea' => 'multi_line_text_field',
        'boolean' => 'boolean',
        'select' => 'single_line_text_field',
        'multiselect' => 'single_line_text_field',
        'date' => 'date',
        'datetime' => 'date_time',
        'price' => 'number_decimal',
        'integer' => 'number_integer',
        'decimal' => 'number_decimal',
        'image' => 'file_reference',
        'file' => 'file_reference',
        'asset' => 'file_reference',
    ];

    public static function fromAttributeType(string $attributeType): string
    {
        return self::$attributeTypeMap[$attributeType] ?? 'single_line_text_field';
    }

    /**
     * @return array<string, string>
     */
    public static function supportedTypes(): array
    {
        $keys = [
            'single_line_text_field', 'multi_line_text_field', 'rich_text_field', 'boolean', 'color',
            'number_integer', 'number_decimal', 'date', 'date_time', 'url', 'money', 'json', 'id',
            'rating', 'dimension', 'volume', 'weight', 'link', 'email', 'image', 'file_reference',
            'metaobject_reference',
        ];

        $types = [];

        foreach ($keys as $key) {
            $types[$key] = trans('shopify::app.shopify.metaobject.types.'.$key);
        }

        return $types;
    }
}
