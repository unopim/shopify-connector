<?php

namespace Webkul\Shopify\Helpers;

/**
 * Maps metaobject measurement units between the UnoPim field-form symbols
 * (mm, kg, fl_oz …) and the enum names Shopify requires (CENTIMETERS …).
 */
class MetaobjectMeasurementUnit
{
    private const MAP = [
        'dimension' => [
            'mm' => 'MILLIMETERS',
            'cm' => 'CENTIMETERS',
            'm'  => 'METERS',
            'in' => 'INCHES',
            'ft' => 'FEET',
            'yd' => 'YARDS',
        ],
        'weight' => [
            'g'  => 'GRAMS',
            'kg' => 'KILOGRAMS',
            'oz' => 'OUNCES',
            'lb' => 'POUNDS',
        ],
        'volume' => [
            'ml'    => 'MILLILITERS',
            'cl'    => 'CENTILITERS',
            'l'     => 'LITERS',
            'm3'    => 'CUBIC_METERS',
            'fl_oz' => 'FLUID_OUNCES',
            'pt'    => 'PINTS',
            'qt'    => 'QUARTS',
            'gal'   => 'GALLONS',
        ],
    ];

    public static function toShopify(string $type, string $symbol): string
    {
        return self::MAP[$type][$symbol] ?? $symbol;
    }

    public static function fromShopify(string $type, string $enum): string
    {
        return array_flip(self::MAP[$type] ?? [])[$enum] ?? $enum;
    }
}
