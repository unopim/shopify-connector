<?php

namespace Webkul\Shopify\Services;

use Webkul\Shopify\Jobs\RunMediaPhase;
use Webkul\Shopify\Jobs\RunPublishingPhase;
use Webkul\Shopify\Jobs\RunTranslationPhase;
use Webkul\Shopify\Models\ShopifyBulkOperation;

class PhaseOrchestrator
{
    public function __construct(protected PhaseProgressTracker $phaseProgressTracker) {}

    /**
     * Register follow-up phase metadata after a core sync completes.
     */
    public function registerPendingPhases(ShopifyBulkOperation $bulkOperation, array $phaseContext): void
    {
        $meta = $bulkOperation->meta ?? [];
        $meta['follow_up_phases'] = $phaseContext;

        $bulkOperation->meta = $meta;
        $bulkOperation->save();
    }

    /**
     * Dispatch follow-up phases when explicitly enabled.
     */
    public function dispatchPendingPhases(ShopifyBulkOperation $bulkOperation): void
    {
        if (! config('shopify-bulk-operations.dispatch_followup_phases', false)) {
            return;
        }

        $meta = $bulkOperation->meta ?? [];
        $phaseContext = $meta['follow_up_phases'] ?? [];
        $pendingPhases = [
            'publishing'   => ! empty($phaseContext['publishing']),
            'translations' => ! empty($phaseContext['translations']),
            'media'        => ! empty($phaseContext['media']),
        ];

        $pendingPhaseCount = count(array_filter($pendingPhases));

        if ($pendingPhaseCount === 0) {
            $this->settleWithoutPhases($bulkOperation);

            return;
        }

        $meta['follow_up_phases_enabled'] = true;

        $bulkOperation->meta = $meta;
        $bulkOperation->save();

        $this->phaseProgressTracker->registerPhaseJobsForCore(
            (int) $bulkOperation->id,
            $pendingPhaseCount,
        );

        if ($pendingPhases['publishing']) {
            dispatch(new RunPublishingPhase($bulkOperation->id));
        }

        if ($pendingPhases['translations']) {
            dispatch(new RunTranslationPhase($bulkOperation->id));
        }

        if ($pendingPhases['media']) {
            dispatch(new RunMediaPhase($bulkOperation->id));
        }
    }

    /**
     * Close out a core op that needs no follow-up phase.
     *
     * DeferJobTrackCompletion may already have reverted the JobTrack to
     * processing while the bulk op was in flight. Nothing would ever flip it
     * back if no phase job runs, so settle a zero-length phase run here: the
     * tracker's own logic then completes the JobTrack once every core op for it
     * has finished.
     */
    protected function settleWithoutPhases(ShopifyBulkOperation $bulkOperation): void
    {
        $jobTrackId = $bulkOperation->job_track_id;

        if (! $jobTrackId) {
            return;
        }

        $this->phaseProgressTracker->registerPhaseJobsForCore((int) $bulkOperation->id, 1);

        $this->phaseProgressTracker->markFinishedForCore(
            (int) $bulkOperation->id,
            (int) $jobTrackId,
            'none',
        );
    }
}
