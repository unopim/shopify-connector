<?php

namespace Webkul\Shopify\Helpers;

class MetaobjectFieldType
{
    /**
     * Shopify has no email type; email is a single_line_text_field carrying this
     * RE2 regex validation. Used to round-trip the email preset on import/export.
     */
    public const EMAIL_REGEX = '^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$';

    /**
     * @return array<string, string>
     */
    public static function supportedTypes(): array
    {
        $keys = [
            'single_line_text_field', 'multi_line_text_field', 'rich_text_field', 'boolean', 'color',
            'number_integer', 'number_decimal', 'date', 'date_time', 'url', 'money', 'json', 'id',
            'rating', 'dimension', 'volume', 'weight', 'link', 'email', 'choice_list', 'image', 'video',
            'file_reference', 'metaobject_reference', 'product_reference', 'variant_reference',
            'collection_reference',
        ];

        $types = [];

        foreach ($keys as $key) {
            $types[$key] = trans('shopify::app.shopify.metaobject.types.'.$key);
        }

        return $types;
    }
}
