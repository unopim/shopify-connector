<?php

namespace Webkul\Shopify\Listeners;

use Illuminate\Support\Facades\DB;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Models\JobTrackProxy;
use Webkul\DataTransfer\Repositories\JobTrackRepository;
use Webkul\Shopify\Services\PhaseProgressTracker;

/**
 * When Export::completed() fires but Shopify follow-up phase jobs are still in
 * flight, revert state to processing so the tracker UI keeps polling and the
 * timer keeps running. PhaseProgressTracker flips the state back to completed
 * once the last phase work unit finalizes.
 *
 * Counter source of truth lives on ShopifyBulkOperation.meta because Export::completed
 * overwrites JobTrack.summary entirely, which would otherwise blow away our markers.
 */
class DeferJobTrackCompletion
{
    public function __construct(
        protected JobTrackRepository $jobTrackRepository,
        protected PhaseProgressTracker $phaseProgressTracker,
    ) {}

    public function handle($export): void
    {
        $jobTrackId = is_object($export) ? ($export->id ?? null) : ($export['id'] ?? null);

        if (! $jobTrackId) {
            return;
        }

        if (! $this->phaseProgressTracker->followUpsScheduled((int) $jobTrackId)) {
            return;
        }

        DB::transaction(function () use ($jobTrackId) {
            $modelClass = JobTrackProxy::modelClass();

            $jobTrack = $modelClass::query()
                ->whereKey($jobTrackId)
                ->lockForUpdate()
                ->first();

            if (! $jobTrack) {
                return;
            }

            $remaining = $this->phaseProgressTracker->totalUnfinishedForJobTrack((int) $jobTrackId);

            if ($remaining <= 0) {
                return;
            }

            $summary = $jobTrack->summary ?? [];
            $summary['follow_up_phases_finalize_pending'] = true;

            $this->jobTrackRepository->update([
                'state' => ExportHelper::STATE_PROCESSING,
                'completed_at' => null,
                'summary' => $summary,
            ], $jobTrackId);
        });
    }
}
