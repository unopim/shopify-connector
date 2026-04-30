<?php

namespace Webkul\Shopify\Traits;

use Illuminate\Support\Facades\DB;
use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Services\PhaseProgressTracker;

/**
 * Adds resilience for Shopify follow-up phase jobs:
 *  - retry transient failures (e.g. cURL/SSL timeouts to Shopify staged uploads)
 *  - on permanent failure, still decrement the PhaseProgressTracker counter
 *    so the JobTrack does not hang in "processing" forever
 *  - lock-safe per-phase result store on the core bulk op's meta, so concurrent
 *    phase jobs do not clobber each other's updates (and, more importantly,
 *    do not clobber PhaseProgressTracker's `unfinished_phase_jobs` counter)
 *
 * Using classes must define `protected int $bulkOperationId` and a
 * class-level `PHASE` constant.
 */
trait HandlesPhaseJobFailure
{
    public $tries = 3;

    public $backoff = [10, 30, 60];

    public function failed(\Throwable $exception): void
    {
        try {
            $repository = app(ShopifyBulkOperationRepository::class);
            $tracker = app(PhaseProgressTracker::class);

            $bulkOperation = $repository->find($this->bulkOperationId);

            if (! $bulkOperation || empty($bulkOperation->job_track_id)) {
                return;
            }

            $tracker->markFinishedForCore(
                (int) $bulkOperation->id,
                (int) $bulkOperation->job_track_id,
                static::PHASE,
            );
        } catch (\Throwable $e) {
            // Best-effort cleanup — never mask the original failure
        }
    }

    /**
     * Atomically merge this phase's diagnostic result into the core bulk op's
     * meta. A row lock is required because phase jobs run concurrently and
     * the core's `meta` JSON also carries the PhaseProgressTracker counter:
     * a non-locked read-modify-write here can clobber another phase's
     * counter decrement and leave the JobTrack hanging in "processing".
     */
    protected function storePhaseResultOnCore(int $coreBulkOpId, string $phase, array $result): void
    {
        DB::transaction(function () use ($coreBulkOpId, $phase, $result) {
            $coreOp = ShopifyBulkOperation::query()
                ->whereKey($coreBulkOpId)
                ->lockForUpdate()
                ->first();

            if (! $coreOp) {
                return;
            }

            $meta = $coreOp->meta ?? [];
            $meta['phase_results'][$phase] = $result;

            $coreOp->meta = $meta;
            $coreOp->save();
        });
    }
}
