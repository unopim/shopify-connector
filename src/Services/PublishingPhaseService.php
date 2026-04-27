<?php

namespace Webkul\Shopify\Services;

use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Services\BulkPayloadBuilders\PublishingBulkPayloadBuilder;
use Webkul\Shopify\Services\BulkOperationService;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;
use Webkul\Shopify\Jobs\PollBulkShopifyOperation;

class PublishingPhaseService
{
    use ShopifyGraphqlRequest;

    public function __construct(
        protected PublishingBulkPayloadBuilder $payloadBuilder,
        protected BulkOperationService $bulkOperationService,
        protected ShopifyBulkOperationRepository $bulkOperationRepository,
        protected ShopifyCredentialRepository $credentialRepository
    ) {}

    /**
     * Handle publishing using bulk publishablePublish mutation.
     *
     * Creates a separate bulk operation and dispatches polling.
     */
    public function handle(ShopifyBulkOperation $coreBulkOperation, array $operationData): array
    {
        $manifest = $operationData['manifest'];
        $credentialId = $manifest['credential_id'] ?? null;

        if (! $credentialId) {
            return ['processed' => 0, 'errors' => ['Missing credential ID']];
        }

        $credential = $this->credentialRepository->find($credentialId);

        if (! $credential) {
            return ['processed' => 0, 'errors' => ['Credential not found']];
        }

        $credentialArray = $this->buildCredentialArray($manifest);

        // Get publication IDs from credential extras
        $publicationIds = $credential->extras['salesChannel'] ?? '';
        if (empty($publicationIds)) {
            return ['processed' => 0, 'errors' => ['Missing publication IDs']];
        }

        // Build JSONL payload
        $lines = $this->payloadBuilder->build($operationData['entries'], $publicationIds);

        if (empty($lines)) {
            return ['processed' => 0, 'errors' => []];
        }

        // Write JSONL file and manifest
        $phase = 'publishing';
        $dir = sprintf('shopify/bulk/%s/%s_%s_%s', $manifest['job_track_id'], $phase, $coreBulkOperation->id, time());
        $jsonlPath = $dir.'/input.jsonl';
        $manifestPath = $dir.'/manifest.json';

        $this->bulkOperationService->writeJsonl($jsonlPath, $lines);

        $phaseManifest = [
            'job_track_id' => $manifest['job_track_id'],
            'credential_id' => $credentialId,
            'shop_url' => $credential->shopUrl,
            'credential' => $credentialArray,
            'channel' => $manifest['channel'] ?? 'default',
            'currency' => $manifest['currency'] ?? 'USD',
            'mutation' => 'publishablePublish',
            'line_count' => count($lines),
        ];

        $this->bulkOperationService->writeManifest($manifestPath, $phaseManifest);

        // Create staged upload target
        $filename = basename($jsonlPath);
        $target = $this->bulkOperationService->createJsonlUploadTarget($credentialArray, $filename);

        if (empty($target)) {
            return [
                'processed' => 0,
                'errors' => ['Failed to create Shopify staged upload target.'],
            ];
        }

        // Upload JSONL file
        $absolutePath = storage_path('app/' . $jsonlPath);
        $stagedUploadPath = $this->bulkOperationService->uploadJsonlFile($target, $absolutePath);

        // Run bulk mutation
        $mutation = <<<'GRAPHQL'
mutation publishablePublishBulk($id: ID!, $input: [PublicationInput!]!) {
  publishablePublish(id: $id, input: $input) {
    userErrors { field message }
  }
}
GRAPHQL;

        $response = $this->bulkOperationService->runMutation(
            $credentialArray,
            $mutation,
            $stagedUploadPath
        );

        $shopifyBulkOperationId = $response['bulkOperation']['id'] ?? $response['id'] ?? null;

        if (! $shopifyBulkOperationId) {
            return [
                'processed' => 0,
                'errors' => ['Failed to initiate bulk operation: ' . ($response['userErrors'][0]['message'] ?? 'Unknown error')],
            ];
        }

        // Create phase bulk operation record
        $phaseBulkOperation = $this->bulkOperationRepository->create([
            'job_track_id' => $manifest['job_track_id'],
            'credential_id' => $credentialId,
            'phase' => 'publishing',
            'shopify_bulk_operation_id' => $shopifyBulkOperationId,
            'input_file_path' => $manifestPath,
            'staged_upload_path' => $stagedUploadPath,
            'status' => 'created',
            'meta' => [
                'parent_bulk_operation_id' => $coreBulkOperation->id,
                'mutation' => 'publishablePublish',
                'line_count' => count($lines),
            ],
        ]);

        // Dispatch poll job
        PollBulkShopifyOperation::dispatch($phaseBulkOperation->id);

        return [
            'processed' => count($lines),
            'errors' => [],
            'phase_bulk_operation_id' => $phaseBulkOperation->id,
        ];
    }

    protected function buildCredentialArray(array $manifest): array
    {
        return [
            'credentialId' => $manifest['credential_id'] ?? null,
            'shopUrl' => $manifest['shop_url'] ?? null,
            'accessToken' => $manifest['credential']['accessToken'] ?? null,
            'apiVersion' => $manifest['credential']['apiVersion'] ?? null,
            'clientId' => $manifest['credential']['clientId'] ?? null,
            'clientSecret' => $manifest['credential']['clientSecret'] ?? null,
            'accessTokenExpiresAt' => $manifest['credential']['accessTokenExpiresAt'] ?? null,
        ];
    }
}
