<?php

namespace Webkul\Shopify\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Webkul\Shopify\Exceptions\BulkMutationInProgressException;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Services\Bulk\Phases\Export\VariantMediaPhaseService;
use Webkul\Shopify\Services\BulkOperationResultReader;
use Webkul\Shopify\Services\PhaseProgressTracker;
use Webkul\Shopify\Traits\HandlesPhaseJobFailure;

class RunVariantMediaPhase implements ShouldQueue
{
    use HandlesPhaseJobFailure, \Illuminate\Foundation\Queue\Queueable;

    protected const PHASE = 'variant_media';

    public function __construct(protected int $bulkOperationId) {}

    public function handle(
        ShopifyBulkOperationRepository $repository,
        BulkOperationResultReader $resultReader,
        VariantMediaPhaseService $phaseService,
        PhaseProgressTracker $tracker,
    ): void {
        $bulkOperation = $repository->find($this->bulkOperationId);

        if (! $bulkOperation) {
            return;
        }

        $tracker->markStarted($bulkOperation->job_track_id, self::PHASE);

        try {
            $result = $phaseService->handle($bulkOperation, $resultReader->read($bulkOperation));
        } catch (BulkMutationInProgressException) {
            $this->release(random_int(20, 60));

            return;
        }

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
