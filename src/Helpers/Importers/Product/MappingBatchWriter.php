<?php

namespace Webkul\Shopify\Helpers\Importers\Product;

use Illuminate\Support\Facades\DB;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;

/**
 * Buffered writer for wk_shopify_data_mapping rows produced during product import.
 *
 * Replaces per-row `$shopifyMappingRepository->create(...)` calls (one INSERT per
 * variant + per image) with a single chunked INSERT per flush. Falls back to the
 * repository on any error so existing behavior is preserved.
 */
class MappingBatchWriter
{
    /** @var array<int, array<string, mixed>> */
    protected array $buffer = [];

    public function __construct(
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected int $chunkSize = 200,
    ) {}

    public function setChunkSize(int $chunkSize): void
    {
        $this->chunkSize = max(1, $chunkSize);
    }

    public function queue(array $row): void
    {
        $now = now();

        $this->buffer[] = [
            'entityType' => $row['entityType'] ?? null,
            'code' => $row['code'] ?? null,
            'externalId' => $row['externalId'] ?? null,
            'relatedId' => $row['relatedId'] ?? null,
            'relatedSource' => $row['relatedSource'] ?? null,
            'jobInstanceId' => $row['jobInstanceId'] ?? 0,
            'apiUrl' => $row['apiUrl'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (count($this->buffer) >= $this->chunkSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $rows = $this->buffer;
        $this->buffer = [];

        try {
            DB::table($this->shopifyMappingRepository->getModel()->getTable())
                ->insert($rows);
        } catch (\Throwable $e) {
            // Fall back to one-by-one through the repository if bulk insert fails.
            foreach ($rows as $row) {
                try {
                    $this->shopifyMappingRepository->create([
                        'entityType' => $row['entityType'],
                        'code' => $row['code'],
                        'externalId' => $row['externalId'],
                        'relatedId' => $row['relatedId'],
                        'relatedSource' => $row['relatedSource'],
                        'jobInstanceId' => $row['jobInstanceId'],
                        'apiUrl' => $row['apiUrl'],
                    ]);
                } catch (\Throwable) {
                    // skip duplicates / constraint failures so the batch can finish
                }
            }
        }
    }
}
