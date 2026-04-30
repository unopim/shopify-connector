<?php

namespace Webkul\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Services\Bulk\Phases\Export\CollectionAssignmentPhaseService;
use Webkul\Shopify\Services\BulkOperationResultReader;
use Webkul\Shopify\Services\PhaseProgressTracker;
use Webkul\Shopify\Traits\HandlesPhaseJobFailure;

class RunCollectionAssignmentPhase implements ShouldQueue
{
    use Dispatchable, HandlesPhaseJobFailure, InteractsWithQueue, Queueable, SerializesModels;

    protected const PHASE = 'collections';

    public function __construct(protected int $bulkOperationId) {}

    public function handle(
        ShopifyBulkOperationRepository $repository,
        BulkOperationResultReader $resultReader,
        CollectionAssignmentPhaseService $phaseService,
        PhaseProgressTracker $tracker,
    ): void {
        $bulkOperation = $repository->find($this->bulkOperationId);

        if (! $bulkOperation) {
            return;
        }

        $tracker->markStarted($bulkOperation->job_track_id, self::PHASE);

        $result = $phaseService->handle($bulkOperation, $resultReader->read($bulkOperation));
        $this->storeResult($bulkOperation, $result);

        if (empty($result['phase_bulk_operation_id'])) {
            $tracker->markFinishedForCore((int) $bulkOperation->id, $bulkOperation->job_track_id, self::PHASE);
        }
    }

    protected function storeResult(object $bulkOperation, array $result): void
    {
        $this->storePhaseResultOnCore((int) $bulkOperation->id, self::PHASE, $result);
    }
}
