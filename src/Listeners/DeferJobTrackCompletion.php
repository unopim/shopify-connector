<?php

namespace Webkul\Shopify\Listeners;

use Illuminate\Support\Facades\DB;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Models\JobTrackProxy;
use Webkul\DataTransfer\Repositories\JobTrackRepository;
use Webkul\Shopify\Services\PhaseProgressTracker;

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

        DB::transaction(function () use ($jobTrackId): void {
            $modelClass = JobTrackProxy::modelClass();

            $jobTrack = $modelClass::query()
                ->whereKey($jobTrackId)
                ->lockForUpdate()
                ->first();

            if (! $jobTrack) {
                return;
            }

            if (! $this->phaseProgressTracker->followUpsScheduled((int) $jobTrackId)) {
                return;
            }

            $summary = $jobTrack->summary ?? [];
            $summary['follow_up_phases_finalize_pending'] = true;

            $this->jobTrackRepository->update([
                'state'        => ExportHelper::STATE_PROCESSING,
                'completed_at' => null,
                'summary'      => $summary,
            ], $jobTrackId);
        });
    }
}
