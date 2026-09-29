<?php

namespace Webkul\Shopify\Services\Bulk\Phases;

use Webkul\Shopify\Exceptions\BulkMutationInProgressException;
use Webkul\Shopify\Jobs\PollBulkShopifyOperation;
use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Services\BulkOperationService;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

abstract class BasePhaseService
{
    use ShopifyGraphqlRequest;

    protected $credential;

    protected $credentialArray;

    protected $manifest;

    protected $coreBulkOperation;

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

        $lines = $this->buildPayloadLines($operationData);

        if ($lines === []) {
            return ['processed' => 0, 'errors' => [], 'phase_bulk_operation_id' => null];
        }

        $phase = $this->getPhaseName();
        $dir = sprintf('shopify/bulk/%s/%s_%s_%s', $manifest['job_track_id'], $phase, $coreBulkOperation->id, time());
        $jsonlPath = $dir.'/input.jsonl';
        $manifestPath = $dir.'/manifest.json';

        $this->bulkOperationService->writeJsonl($jsonlPath, $lines);

        $phaseManifest = [
            'job_track_id'  => $manifest['job_track_id'],
            'credential_id' => $credentialId,
            'shop_url'      => $this->credential->shopUrl,
            'credential'    => $credentialArray,
            'channel'       => $manifest['channel'] ?? 'default',
            'currency'      => $manifest['currency'] ?? 'USD',
            'mutation'      => $this->getManifestMutationName(),
            'line_count'    => count($lines),
        ];

        $extraData = $this->getExtraManifestData($operationData);
        if ($extraData !== []) {
            $phaseManifest = array_merge($phaseManifest, $extraData);
        }

        $this->bulkOperationService->writeManifest($manifestPath, $phaseManifest);

        $filename = basename($jsonlPath);
        $target = $this->bulkOperationService->createJsonlUploadTarget($credentialArray, $filename);

        if ($target === []) {
            return [
                'processed'               => 0,
                'errors'                  => ['Failed to create Shopify staged upload target.'],
                'phase_bulk_operation_id' => null,
            ];
        }

        $absolutePath = storage_path('app/'.$jsonlPath);
        $stagedUploadPath = $this->bulkOperationService->uploadJsonlFile($target, $absolutePath);

        $mutation = config('shopify_bulk_mutations.'.$this->getMutationKey());

        $response = $this->bulkOperationService->runMutation(
            $credentialArray,
            $mutation,
            $stagedUploadPath
        );

        $shopifyBulkOperationId = $response['bulkOperation']['id'] ?? $response['id'] ?? null;

        if (! $shopifyBulkOperationId) {
            $message = $response['userErrors'][0]['message'] ?? 'Unknown error';

            throw_if(stripos($message, 'already in progress') !== false, BulkMutationInProgressException::class, $message);

            return [
                'processed'               => 0,
                'errors'                  => ['Failed to initiate bulk operation: '.$message],
                'phase_bulk_operation_id' => null,
            ];
        }

        $phaseBulkOperation = $this->bulkOperationRepository->create([
            'job_track_id'              => $manifest['job_track_id'],
            'credential_id'             => $credentialId,
            'phase'                     => $phase,
            'shopify_bulk_operation_id' => $shopifyBulkOperationId,
            'input_file_path'           => $manifestPath,
            'staged_upload_path'        => $stagedUploadPath,
            'status'                    => 'created',
            'meta'                      => [
                'parent_bulk_operation_id' => $coreBulkOperation->id,
                'mutation'                 => $this->getManifestMutationName(),
                'line_count'               => count($lines),
            ],
        ]);

        dispatch(new PollBulkShopifyOperation($phaseBulkOperation->id));

        return [
            'processed'               => count($lines),
            'errors'                  => [],
            'phase_bulk_operation_id' => $phaseBulkOperation->id,
        ];
    }

    /**
     * Build credential array from manifest.
     */
    protected function buildCredentialArray(array $manifest): array
    {
        return [
            'credentialId'         => $manifest['credential_id'] ?? null,
            'shopUrl'              => $manifest['shop_url'] ?? null,
            'accessToken'          => $manifest['credential']['accessToken'] ?? null,
            'apiVersion'           => $manifest['credential']['apiVersion'] ?? null,
            'clientId'             => $manifest['credential']['clientId'] ?? null,
            'clientSecret'         => $manifest['credential']['clientSecret'] ?? null,
            'accessTokenExpiresAt' => $manifest['credential']['accessTokenExpiresAt'] ?? null,
            'extras'               => $manifest['credential']['extras'] ?? null,
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
