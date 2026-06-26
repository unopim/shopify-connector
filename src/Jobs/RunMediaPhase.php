<?php

namespace Webkul\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Shopify\Exceptions\BulkMutationInProgressException;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Services\Bulk\Phases\Export\MediaPhaseService;
use Webkul\Shopify\Services\BulkOperationResultReader;
use Webkul\Shopify\Services\PhaseProgressTracker;
use Webkul\Shopify\Traits\HandlesPhaseJobFailure;

class RunMediaPhase implements ShouldQueue
{
    use Dispatchable, HandlesPhaseJobFailure, InteractsWithQueue, Queueable, SerializesModels;

    protected const PHASE = 'media';

    public function __construct(protected int $bulkOperationId) {}

    public function handle(
        ShopifyBulkOperationRepository $repository,
        BulkOperationResultReader $resultReader,
        MediaPhaseService $phaseService,
        PhaseProgressTracker $tracker,
    ): void {
        $bulkOperation = $repository->find($this->bulkOperationId);

        if (! $bulkOperation) {
            return;
        }

        $tracker->markStarted($bulkOperation->job_track_id, self::PHASE);

        $operationData = $resultReader->read($bulkOperation);

        try {
            $result = $phaseService->handle($bulkOperation, $operationData);
        } catch (BulkMutationInProgressException $e) {
            // A sibling phase still holds Shopify's single bulk-mutation slot.
            // Release back to the queue and retry once it frees.
            $this->release(random_int(20, 60));

            return;
        }

        $this->storeResult($bulkOperation, $result);

        // Chain the media_update phase only when build() actually found images to
        // update. Register it (+1) BEFORE this media phase decrements below so the
        // core op does not flip to completed before the updates run.
        if ($phaseService->hasPendingUpdate()) {
            $tracker->registerPhaseJobsForCore((int) $bulkOperation->id, 1);
            RunMediaUpdatePhase::dispatch((int) $bulkOperation->id);
        }

        // When media was created inline by the core productSet (no productCreateMedia
        // op here), the variant-media phase is chained from this point; otherwise it
        // is chained when the productCreateMedia op finalizes.
        if (empty($result['phase_bulk_operation_id']) && ! empty($operationData['manifest']['media_created'])) {
            $tracker->registerPhaseJobsForCore((int) $bulkOperation->id, 1);
            RunVariantMediaPhase::dispatch((int) $bulkOperation->id);
        }

        if (empty($result['phase_bulk_operation_id'])) {
            $tracker->markFinishedForCore((int) $bulkOperation->id, $bulkOperation->job_track_id, self::PHASE);
        }
    }

    protected function storeResult(object $bulkOperation, array $result): void
    {
        $this->storePhaseResultOnCore((int) $bulkOperation->id, self::PHASE, $result);
    }
}
