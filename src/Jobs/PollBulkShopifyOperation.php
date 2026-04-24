<?php

namespace Webkul\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Services\BulkOperationService;
use Webkul\Shopify\Services\BulkResultFinalizer;

class PollBulkShopifyOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 120;

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

        $credentialArray = [
            'credentialId' => $credential->id,
            'shopUrl' => $credential->shopUrl,
            'accessToken' => $credential->accessToken,
            'apiVersion' => $credential->apiVersion,
            'clientId' => $credential->clientId,
            'clientSecret' => $credential->clientSecret,
            'accessTokenExpiresAt' => optional($credential->accessTokenExpiresAt)?->toDateTimeString(),
        ];

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
