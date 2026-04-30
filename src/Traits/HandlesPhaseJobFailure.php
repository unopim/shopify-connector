<?php

namespace Webkul\Shopify\Traits;

use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Services\PhaseProgressTracker;

/**
 * Adds resilience for Shopify follow-up phase jobs:
 *  - retry transient failures (e.g. cURL/SSL timeouts to Shopify staged uploads)
 *  - on permanent failure, still decrement the PhaseProgressTracker counter
 *    so the JobTrack does not hang in "processing" forever
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
}
