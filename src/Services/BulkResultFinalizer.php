<?php

namespace Webkul\Shopify\Services;

use Illuminate\Support\Facades\Storage;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;

class BulkResultFinalizer
{
    public function __construct(
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected PhaseOrchestrator $phaseOrchestrator,
    ) {}

    /**
     * Finalize a completed Shopify bulk operation by syncing local mappings.
     */
    public function finalize(object $bulkOperation, array $manifest): void
    {
        $manifestLines = $manifest['lines'] ?? [];
        $resultPath = $bulkOperation->result_file_path;

        if (empty($resultPath) || ! Storage::disk('local')->exists($resultPath)) {
            throw new \RuntimeException('Shopify bulk operation result file is missing.');
        }

        $raw = trim(Storage::disk('local')->get($resultPath));
        $results = $raw === '' ? [] : preg_split("/\r\n|\n|\r/", $raw);
        $success = 0;
        $failed = [];

        foreach ($results as $index => $line) {
            $decoded = json_decode($line, true);
            $manifestLine = $manifestLines[$index] ?? [];
            $payload = $decoded['data']['productSet'] ?? [];
            $userErrors = $payload['userErrors'] ?? [];
            $product = $payload['product'] ?? [];

            if (! empty($userErrors) || empty($product['id'])) {
                $failed[] = [
                    'line' => $index,
                    'sku' => $manifestLine['product_sku'] ?? null,
                    'errors' => $userErrors,
                ];

                continue;
            }

            $this->syncProductMapping(
                $manifestLine['product_sku'] ?? null,
                $product['id'],
                $manifest['job_track_id'] ?? null,
                $manifest['shop_url'] ?? null
            );

            foreach ($product['variants']['nodes'] ?? [] as $variant) {
                if (empty($variant['sku']) || empty($variant['id'])) {
                    continue;
                }

                $this->syncVariantMapping(
                    $variant['sku'],
                    $variant['id'],
                    $product['id'],
                    $manifest['job_track_id'] ?? null,
                    $manifest['shop_url'] ?? null
                );
            }

            $success++;
        }

        $meta = $bulkOperation->meta ?? [];
        $meta['result_summary'] = [
            'success' => $success,
            'failed' => count($failed),
            'errors' => $failed,
        ];

        $bulkOperation->status = 'completed';
        $bulkOperation->meta = $meta;
        $bulkOperation->save();

        $this->phaseOrchestrator->registerPendingPhases($bulkOperation, $manifest['follow_up_context'] ?? []);
        $this->phaseOrchestrator->dispatchPendingPhases($bulkOperation);
    }

    /**
     * Create or update the parent product mapping.
     */
    protected function syncProductMapping(?string $sku, ?string $productId, ?int $jobTrackId, ?string $shopUrl): void
    {
        if (! $sku || ! $productId || ! $jobTrackId || ! $shopUrl) {
            return;
        }

        $existing = $this->shopifyMappingRepository->where('code', $sku)
            ->where('entityType', 'product')
            ->where('apiUrl', $shopUrl)
            ->first();

        $data = [
            'entityType' => 'product',
            'code' => $sku,
            'externalId' => $productId,
            'jobInstanceId' => $jobTrackId,
            'apiUrl' => $shopUrl,
            'relatedId' => null,
        ];

        if ($existing) {
            $this->shopifyMappingRepository->update($data, $existing->id);

            return;
        }

        $this->shopifyMappingRepository->create($data);
    }

    /**
     * Create or update the variant mapping.
     */
    protected function syncVariantMapping(?string $sku, ?string $variantId, ?string $productId, ?int $jobTrackId, ?string $shopUrl): void
    {
        if (! $sku || ! $variantId || ! $productId || ! $jobTrackId || ! $shopUrl) {
            return;
        }

        $existing = $this->shopifyMappingRepository->where('code', $sku)
            ->where('entityType', 'product')
            ->where('apiUrl', $shopUrl)
            ->first();

        $data = [
            'entityType' => 'product',
            'code' => $sku,
            'externalId' => $variantId,
            'relatedId' => $productId,
            'jobInstanceId' => $jobTrackId,
            'apiUrl' => $shopUrl,
        ];

        if ($existing) {
            $this->shopifyMappingRepository->update($data, $existing->id);

            return;
        }

        $this->shopifyMappingRepository->create($data);
    }
}
