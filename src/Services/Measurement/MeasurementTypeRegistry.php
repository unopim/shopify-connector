<?php

namespace Webkul\Shopify\Services\Measurement;

use Webkul\Shopify\Support\ProFeatures;

class MeasurementTypeRegistry
{
    /** @var array<string, array<int, string>> */
    private const array TYPES = [
        'antenna_gain'             => ['decibels_isotropic', 'decibels_dipole'],
        'area'                     => ['square_centimeters', 'square_feet', 'square_inches', 'square_meters', 'square_yards'],
        'battery_charge_capacity'  => ['milliamp_hours'],
        'battery_energy_capacity'  => ['watt_hours'],
        'capacitance'              => ['picofarads', 'nanofarads', 'microfarads', 'farads'],
        'concentration'            => ['milligrams_per_gram', 'milligrams_per_milliliter'],
        'data_storage_capacity'    => ['bytes', 'kilobytes', 'megabytes', 'gigabytes', 'terabytes'],
        'data_transfer_rate'       => ['bits_per_second', 'kilobits_per_second', 'megabits_per_second', 'gigabits_per_second'],
        'dimension'                => ['millimeters', 'centimeters', 'meters', 'inches', 'feet', 'yards'],
        'display_density'          => ['pixels_per_inch', 'dots_per_inch'],
        'distance'                 => ['kilometers', 'miles'],
        'duration'                 => ['nanoseconds', 'microseconds', 'milliseconds', 'seconds', 'minutes', 'hours', 'days', 'months', 'years'],
        'electric_current'         => ['milliamperes', 'amperes', 'kiloamperes'],
        'electrical_resistance'    => ['ohms', 'kiloohms'],
        'energy'                   => ['joules', 'calories', 'kilojoules', 'kilocalories'],
        'frequency'                => ['hertz', 'kilohertz', 'megahertz', 'gigahertz'],
        'illuminance'              => ['lux', 'foot_candles'],
        'inductance'               => ['microhenries', 'millihenries', 'henries'],
        'luminous_flux'            => ['lumens'],
        'mass_flow_rate'           => ['grams_per_day', 'grams_per_hour', 'grams_per_minute', 'grams_per_second', 'ounces_per_day', 'ounces_per_hour', 'ounces_per_minute', 'ounces_per_second', 'pounds_per_day', 'pounds_per_hour', 'pounds_per_minute', 'pounds_per_second', 'kilograms_per_day', 'kilograms_per_hour', 'kilograms_per_minute', 'kilograms_per_second', 'tons_per_day', 'tons_per_hour', 'tons_per_minute', 'tons_per_second', 'tonnes_per_day', 'tonnes_per_hour', 'tonnes_per_minute', 'tonnes_per_second'],
        'power'                    => ['milliwatts', 'watts', 'horsepower', 'kilowatts'],
        'pressure'                 => ['pounds_per_square_inch', 'bars'],
        'resolution'               => ['pixels', 'megapixels'],
        'rotational_speed'         => ['revolutions_per_minute'],
        'sound_level'              => ['decibels'],
        'speed'                    => ['kilometers_per_hour', 'feet_per_second', 'miles_per_hour', 'meters_per_second'],
        'temperature'              => ['celsius', 'fahrenheit', 'kelvin'],
        'thermal_power'            => ['british_thermal_units_per_hour', 'kilowatts', 'tons_of_refrigeration'],
        'voltage'                  => ['volts'],
        'volume'                   => ['milliliters', 'centiliters', 'liters', 'cubic_meters', 'us_fluid_ounces', 'us_pints', 'us_quarts', 'us_gallons', 'imperial_fluid_ounces', 'imperial_pints', 'imperial_quarts', 'imperial_gallons'],
        'volumetric_flow_rate'     => ['liters_per_hour', 'liters_per_minute', 'liters_per_second', 'gallons_per_hour', 'gallons_per_minute', 'gallons_per_second', 'cubic_feet_per_hour', 'cubic_feet_per_minute', 'cubic_feet_per_second', 'cubic_meters_per_hour', 'cubic_meters_per_minute', 'cubic_meters_per_second'],
        'weight'                   => ['ounces', 'pounds', 'grams', 'kilograms'],
    ];

    /**
     * @return array<string, array<int, string>>
     */
    public function types(): array
    {
        return self::TYPES;
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function metafieldOptions(): array
    {
        $locked = ! resolve(ProFeatures::class)->isInstalled();

        return array_map(
            fn (string $type): array => array_filter([
                'id'          => $type,
                'name'        => $this->optionLabel($type),
                '$isDisabled' => $locked ?: null,
            ], static fn (string|true|null $value): bool => $value !== null),
            array_keys(self::TYPES)
        );
    }

    /**
     * @return array<string, string>
     */
    public function metaobjectTypes(): array
    {
        $types = [];

        foreach (array_keys(self::TYPES) as $type) {
            $types[$type] = $this->optionLabel($type);
        }

        return $types;
    }

    /**
     * @return array<string, array{list: bool, validation: array{min: string, max: string}, unitoptions: array<int, array{id: string, name: string}>}>
     */
    public function definitionMetadata(): array
    {
        $metadata = [];

        foreach (self::TYPES as $type => $units) {
            $metadata[$type] = [
                'list'       => true,
                'validation' => [
                    'min' => trans('shopify::app.metafield.measurement.minimum', ['type' => $this->label($type)]),
                    'max' => trans('shopify::app.metafield.measurement.maximum', ['type' => $this->label($type)]),
                ],
                'unitoptions' => array_map(
                    fn (string $unit): array => ['id' => mb_strtoupper($unit), 'name' => $this->unitLabel($unit)],
                    $units
                ),
            ];
        }

        return $metadata;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function unitAliases(): array
    {
        $aliases = [];

        foreach (self::TYPES as $type => $units) {
            foreach ($units as $unit) {
                $aliases[$type][$unit] = array_values(array_unique([
                    $unit,
                    mb_strtoupper($unit),
                    str_replace('_', ' ', $unit),
                ]));
            }
        }

        return $aliases;
    }

    /**
     * The types a store cannot pick while the Pro package is absent.
     *
     * @return array<int, string>
     */
    public function lockedTypes(): array
    {
        return resolve(ProFeatures::class)->isInstalled() ? [] : array_keys(self::TYPES);
    }

    public function has(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    protected function label(string $type): string
    {
        return trans('shopify::app.metafield.measurement.types.'.$type);
    }

    /**
     * The label as a definition-screen dropdown shows it, marked as a Pro type.
     */
    protected function optionLabel(string $type): string
    {
        return resolve(ProFeatures::class)->optionLabel($this->label($type));
    }

    protected function unitLabel(string $unit): string
    {
        return str_replace('_', ' ', $unit);
    }
}
