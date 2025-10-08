<?php

namespace Webkul\Shopify\Helpers;

class ShoifyApiVersion
{
    /**
     * Shopify API Versions.
     */
    public array $apiVersion = [
        [
            'id'   => '2025-01',
            'name' => '2025-01',
        ],
        [
            'id'   => '2025-04',
            'name' => '2025-04',
        ],
        [
            'id'   => '2025-07',
            'name' => '2025-07',
        ],
        [
            'id'   => '2025-10',
            'name' => '2025-10',
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
