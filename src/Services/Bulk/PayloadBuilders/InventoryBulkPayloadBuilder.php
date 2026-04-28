<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders;

use Webkul\Shopify\Services\ProductPhaseDataService;

class InventoryBulkPayloadBuilder
{
    public function __construct(
        protected ProductPhaseDataService $productPhaseDataService
    ) {}

    /**
     * Build JSONL payload lines for inventorySetOnHandQuantities mutation.
     *
     * Each line format:
     * {
     *   "input": {
     *     "reason": "correction",
     *     "setQuantities": [
     *       {
     *         "inventoryItemId": "gid://shopify/InventoryItem/...",
     *         "locationId": "gid://shopify/Location/...",
     *         "quantity": 10
     *       }
     *     ]
     *   }
     * }
     *
     * @param  array  $entries  Successful productSet entries with variants
     * @param  string|null  $locationId  Shopify location GID
     * @param  int  $defaultQuantity  Default inventory if not mapped
     * @param  int  $credentialId  Credential ID
     * @param  string  $channel  Channel key
     * @param  string  $currency  Currency code
     * @return array  JSONL lines
     */
    public function build(array $entries, ?string $locationId, int $defaultQuantity, int $credentialId, string $channel = 'default', string $currency = 'USD'): array
    {
        if (! $locationId) {
            return [];
        }

        $lines = [];

        foreach ($entries as $entry) {
            if (! empty($entry['user_errors']) || empty($entry['product']['id'])) {
                continue;
            }

            foreach ($entry['product']['variants']['nodes'] ?? [] as $variant) {
                $sku = $variant['sku'] ?? null;
                $rawInventoryItemId = $variant['inventoryItem']['id'] ?? null;
                $locationIdGid = $this->ensureGid($locationId, 'Location');

                if (! $sku || ! $rawInventoryItemId) {
                    continue;
                }

                $inventoryItemId = $this->ensureGid($rawInventoryItemId, 'InventoryItem');

                // Get mapped inventory quantity from Unopim via ProductPhaseDataService
                $context = $this->productPhaseDataService->getProductContext(
                    $sku,
                    (int) $credentialId,
                    $channel,
                    $currency
                );

                if (! $context) {
                    continue;
                }

                $inventoryAttribute = $context['export_mapping']->mapping['shopify_connector_settings']['inventoryQuantity'] ?? null;

                if ($inventoryAttribute) {
                    $quantity = (int) ($context['merged_fields'][$inventoryAttribute] ?? 0);
                } else {
                    $defaultInventory = $context['export_mapping']->mapping['shopify_connector_defaults']['inventoryQuantity'] ?? $defaultQuantity;
                    $quantity = (int) $defaultInventory;
                }

                $line = [
                    'input' => [
                        'reason' => 'correction',
                        'setQuantities' => [
                            [
                                'inventoryItemId' => $inventoryItemId,
                                'locationId' => $locationIdGid,
                                'quantity' => $quantity,
                            ],
                        ],
                    ],
                ];

                $lines[] = json_encode($line, JSON_UNESCAPED_SLASHES);
            }
        }

        return $lines;
    }

    /**
     * Ensure an ID is in Shopify GID format.
     */
    protected function ensureGid(string $id, string $type): string
    {
        if (str_starts_with($id, 'gid://')) {
            return $id;
        }

        return "gid://shopify/{$type}/{$id}";
    }
}
