<?php

namespace Webkul\Shopify\Services\Bulk\Phases;

use Webkul\Shopify\Exceptions\BulkMutationInProgressException;
use Webkul\Shopify\Jobs\PollBulkShopifyOperation;
use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Services\BulkOperationService;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

/**
 * Abstract base class for bulk operation phases.
 *
 * Implements the template method pattern for bulk phase execution:
 *   1. Validate input & fetch credential
 *   2. Build payload via buildPayloadLines()
 *   3. Write JSONL + manifest files
 *   4. Create staged upload target & upload file
 *   5. Run bulk mutation
 *   6. Create phase bulk operation record
 *   7. Dispatch polling job
 *
 * Concrete phases only need to provide:
 *   - getPhaseName(): string
 *   - getMutationKey(): string (config key for bulk_mutations)
 *   - buildPayloadLines(array $operationData): array
 *   - getExtraManifestData(array $operationData): array (optional)
 *   - extractOperationParameters(array $operationData): array (optional, for validation)
 *
 * This eliminates ~100 lines of duplication per phase service.
 */
abstract class BasePhaseService
{
    use ShopifyGraphqlRequest;

    /** @var ShopifyCredential|null */
    protected $credential;

    /** @var array */
    protected $credentialArray;

    /** @var array */
    protected $manifest;

    /** @var ShopifyBulkOperation */
    protected $coreBulkOperation;

    /** @var object Payload builder instance (e.g., PublishingBulkPayloadBuilder) */
    protected $payloadBuilder;

    public function __construct(
        protected BulkOperationService $bulkOperationService,
        protected ShopifyBulkOperationRepository $bulkOperationRepository,
        protected ShopifyCredentialRepository $credentialRepository
    ) {}

    /**
     * Template method — handles the complete phase workflow.
     *
     * @param  ShopifyBulkOperation  $coreBulkOperation  The parent bulk operation
     * @param  array  $operationData  Contains 'manifest', 'entries' keys
     * @return array ['processed' => int, 'errors' => array, 'phase_bulk_operation_id' => ?int]
     */
    public function handle(ShopifyBulkOperation $coreBulkOperation, array $operationData): array
    {
        $manifest = $operationData['manifest'];
        $credentialId = $manifest['credential_id'] ?? null;

        if (! $credentialId) {
            return ['processed' => 0, 'errors' => ['Missing credential ID'], 'phase_bulk_operation_id' => null];
        }

        $this->credential = $this->credentialRepository->find($credentialId);

        if (! $this->credential) {
            return ['processed' => 0, 'errors' => ['Credential not found'], 'phase_bulk_operation_id' => null];
        }

        $credentialArray = $this->buildCredentialArray($manifest);
        $this->credentialArray = $credentialArray;
        $this->manifest = $manifest;
        $this->coreBulkOperation = $coreBulkOperation;

        // Build payload lines (JSONL)
        $lines = $this->buildPayloadLines($operationData);

        if (empty($lines)) {
            return ['processed' => 0, 'errors' => [], 'phase_bulk_operation_id' => null];
        }

        // Write files
        $phase = $this->getPhaseName();
        $dir = sprintf('shopify/bulk/%s/%s_%s_%s', $manifest['job_track_id'], $phase, $coreBulkOperation->id, time());
        $jsonlPath = $dir.'/input.jsonl';
        $manifestPath = $dir.'/manifest.json';

        $this->bulkOperationService->writeJsonl($jsonlPath, $lines);

        $phaseManifest = [
            'job_track_id' => $manifest['job_track_id'],
            'credential_id' => $credentialId,
            'shop_url' => $this->credential->shopUrl,
            'credential' => $credentialArray,
            'channel' => $manifest['channel'] ?? 'default',
            'currency' => $manifest['currency'] ?? 'USD',
            'mutation' => $this->getManifestMutationName(),
            'line_count' => count($lines),
        ];

        // Merge any extra manifest data from concrete class
        $extraData = $this->getExtraManifestData($operationData);
        if (! empty($extraData)) {
            $phaseManifest = array_merge($phaseManifest, $extraData);
        }

        $this->bulkOperationService->writeManifest($manifestPath, $phaseManifest);

        // Create staged upload target
        $filename = basename($jsonlPath);
        $target = $this->bulkOperationService->createJsonlUploadTarget($credentialArray, $filename);

        if (empty($target)) {
            return [
                'processed' => 0,
                'errors' => ['Failed to create Shopify staged upload target.'],
                'phase_bulk_operation_id' => null,
            ];
        }

        // Upload JSONL file
        $absolutePath = storage_path('app/'.$jsonlPath);
        $stagedUploadPath = $this->bulkOperationService->uploadJsonlFile($target, $absolutePath);

        // Run bulk mutation
        $mutation = config('shopify_bulk_mutations.'.$this->getMutationKey());

        $response = $this->bulkOperationService->runMutation(
            $credentialArray,
            $mutation,
            $stagedUploadPath
        );

        $shopifyBulkOperationId = $response['bulkOperation']['id'] ?? $response['id'] ?? null;

        if (! $shopifyBulkOperationId) {
            $message = $response['userErrors'][0]['message'] ?? 'Unknown error';

            // A sibling phase still holds the single bulk-mutation slot. Signal
            // the calling job to release & retry rather than dropping the phase.
            if (stripos($message, 'already in progress') !== false) {
                throw new BulkMutationInProgressException($message);
            }

            return [
                'processed' => 0,
                'errors' => ['Failed to initiate bulk operation: '.$message],
                'phase_bulk_operation_id' => null,
            ];
        }

        // Create phase bulk operation record
        $phaseBulkOperation = $this->bulkOperationRepository->create([
            'job_track_id' => $manifest['job_track_id'],
            'credential_id' => $credentialId,
            'phase' => $phase,
            'shopify_bulk_operation_id' => $shopifyBulkOperationId,
            'input_file_path' => $manifestPath,
            'staged_upload_path' => $stagedUploadPath,
            'status' => 'created',
            'meta' => [
                'parent_bulk_operation_id' => $coreBulkOperation->id,
                'mutation' => $this->getManifestMutationName(),
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

    /**
     * Build credential array from manifest.
     */
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
            'extras' => $manifest['credential']['extras'] ?? null,
        ];
    }

    /**
     * Build the JSONL payload lines for this phase.
     *
     * @param  array  $operationData  Contains 'entries' and 'manifest'
     * @return array List of JSONL lines (strings)
     */
    abstract protected function buildPayloadLines(array $operationData): array;

    /**
     * Get the phase name (used for directory & record).
     */
    abstract protected function getPhaseName(): string;

    /**
     * Get the mutation config key (e.g., 'publishablePublishBulk').
     */
    abstract protected function getMutationKey(): string;

    /**
     * Get the manifest mutation name (the inner GraphQL mutation name, e.g., 'publishablePublish').
     * This is stored in the manifest and used by BulkResultFinalizer::extractUserErrors().
     */
    abstract protected function getManifestMutationName(): string;

    /**
     * Get extra manifest data to merge (optional).
     *
     * Override to add phase-specific manifest fields (e.g., publication IDs, location ID).
     */
    protected function getExtraManifestData(array $operationData): array
    {
        return [];
    }
}
