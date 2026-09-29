<?php

namespace Webkul\Shopify\Traits;

use Illuminate\Support\Facades\DB;
use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Services\PhaseProgressTracker;

trait HandlesPhaseJobFailure
{
    public $tries = 30;

    public $maxExceptions = 3;

    public $backoff = [10, 30, 60];

    /**
     * Mark the phase failed, letting cleanup errors pass so the original failure is never masked.
     */
    public function failed(\Throwable $exception): void
    {
        try {
            $repository = resolve(ShopifyBulkOperationRepository::class);
            $tracker = resolve(PhaseProgressTracker::class);

            $bulkOperation = $repository->find($this->bulkOperationId);

            if (! $bulkOperation || empty($bulkOperation->job_track_id)) {
                return;
            }

            $tracker->markFinishedForCore(
                (int) $bulkOperation->id,
                (int) $bulkOperation->job_track_id,
                static::PHASE,
            );
        } catch (\Throwable) {
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
        DB::transaction(function () use ($coreBulkOpId, $phase, $result): void {
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
