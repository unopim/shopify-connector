<?php

namespace Webkul\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Webkul\DataTransfer\Services\JobLogger;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Services\BulkOperationService;
use Webkul\Shopify\Services\BulkResultFinalizer;

class PollBulkShopifyOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 120;

    protected ?LoggerInterface $jobLogger = null;

    public function __construct(protected int $bulkOperationId) {}

    /**
     * Execute the job.
     */
    public function handle(
        ShopifyBulkOperationRepository $bulkOperationRepository,
        ShopifyCredentialRepository $credentialRepository,
        BulkOperationService $bulkOperationService,
        BulkResultFinalizer $bulkResultFinalizer,
    ): void {
        $bulkOperation = $bulkOperationRepository->find($this->bulkOperationId);

        if (! $bulkOperation || empty($bulkOperation->shopify_bulk_operation_id)) {
            return;
        }

        $credential = $credentialRepository->find($bulkOperation->credential_id);

        if (! $credential) {
            return;
        }

        $this->jobLogger = $this->makeJobLogger($bulkOperation->job_track_id ?? null);

        $credentialArray = $credential->toApiArray();

        $operationState = $bulkOperationService->getOperation($credentialArray, $bulkOperation->shopify_bulk_operation_id);

        $bulkOperationRepository->update([
            'shopify_status' => strtolower($operationState['status'] ?? 'unknown'),
            'error_code' => $operationState['errorCode'] ?? null,
            'result_url' => $operationState['url'] ?? null,
            'partial_data_url' => $operationState['partialDataUrl'] ?? null,
            'object_count' => isset($operationState['objectCount']) ? (int) $operationState['objectCount'] : null,
            'file_size' => isset($operationState['fileSize']) ? (int) $operationState['fileSize'] : null,
            'status' => $this->mapStatus($operationState['status'] ?? null),
        ], $bulkOperation->id);

        $bulkOperation = $bulkOperationRepository->find($bulkOperation->id);
        $shopifyStatus = strtoupper((string) ($operationState['status'] ?? ''));

        if (in_array($shopifyStatus, ['CREATED', 'RUNNING', 'CANCELING'])) {
            static::dispatch($bulkOperation->id)->delay(
                now()->addSeconds((int) config('shopify-bulk-operations.poll_delay_seconds', 20))
            );

            return;
        }

        if (! in_array($shopifyStatus, ['COMPLETED', 'FAILED', 'CANCELED'])) {
            return;
        }

        $this->jobLogger?->info(sprintf(
            'Shopify bulk operation %s finished with status %s.',
            $bulkOperation->shopify_bulk_operation_id,
            $shopifyStatus
        ));

        if (! empty($operationState['errorCode'])) {
            $this->safeWarn(sprintf(
                'Shopify bulk operation %s reported GraphQL errorCode "%s" (status: %s). Response: %s',
                $bulkOperation->shopify_bulk_operation_id,
                $operationState['errorCode'],
                $shopifyStatus,
                $this->encodeForLog($operationState)
            ));
        }

        $resultUrl = $operationState['url'] ?? $operationState['partialDataUrl'] ?? null;

        if (! $resultUrl) {
            return;
        }

        $resultStoragePath = sprintf(
            'shopify/bulk/%s/%s/result.jsonl',
            $bulkOperation->job_track_id,
            $bulkOperation->id
        );

        $bulkOperationService->downloadResult($resultUrl, $resultStoragePath);

        $bulkOperationRepository->update([
            'result_file_path' => $resultStoragePath,
        ], $bulkOperation->id);

        $bulkOperation = $bulkOperationRepository->find($bulkOperation->id);

        $manifest = $bulkOperationService->readManifest($bulkOperation->input_file_path);
        $bulkResultFinalizer->finalize($bulkOperation, $manifest);

        $this->logUserErrors($bulkOperationRepository->find($bulkOperation->id));
    }

    /**
     * Build a JobLogger bound to the export's job-track id, or null if unavailable.
     */
    protected function makeJobLogger(?int $jobTrackId): ?LoggerInterface
    {
        if (! $jobTrackId) {
            return null;
        }

        try {
            return JobLogger::make($jobTrackId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Walk the finalizer's result_summary.errors and surface every Shopify
     * userErrors entry (keyed by SKU/line) into the per-job log file.
     */
    protected function logUserErrors(?object $bulkOperation): void
    {
        if (! $bulkOperation || ! $this->jobLogger) {
            return;
        }

        $summary = ($bulkOperation->meta ?? [])['result_summary'] ?? [];
        $errors = $summary['errors'] ?? [];

        if (empty($errors) || ! is_array($errors)) {
            return;
        }

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $sku = $error['sku'] ?? null;
            $line = $error['line'] ?? null;
            $userErrors = $error['errors'] ?? [];
            $identifier = $sku !== null ? "SKU [{$sku}]" : 'line ['.((string) $line).']';

            $this->safeWarn(sprintf(
                'Shopify export failed for %s: %s',
                $identifier,
                $this->encodeForLog($userErrors)
            ));
        }
    }

    /**
     * Emit a warning without letting a logger failure interrupt polling.
     */
    protected function safeWarn(string $message): void
    {
        try {
            $this->jobLogger?->warning($message);
        } catch (\Throwable $e) {
            // Logging must never break the bulk polling flow.
        }
    }

    /**
     * Encode arbitrary payloads for the log line, falling back to a print_r dump.
     */
    protected function encodeForLog(mixed $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? print_r($payload, true) : $encoded;
    }

    /**
     * Map Shopify status values to local status values.
     */
    protected function mapStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'COMPLETED' => 'completed',
            'FAILED' => 'failed',
            'CANCELED' => 'cancelled',
            default => 'running',
        };
    }
}
