<?php

namespace Webkul\Shopify\Helpers;

class MetaobjectFieldType
{
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
