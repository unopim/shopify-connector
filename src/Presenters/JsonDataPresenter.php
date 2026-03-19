<?php

namespace Webkul\Shopify\Presenters;

use Webkul\HistoryControl\Presenters\JsonDataPresenter as JsonDataPresenters;

class JsonDataPresenter extends JsonDataPresenters
{
    /**
     * Represents value changes for history tracking.
     *
     * @param  mixed  $oldValues  Old values that will be compared.
     * @param  mixed  $newValues  New values to compare against old values.
     * @param  string  $fieldName  Name of the field being tracked.
     * @return array Normalized array of changes for history tracking.
     */
    public static function representValueForHistory(mixed $oldValues, mixed $newValues, string $fieldName): array
    {
        if ($fieldName === 'mapping') {
            $oldArray = static::decodeToArray($oldValues);
            $newArray = static::decodeToArray($newValues);
            $normalizedData = [];

            $oldArray = static::flattenForHistory($oldArray);
            $newArray = static::flattenForHistory($newArray);

            if (empty($oldArray) && empty($newArray)) {
                return $normalizedData;
            }

            $removed = static::calculateDifference($oldArray, $newArray);
            $updated = static::calculateDifference($newArray, $oldArray);
            static::normalizeValues($removed, 'old', $normalizedData);
            static::normalizeValues($updated, 'new', $normalizedData);

            return $normalizedData;
        }

        if ($fieldName === 'apiUrl') {
            $oldApiUrl = static::extractApiUrl($oldValues);
            $newApiUrl = static::extractApiUrl($newValues);

            if ($oldApiUrl === $newApiUrl) {
                return [];
            }

            $normalizedData = [];

            if ($oldApiUrl !== '') {
                static::normalizeValues(['apiUrl' => $oldApiUrl], 'old', $normalizedData);
            }

            if ($newApiUrl !== '') {
                static::normalizeValues(['apiUrl' => $newApiUrl], 'new', $normalizedData);
            }

            return $normalizedData;
        }

        $oldArray = static::decodeToArray($oldValues);
        $newArray = static::decodeToArray($newValues);
        $normalizedData = [];

        if (static::hasNestedValues($oldArray) || static::hasNestedValues($newArray)) {
            $oldArray = static::flattenForHistory($oldArray);
            $newArray = static::flattenForHistory($newArray);
        }

        if (empty($oldArray) && empty($newArray)) {
            return $normalizedData;
        }

        $removed = static::calculateDifference($oldArray, $newArray);
        $updated = static::calculateDifference($newArray, $oldArray);
        static::normalizeValues($removed, 'old', $normalizedData);
        static::normalizeValues($updated, 'new', $normalizedData);

        return $normalizedData;
    }

    /**
     * Extract store URL from apiUrl.
     */
    protected static function extractApiUrl(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($value) || empty($value)) {
            return '';
        }

        $url = array_key_first($value);

        return is_string($url) ? $url : '';
    }

    /**
     * Decode value into array for change comparison.
     */
    protected static function decodeToArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Flatten nested values using only leaf keys for history labels.
     */
    protected static function flattenForHistory(array $values): array
    {
        $flattened = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $flattened += static::flattenForHistory($value);

                continue;
            }

            $flattened[(string) $key] = $value;
        }

        return $flattened;
    }

    /**
     * Check whether any nested arrays exist.
     */
    protected static function hasNestedValues(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                return true;
            }
        }

        return false;
    }
}
