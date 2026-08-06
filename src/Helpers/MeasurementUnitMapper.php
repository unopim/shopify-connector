<?php

namespace Webkul\Shopify\Helpers;

use Webkul\Measurement\Models\MeasurementUnit;

class MeasurementUnitMapper
{
    public const WEIGHT = 'weight';

    public const VOLUME = 'volume';

    public const DIMENSION = 'dimension';

    public const UNIT_PRICE = 'unitprice';

    protected static array $targetMaps = [];

    protected static ?array $symbolByFamilyCode = null;

    /**
     * Resolve a stored measurement value to a Shopify [amount, enum] pair.
     */
    public function resolve(string $target, array $value): ?array
    {
        $map = $this->targetMap($target);
        $family = (string) ($value['family'] ?? '');

        $symbol = $value['symbol'] ?? $this->symbolFor($family, (string) ($value['unit'] ?? ''));
        $enum = $map[$this->normalize((string) $symbol)] ?? null;
        if ($enum !== null && isset($value['amount'])) {
            return [(float) $value['amount'], $enum];
        }

        $baseSymbol = $this->symbolFor($family, (string) ($value['base_unit'] ?? ''));
        $enum = $map[$this->normalize($baseSymbol)] ?? null;
        if ($enum !== null && isset($value['base_data'])) {
            return [(float) $value['base_data'], $enum];
        }

        return null;
    }

    /**
     * Reverse a Shopify enum back to a UnoPim unit code within a family.
     */
    public function toUnopim(string $target, string $familyCode, string $enum): ?string
    {
        $symbolNorm = array_search($enum, $this->targetMap($target), true);
        if ($symbolNorm === false) {
            return null;
        }

        foreach ($this->symbolMap() as $key => $symbol) {
            [$family, $code] = explode('|', $key, 2);
            if ($family === $familyCode && $this->normalize($symbol) === $symbolNorm) {
                return $code;
            }
        }

        return null;
    }

    protected function targetMap(string $target): array
    {
        if (isset(self::$targetMaps[$target])) {
            return self::$targetMaps[$target];
        }

        $map = [];
        if ($target === self::UNIT_PRICE) {
            foreach (array_keys(ShopifyFields::UNIT_PRICE_UNITS) as $enum) {
                $map[$this->normalize($enum)] = $enum;
            }
        } else {
            foreach ((new ShoifyMetaFieldType)->getMetaFieldTypeInShopify()[$target]['unitoptions'] ?? [] as $option) {
                $map[$this->normalize((string) $option['name'])] = $option['id'];
            }
        }

        return self::$targetMaps[$target] = $map;
    }

    protected function symbolMap(): array
    {
        if (self::$symbolByFamilyCode !== null) {
            return self::$symbolByFamilyCode;
        }

        $map = [];
        foreach (MeasurementUnit::query()
            ->join('measurement_families', 'measurement_families.id', '=', 'measurement_units.measurement_family_id')
            ->get(['measurement_families.code as family', 'measurement_units.code as code', 'measurement_units.symbol as symbol']) as $row) {
            $map[$row->family.'|'.$row->code] = (string) $row->symbol;
        }

        return self::$symbolByFamilyCode = $map;
    }

    protected function symbolFor(string $family, string $code): string
    {
        return $this->symbolMap()[$family.'|'.$code] ?? '';
    }

    protected function normalize(string $unit): string
    {
        return str_replace([' ', '²', '³'], ['', '2', '3'], strtolower(trim($unit)));
    }
}
