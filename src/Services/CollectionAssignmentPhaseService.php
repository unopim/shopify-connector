<?php

namespace Webkul\Shopify\Services;

use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Services\BulkPayloadBuilders\CollectionsBulkPayloadBuilder;
use Webkul\Shopify\Services\BulkOperationService;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;
use Webkul\Shopify\Jobs\PollBulkShopifyOperation;

class CollectionAssignmentPhaseService
{
    use ShopifyGraphqlRequest;

    public function __construct(
        protected CollectionsBulkPayloadBuilder $payloadBuilder,
        protected BulkOperationService $bulkOperationService,
        protected ShopifyBulkOperationRepository $bulkOperationRepository,
        protected ShopifyCredentialRepository $credentialRepository
    ) {}

    /**
     * Handle collection assignments using bulk collectionAddProducts mutation.
     *
     * Groups products by collection, creates a single bulk operation, and polls.
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

        // Build JSONL payload grouped by collection
        $lines = $this->payloadBuilder->build($operationData['entries']);

        if (empty($lines)) {
            return ['processed' => 0, 'errors' => []];
        }

        // Write JSONL file and manifest
        $phase = 'collections';
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
            'mutation' => 'collectionAddProducts',
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

        // Run bulk mutation (collectionAddProducts)
        $mutation = <<<'GRAPHQL'
mutation collectionAddProductsBulk($id: ID!, $productIds: [ID!]!) {
  collectionAddProducts(id: $id, productIds: $productIds) {
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
            'phase' => 'collections',
            'shopify_bulk_operation_id' => $shopifyBulkOperationId,
            'input_file_path' => $manifestPath,
            'staged_upload_path' => $stagedUploadPath,
            'status' => 'created',
            'meta' => [
                'parent_bulk_operation_id' => $coreBulkOperation->id,
                'mutation' => 'collectionAddProducts',
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
