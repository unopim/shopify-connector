<?php

namespace Webkul\Shopify\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\DataTransfer\Repositories\JobTrackRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Services\BulkOperationService;

class BulkResultFinalizer
{
    public function __construct(
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected PhaseOrchestrator $phaseOrchestrator,
        protected JobTrackBatchRepository $jobTrackBatchRepository,
        protected JobTrackRepository $jobTrackRepository,
    ) {}

    /**
     * Finalize a completed Shopify bulk operation by syncing local mappings or phase results.
     */
    public function finalize(object $bulkOperation, array $manifest): void
    {
        $resultPath = $bulkOperation->result_file_path;

        if (empty($resultPath) || ! Storage::disk('local')->exists($resultPath)) {
            throw new \RuntimeException('Shopify bulk operation result file is missing.');
        }

        $raw = trim(Storage::disk('local')->get($resultPath));
        $results = $raw === '' ? [] : preg_split("/\r\n|\n|\r/", $raw);

        $phase = $bulkOperation->phase ?? null;

        // For core product sync (phase = 'core_product_sync' or empty), perform mapping sync and dispatch follow-up phases
        if (empty($phase) || $phase === BulkOperationService::CORE_PRODUCT_PHASE) {
            $this->finalizeCoreProductSync($bulkOperation, $manifest, $results);
        } else {
            $this->finalizePhaseOperation($bulkOperation, $manifest, $results);
        }
    }

    /**
     * Finalize core productSet bulk operation: sync product/variant mappings.
     */
    protected function finalizeCoreProductSync(ShopifyBulkOperation $bulkOperation, array $manifest, array $results): void
    {
        $manifestLines = $manifest['lines'] ?? [];
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

        $this->markBatchProcessed($bulkOperation, $success, count($failed));

        // Dispatch follow-up phases
        $this->phaseOrchestrator->registerPendingPhases($bulkOperation, $manifest['follow_up_context'] ?? []);
        $this->phaseOrchestrator->dispatchPendingPhases($bulkOperation);
    }

    protected function markBatchProcessed(ShopifyBulkOperation $bulkOperation, int $success, int $failed): void
    {
        if (empty($bulkOperation->job_track_batch_id)) {
            return;
        }

        $this->jobTrackBatchRepository->update([
            'state'   => ExportHelper::STATE_PROCESSED,
            'summary' => [
                'processed' => $success,
                'created'   => $success,
                'skipped'   => $failed,
            ],
        ], $bulkOperation->job_track_batch_id);

        $this->reAggregateJobTrackSummary((int) $bulkOperation->job_track_id);
    }

    protected function reAggregateJobTrackSummary(int $jobTrackId): void
    {
        $grammar = DB::rawQueryGrammar();

        $row = $this->jobTrackBatchRepository
            ->select(
                DB::raw("SUM(CAST({$grammar->jsonExtract('summary', 'processed')} as DECIMAL)) AS processed"),
                DB::raw("SUM(CAST({$grammar->jsonExtract('summary', 'created')} as DECIMAL)) AS created"),
                DB::raw("SUM(CAST({$grammar->jsonExtract('summary', 'skipped')} as DECIMAL)) AS skipped"),
            )
            ->where('job_track_id', $jobTrackId)
            ->groupBy('job_track_id')
            ->first();

        if (! $row) {
            return;
        }

        $this->jobTrackRepository->update([
            'summary' => [
                'processed' => (int) $row->processed,
                'created'   => (int) $row->created,
                'skipped'   => (int) $row->skipped,
            ],
        ], $jobTrackId);
    }

    /**
     * Finalize a phase-specific bulk operation (inventory, collections, publishing, translations).
     */
    protected function finalizePhaseOperation(ShopifyBulkOperation $bulkOperation, array $manifest, array $results): void
    {
        $mutation = $bulkOperation->meta['mutation'] ?? '';
        $total = $bulkOperation->meta['line_count'] ?? count($results);
        $successful = 0;
        $errors = [];

        foreach ($results as $index => $line) {
            $decoded = json_decode($line, true);
            $userErrors = $this->extractUserErrors($decoded, $mutation);

            if (empty($userErrors)) {
                $successful++;
            } else {
                $errors[] = [
                    'line' => $index,
                    'errors' => $userErrors,
                ];
            }
        }

        $meta = $bulkOperation->meta ?? [];
        $meta['result_summary'] = [
            'success' => $successful,
            'failed' => count($errors),
            'errors' => $errors,
            'total_input_lines' => $total,
        ];

        $bulkOperation->status = $successful > 0 && count($errors) === 0 ? 'completed' : 'failed';
        $bulkOperation->meta = $meta;
        $bulkOperation->save();
    }

    /**
     * Extract userErrors array from result line based on mutation type.
     */
    protected function extractUserErrors(array $decoded, string $mutation): array
    {
        return match ($mutation) {
            'inventorySetOnHandQuantities' => $decoded['data']['inventorySetOnHandQuantities']['userErrors'] ?? [],
            'collectionAddProducts' => $decoded['data']['collectionAddProducts']['userErrors'] ?? [],
            'publishablePublish' => $decoded['data']['publishablePublish']['userErrors'] ?? [],
            'translationsRegister' => $decoded['data']['translationsRegister']['userErrors'] ?? [],
            default => [],
        };
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
