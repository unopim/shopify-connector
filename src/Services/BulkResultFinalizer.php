<?php

namespace Webkul\Shopify\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\DataTransfer\Models\JobTrackProxy;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\DataTransfer\Repositories\JobTrackRepository;
use Webkul\DataTransfer\Services\JobLogger;
use Webkul\Shopify\Jobs\RunVariantMediaPhase;
use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class BulkResultFinalizer
{
    use ShopifyGraphqlRequest;

    public function __construct(
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected PhaseOrchestrator $phaseOrchestrator,
        protected JobTrackBatchRepository $jobTrackBatchRepository,
        protected JobTrackRepository $jobTrackRepository,
        protected PhaseProgressTracker $phaseProgressTracker,
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
        $shopUrl = $manifest['shop_url'] ?? null;
        $credential = $manifest['credential'] ?? [];
        $jobTrackId = $manifest['job_track_id'] ?? null;
        $inputLines = $this->readInputJsonl($bulkOperation->input_file_path ?? null);
        $success = 0;
        $failed = [];
        $clearedStaleSkus = [];
        $recreatedSkus = [];
        $pendingMedia = [];

        foreach ($results as $index => $line) {
            $decoded = json_decode($line, true);
            $manifestLine = $manifestLines[$index] ?? [];
            $payload = $decoded['data']['productSet'] ?? [];
            $userErrors = $payload['userErrors'] ?? [];
            $product = $payload['product'] ?? [];

            if (! empty($userErrors) || empty($product['id'])) {
                $sku = $manifestLine['product_sku'] ?? null;

                if ($sku && $shopUrl && $this->isStaleProductMappingError($userErrors)) {
                    $cleared = $this->clearStaleProductMappings($sku, $shopUrl);
                    if (! empty($cleared)) {
                        $clearedStaleSkus = array_values(array_unique(array_merge($clearedStaleSkus, $cleared)));
                    }

                    $retry = $this->recreateWithHandleIdentifier(
                        $inputLines[$index] ?? null,
                        $manifestLine,
                        $credential,
                        $jobTrackId,
                        $shopUrl,
                    );

                    if ($retry['success']) {
                        $recreatedSkus[] = $sku;
                        $success++;

                        continue;
                    }

                    if (! empty($retry['errors'])) {
                        $userErrors = array_merge($userErrors, $retry['errors']);
                    }
                }

                $failed[] = [
                    'line' => $index,
                    'sku' => $sku,
                    'errors' => $userErrors,
                ];

                continue;
            }

            $this->syncProductMapping(
                $manifestLine['product_sku'] ?? null,
                $product['id'],
                $jobTrackId,
                $shopUrl
            );

            foreach ($product['variants']['nodes'] ?? [] as $variant) {
                $variantSku = ($variant['sku'] ?? null) ?: ($variant['inventoryItem']['sku'] ?? null);

                if (empty($variantSku) || empty($variant['id'])) {
                    continue;
                }

                $this->syncVariantMapping(
                    $variantSku,
                    $variant['id'],
                    $product['id'],
                    $jobTrackId,
                    $shopUrl
                );
            }

            if (! empty($manifestLine['media_plan_items']) && ! empty($product['id'])) {
                $pendingMedia[] = [
                    'productId' => $product['id'],
                    'planItems' => $manifestLine['media_plan_items'],
                ];
            }

            $success++;
        }

        $this->persistBulkMediaMappings($pendingMedia, $credential, $jobTrackId, $shopUrl);

        $meta = $bulkOperation->meta ?? [];
        $meta['result_summary'] = [
            'success' => $success,
            'failed' => count($failed),
            'errors' => $failed,
            'cleared_stale_mappings' => $clearedStaleSkus,
            'recreated_after_stale_mapping' => $recreatedSkus,
        ];

        $bulkOperation->status = 'completed';
        $bulkOperation->meta = $meta;
        $bulkOperation->save();

        if (! empty($clearedStaleSkus) || ! empty($recreatedSkus)) {
            $this->logStaleMappingCleanup((int) ($jobTrackId ?? 0), $clearedStaleSkus, $recreatedSkus);
        }

        $this->markBatchProcessed($bulkOperation, $success, count($failed));

        // Dispatch follow-up phases
        $this->phaseOrchestrator->registerPendingPhases($bulkOperation, $manifest['follow_up_context'] ?? []);
        $this->phaseOrchestrator->dispatchPendingPhases($bulkOperation);
    }

    /**
     * Detect Shopify productSet errors that indicate the local mapping points to a deleted product.
     */
    protected function isStaleProductMappingError(array $userErrors): bool
    {
        foreach ($userErrors as $error) {
            $code = strtoupper((string) ($error['code'] ?? ''));

            if ($code === 'PRODUCT_DOES_NOT_EXIST' || $code === 'NOT_FOUND') {
                return true;
            }

            $message = strtolower((string) ($error['message'] ?? ''));
            $field = strtolower(implode(',', array_map('strval', (array) ($error['field'] ?? []))));

            $isIdentifierError = str_contains($field, 'identifier') || str_contains($field, 'id');
            $hintsAtMissing = str_contains($message, 'does not exist')
                || str_contains($message, 'not found')
                || str_contains($message, 'no such product');

            if ($isIdentifierError && $hintsAtMissing) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete the stale parent and variant mappings tied to the given SKU's Shopify product.
     *
     * @return array<string> SKUs whose mappings were deleted
     */
    protected function clearStaleProductMappings(string $sku, string $shopUrl): array
    {
        $direct = $this->shopifyMappingRepository->where('code', $sku)
            ->where('entityType', 'product')
            ->where('apiUrl', $shopUrl)
            ->first();

        if (! $direct) {
            return [];
        }

        $parentProductId = $direct->relatedId ?: $direct->externalId;

        if (empty($parentProductId)) {
            return [];
        }

        $relatedMappings = $this->shopifyMappingRepository
            ->where('apiUrl', $shopUrl)
            ->where('entityType', 'product')
            ->where(function ($query) use ($parentProductId) {
                $query->where('relatedId', $parentProductId)
                    ->orWhere('externalId', $parentProductId);
            })
            ->get();

        $cleared = [];

        foreach ($relatedMappings as $mapping) {
            $cleared[] = $mapping->code;
            $this->shopifyMappingRepository->delete($mapping->id);
        }

        if (! empty($cleared)) {
            $staleMedia = $this->shopifyMappingRepository
                ->where('apiUrl', $shopUrl)
                ->where('entityType', 'productImage')
                ->whereIn('relatedSource', $cleared)
                ->get();

            foreach ($staleMedia as $media) {
                $this->shopifyMappingRepository->delete($media->id);
            }
        }

        return $cleared;
    }

    /**
     * Recreate a product on Shopify by re-running productSet with a handle-based identifier.
     *
     * Used when the original bulk attempt failed because the local mapping pointed to a
     * deleted Shopify product. Sync the new parent + variant mappings on success.
     *
     * @return array{success: bool, errors?: array}
     */
    protected function recreateWithHandleIdentifier(
        ?array $variables,
        array $manifestLine,
        array $credential,
        ?int $jobTrackId,
        ?string $shopUrl,
    ): array {
        $handle = $manifestLine['product_handle'] ?? null;

        if (empty($variables) || empty($variables['input']) || empty($credential)) {
            return ['success' => false];
        }

        // Adopt the existing product by handle when one is mapped; otherwise create fresh
        // and let Shopify generate the handle.
        $variables['identifier'] = ! empty($handle) ? ['handle' => $handle] : null;
        unset($variables['input']['files']);

        $result = $this->runRecreateProductSet($credential, $variables);

        // The handle is already owned by another (orphan) product: drop it and let Shopify
        // auto-generate a unique handle so the recreate still succeeds.
        if (! $result['success'] && $this->hasHandleConflict($result['errors'] ?? [])) {
            $variables['identifier'] = null;
            unset($variables['input']['handle']);
            $result = $this->runRecreateProductSet($credential, $variables);
        }

        if (! $result['success']) {
            return ['success' => false, 'errors' => $result['errors'] ?? []];
        }

        $product = $result['product'];

        $this->syncProductMapping(
            $manifestLine['product_sku'] ?? null,
            $product['id'],
            $jobTrackId,
            $shopUrl,
        );

        foreach ($product['variants']['nodes'] ?? [] as $variant) {
            $variantSku = ($variant['sku'] ?? null) ?: ($variant['inventoryItem']['sku'] ?? null);

            if (empty($variantSku) || empty($variant['id'])) {
                continue;
            }

            $this->syncVariantMapping(
                $variantSku,
                $variant['id'],
                $product['id'],
                $jobTrackId,
                $shopUrl,
            );
        }

        $this->persistCoreMediaMappings($manifestLine, $product, $jobTrackId, $shopUrl);

        return ['success' => true];
    }

    /**
     * Run a single productSet recreation request.
     *
     * @return array{success: bool, product?: array, errors?: array}
     */
    protected function runRecreateProductSet(array $credential, array $variables): array
    {
        try {
            $response = $this->requestGraphQlApiAction('productSet', $credential, $variables);
        } catch (\Throwable $e) {
            return ['success' => false, 'errors' => [['message' => 'Recreation failed: '.$e->getMessage()]]];
        }

        $payload = $response['body']['data']['productSet'] ?? [];
        $userErrors = $payload['userErrors'] ?? [];
        $product = $payload['product'] ?? [];

        if (! empty($userErrors) || empty($product['id'])) {
            return ['success' => false, 'errors' => $userErrors];
        }

        return ['success' => true, 'product' => $product];
    }

    /**
     * Whether the userErrors contain a handle-uniqueness conflict.
     */
    protected function hasHandleConflict(array $errors): bool
    {
        foreach ($errors as $error) {
            if (($error['code'] ?? null) === 'HANDLE_NOT_UNIQUE') {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the bulk operation's input JSONL alongside the manifest file.
     *
     * @return array<int, array> Decoded variables keyed by line index
     */
    protected function readInputJsonl(?string $manifestPath): array
    {
        if (empty($manifestPath)) {
            return [];
        }

        $jsonlPath = dirname($manifestPath).'/input.jsonl';

        if (! Storage::disk('local')->exists($jsonlPath)) {
            return [];
        }

        $raw = trim(Storage::disk('local')->get($jsonlPath));

        if ($raw === '') {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", $raw);

        return array_map(fn ($line) => json_decode($line, true) ?: [], $lines);
    }

    /**
     * Surface stale-mapping cleanups + recreations in the job-tracker log.
     */
    protected function logStaleMappingCleanup(int $jobTrackId, array $clearedSkus, array $recreatedSkus): void
    {
        if ($jobTrackId <= 0) {
            return;
        }

        try {
            $logger = JobLogger::make($jobTrackId);

            if (! empty($recreatedSkus)) {
                $logger->info(sprintf(
                    'Recreated Shopify product(s) for SKU(s) after detecting stale local mapping: %s',
                    implode(', ', $recreatedSkus)
                ));
            }

            $unrecoveredCleared = array_values(array_diff($clearedSkus, $recreatedSkus));

            if (! empty($unrecoveredCleared)) {
                $logger->warning(sprintf(
                    'Cleared stale Shopify mapping(s) for SKU(s): %s. Recreation could not complete in this run; re-run the export to recreate them.',
                    implode(', ', $unrecoveredCleared)
                ));
            }
        } catch (\Throwable $e) {
            // Logging failures should never break finalization
        }
    }

    protected function markBatchProcessed(ShopifyBulkOperation $bulkOperation, int $success, int $failed): void
    {
        if (empty($bulkOperation->job_track_batch_id)) {
            return;
        }

        // Write the truthful Shopify-confirmed count directly to JobTrack.summary.
        //
        // We deliberately do NOT touch the individual batch summary rows: the
        // exporter already wrote each batch's slice of the catalog (via
        // markBatchAsNoOp) so SUM(batches.summary.created) = total catalog rows,
        // which feeds the climbing per-batch count in Export::stats() during the
        // processing window. Overwriting one batch row with `$success` and then
        // re-aggregating would corrupt that math (e.g. 9962 + 19 × 500 = 19462
        // for a 10k export with 38 failures), reintroducing the 200k-style bug.
        //
        // JobTrack.summary is the source of truth for the FINAL count shown in
        // the completed UI; per-batch slice counts power the climbing during
        // processing. The two coexist by reading from different sources at
        // different states.
        DB::transaction(function () use ($bulkOperation, $success, $failed) {
            $jobTrackId = (int) $bulkOperation->job_track_id;

            if ($jobTrackId <= 0) {
                return;
            }

            $modelClass = JobTrackProxy::modelClass();
            $jobTrack = $modelClass::query()
                ->whereKey($jobTrackId)
                ->lockForUpdate()
                ->first();

            if (! $jobTrack) {
                return;
            }

            $summary = array_merge((array) ($jobTrack->summary ?? []), [
                'processed' => $success,
                'created' => $success,
                'skipped' => $failed,
            ]);

            $this->jobTrackRepository->update(['summary' => $summary], $jobTrackId);
        });
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

        $modelClass = JobTrackProxy::modelClass();
        $jobTrack = $modelClass::query()->whereKey($jobTrackId)->first();

        if (! $jobTrack) {
            return;
        }

        $summary = array_merge((array) ($jobTrack->summary ?? []), [
            'processed' => (int) $row->processed,
            'created' => (int) $row->created,
            'skipped' => (int) $row->skipped,
        ]);

        $this->jobTrackRepository->update(['summary' => $summary], $jobTrackId);
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

        // Persist the created Shopify media IDs so subsequent exports update the
        // existing media instead of creating duplicates.
        if ($mutation === 'productCreateMedia') {
            $this->persistMediaMappings($manifest, $results);

            $coreOpId = (int) (($bulkOperation->meta ?? [])['parent_bulk_operation_id'] ?? 0);

            if ($coreOpId > 0) {
                $this->phaseProgressTracker->registerPhaseJobsForCore($coreOpId, 1);
                RunVariantMediaPhase::dispatch($coreOpId);
            }
        }

        // Refresh the stored mapping `code` (attribute + new path) for media the
        // media_update phase updated, so the next export sees the new path.
        if ($mutation === 'productUpdateMedia') {
            $this->refreshMediaUpdateMappings($manifest, $results);
        }

        if ($bulkOperation->job_track_id && $bulkOperation->phase) {
            $parentCoreOpId = (int) (($bulkOperation->meta ?? [])['parent_bulk_operation_id'] ?? 0);

            if ($parentCoreOpId > 0) {
                $this->phaseProgressTracker->markFinishedForCore(
                    $parentCoreOpId,
                    (int) $bulkOperation->job_track_id,
                    (string) $bulkOperation->phase,
                );
            }
        }
    }

    /**
     * Persist mappings for media created inline by the core productSet bulk op.
     *
     * The bulk result can't carry a media connection (Shopify allows one connection
     * per bulk mutation), so the created media ids are fetched in batches via a
     * regular query and matched to each product's plan items by alt.
     *
     * @param  array<int, array{productId: string, planItems: array}>  $pendingMedia
     */
    protected function persistBulkMediaMappings(array $pendingMedia, array $credential, ?int $jobTrackId, ?string $shopUrl): void
    {
        if (empty($pendingMedia) || empty($credential) || empty($jobTrackId) || empty($shopUrl)) {
            return;
        }

        $planByProduct = [];

        foreach ($pendingMedia as $entry) {
            $planByProduct[$entry['productId']] = $entry['planItems'];
        }

        foreach (array_chunk(array_keys($planByProduct), 50) as $chunk) {
            try {
                $response = $this->requestGraphQlApiAction('getProductsMedia', $credential, ['ids' => $chunk]);
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($response['body']['data']['nodes'] ?? [] as $node) {
                $productId = $node['id'] ?? null;

                if (! $productId || empty($planByProduct[$productId])) {
                    continue;
                }

                $idByAlt = [];

                foreach ($node['media']['nodes'] ?? [] as $media) {
                    $alt = $media['alt'] ?? null;

                    if (is_string($alt) && $alt !== '' && ! empty($media['id'])) {
                        $idByAlt[$alt] = $media['id'];
                    }
                }

                foreach ($planByProduct[$productId] as $item) {
                    $mediaId = $idByAlt[$item['alt'] ?? ''] ?? null;

                    if (! $mediaId) {
                        continue;
                    }

                    $this->syncMediaMapping(
                        $item['sku'] ?? null,
                        $item['code'] ?? null,
                        $mediaId,
                        $productId,
                        (int) $jobTrackId,
                        $shopUrl,
                    );
                }
            }
        }
    }

    /**
     * Persist media mappings for files created inline by productSet, matching the
     * manifest line's plan items to the returned media nodes by alt.
     */
    protected function persistCoreMediaMappings(array $manifestLine, array $product, ?int $jobTrackId, ?string $shopUrl): void
    {
        $planItems = $manifestLine['media_plan_items'] ?? [];

        if (empty($planItems) || empty($jobTrackId) || empty($shopUrl)) {
            return;
        }

        $idByAlt = [];

        foreach ($product['media']['nodes'] ?? [] as $node) {
            $alt = $node['alt'] ?? null;

            if (is_string($alt) && $alt !== '' && ! empty($node['id'])) {
                $idByAlt[$alt] = $node['id'];
            }
        }

        foreach ($planItems as $item) {
            $mediaId = $idByAlt[$item['alt'] ?? ''] ?? null;

            if (! $mediaId) {
                continue;
            }

            $this->syncMediaMapping(
                $item['sku'] ?? null,
                $item['code'] ?? null,
                $mediaId,
                $product['id'] ?? null,
                (int) $jobTrackId,
                $shopUrl,
            );
        }
    }

    /**
     * Persist a mapping for every image the media phase successfully created.
     *
     * The mapping is keyed by the media `alt` (the connector's deterministic
     * "<sku> - <attribute>"); MediaBulkPayloadBuilder reads these on the next
     * export and skips images already sent, preventing duplicate uploads.
     */
    protected function syncMediaMappings(array $results, array $manifest): void
    {
        $shopUrl = $manifest['shop_url'] ?? null;
        $jobTrackId = $manifest['job_track_id'] ?? null;

        if (empty($shopUrl)) {
            return;
        }

        foreach ($results as $line) {
            $decoded = json_decode($line, true) ?: [];
            $payload = $decoded['data']['productCreateMedia'] ?? [];

            if (! empty($payload['mediaUserErrors'])) {
                continue;
            }

            $productId = $payload['product']['id'] ?? null;

            foreach ($payload['media'] ?? [] as $media) {
                $alt = $media['alt'] ?? null;

                if (! is_string($alt) || $alt === '') {
                    continue;
                }

                $exists = $this->shopifyMappingRepository
                    ->where('entityType', 'product_media')
                    ->where('code', $alt)
                    ->where('apiUrl', $shopUrl)
                    ->first();

                if ($exists) {
                    continue;
                }

                $this->shopifyMappingRepository->create([
                    'entityType' => 'product_media',
                    'code' => $alt,
                    'externalId' => $media['id'] ?? null,
                    'relatedId' => $productId,
                    'jobInstanceId' => $jobTrackId,
                    'apiUrl' => $shopUrl,
                ]);
            }
        }
    }

    /**
     * Extract userErrors array from result line based on mutation type.
     */
    protected function extractUserErrors(array $decoded, string $mutation): array
    {
        return match ($mutation) {
            'publishablePublish' => $decoded['data']['publishablePublish']['userErrors'] ?? [],
            'translationsRegister' => $decoded['data']['translationsRegister']['userErrors'] ?? [],
            'productCreateMedia' => $decoded['data']['productCreateMedia']['mediaUserErrors'] ?? [],
            'productUpdateMedia' => $decoded['data']['productUpdateMedia']['mediaUserErrors'] ?? [],
            'productVariantAppendMedia' => $decoded['data']['productVariantAppendMedia']['userErrors'] ?? [],
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

    /**
     * Store media mappings for media created by a productCreateMedia bulk operation.
     *
     * The phase manifest carries a per-line media plan written by MediaBulkPayloadBuilder.
     * Each result line's created media is matched back to its (SKU, attribute) — by the
     * deterministic alt text, falling back to positional order — and persisted so that
     * the next export updates the existing media instead of creating a duplicate.
     */
    protected function persistMediaMappings(array $manifest, array $results): void
    {
        $mediaPlan = $manifest['media_plan'] ?? [];
        $shopUrl = $manifest['shop_url'] ?? null;
        $jobTrackId = $manifest['job_track_id'] ?? null;

        if (empty($mediaPlan) || empty($shopUrl) || empty($jobTrackId)) {
            return;
        }

        foreach ($results as $index => $line) {
            $plan = $mediaPlan[$index] ?? null;

            if (! $plan || empty($plan['items'])) {
                continue;
            }

            $decoded = json_decode($line, true) ?: [];
            $createdMedia = $decoded['data']['productCreateMedia']['media'] ?? [];

            if (empty($createdMedia)) {
                continue;
            }

            // Index plan items by their deterministic alt text for robust matching
            // even when Shopify drops some media (partial failure shifts positions).
            $itemsByAlt = [];
            foreach ($plan['items'] as $item) {
                if (! empty($item['alt'])) {
                    $itemsByAlt[$item['alt']] = $item;
                }
            }

            foreach ($createdMedia as $mediaIndex => $mediaNode) {
                $mediaId = $mediaNode['id'] ?? null;

                if (empty($mediaId)) {
                    continue;
                }

                $alt = $mediaNode['alt'] ?? null;
                $item = ($alt !== null && isset($itemsByAlt[$alt]))
                    ? $itemsByAlt[$alt]
                    : ($plan['items'][$mediaIndex] ?? null);

                if (! $item) {
                    continue;
                }

                $this->syncMediaMapping(
                    $item['sku'] ?? null,
                    $item['code'] ?? null,
                    $mediaId,
                    $plan['productId'] ?? null,
                    (int) $jobTrackId,
                    $shopUrl,
                );
            }
        }
    }

    /**
     * Rewrite the stored mapping `code` for media updated by a productUpdateMedia
     * bulk operation.
     *
     * The phase manifest carries a code-refresh plan keyed by Shopify media GID
     * (computed by MediaBulkPayloadBuilder::build, persisted by the media phase).
     * Each successfully
     * updated media node is matched back to its mapping row by GID and its `code`
     * rewritten to the new (attribute + path), so the next export skips it instead
     * of updating again.
     */
    protected function refreshMediaUpdateMappings(array $manifest, array $results): void
    {
        $updatePlan = $manifest['media_update_plan'] ?? [];

        if (empty($updatePlan)) {
            return;
        }

        foreach ($results as $line) {
            $decoded = json_decode($line, true) ?: [];
            $payload = $decoded['data']['productUpdateMedia'] ?? [];

            if (! empty($payload['mediaUserErrors'])) {
                continue;
            }

            foreach ($payload['media'] ?? [] as $media) {
                $mediaId = $media['id'] ?? null;
                $refresh = $mediaId ? ($updatePlan[$mediaId] ?? null) : null;

                if (! $refresh || empty($refresh['rowId']) || ! isset($refresh['code'])) {
                    continue;
                }

                $this->shopifyMappingRepository->update(['code' => $refresh['code']], $refresh['rowId']);
            }
        }
    }

    /**
     * Create or update a media mapping row (entityType "productImage").
     */
    protected function syncMediaMapping(?string $sku, ?string $code, ?string $mediaId, ?string $productId, ?int $jobTrackId, ?string $shopUrl): void
    {
        if (! $sku || ! $code || ! $mediaId || ! $jobTrackId || ! $shopUrl) {
            return;
        }

        $existing = $this->shopifyMappingRepository
            ->where('entityType', 'productImage')
            ->where('code', $code)
            ->where('relatedSource', $sku)
            ->where('apiUrl', $shopUrl)
            ->first();

        $data = [
            'entityType' => 'productImage',
            'code' => $code,
            'externalId' => $mediaId,
            'relatedId' => $productId,
            'relatedSource' => $sku,
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
