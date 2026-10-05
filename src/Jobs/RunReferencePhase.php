<?php

namespace Webkul\Shopify\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Services\Bulk\Phases\Export\ReferenceMetafieldPhaseService;
use Webkul\Shopify\Services\BulkOperationResultReader;
use Webkul\Shopify\Services\PhaseProgressTracker;
use Webkul\Shopify\Traits\HandlesPhaseJobFailure;

class RunReferencePhase implements ShouldQueue
{
    use HandlesPhaseJobFailure, \Illuminate\Foundation\Queue\Queueable;

    protected const PHASE = 'references';

    public function __construct(protected int $bulkOperationId) {}

    public function handle(
        ShopifyBulkOperationRepository $repository,
        BulkOperationResultReader $resultReader,
        ReferenceMetafieldPhaseService $phaseService,
        PhaseProgressTracker $tracker,
    ): void {
        $bulkOperation = $repository->find($this->bulkOperationId);

        if (! $bulkOperation) {
            return;
        }

        $tracker->markStarted($bulkOperation->job_track_id, self::PHASE);
        $result = $phaseService->handle($bulkOperation, $resultReader->read($bulkOperation));

        $this->storePhaseResultOnCore((int) $bulkOperation->id, self::PHASE, $result);

        if (empty($result['phase_bulk_operation_id'])) {
            $tracker->markFinishedForCore((int) $bulkOperation->id, $bulkOperation->job_track_id, self::PHASE);
        }
    }
}
