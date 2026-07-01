<?php

namespace Webkul\Shopify\Helpers;

class ShoifyApiVersion
{
    /**
     * Shopify API Versions.
     */
    public array $apiVersion = [
        [
            'id' => '2026-01',
            'name' => '2026-01',
        ], [
            'id' => '2026-04',
            'name' => '2026-04',
        ],
    ];

    /**
     * Get available Shopify API versions.
     *
     * @return array The list of Shopify API versions.
     */
    public function getApiVersion(): array
    {
        return $this->apiVersion;
    }
}
