<?php

namespace Webkul\Shopify\Helpers;

class ShoifyApiVersion
{
    /**
     * Shopify API Versions.
     */
    public array $apiVersion = [
        [
            'id'   => '2024-07',
            'name' => '2024-07',
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
