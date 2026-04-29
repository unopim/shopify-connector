<?php

namespace Webkul\Shopify\Services;

use Illuminate\Support\Facades\DB;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Models\JobTrackProxy;
use Webkul\DataTransfer\Repositories\JobTrackRepository;
use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;

/**
 * Tracks Shopify follow-up phase progress for a JobTrack.
 *
 * Storage layout:
 * - Counter lives on each core bulk op's meta as `unfinished_phase_jobs` so it
 *   survives Export::completed() (which wipes JobTrack.summary). Total = sum
 *   across all core ops for the JobTrack.
 * - JobTrack.summary.current_phase is a best-effort UI hint; if Export::completed
 *   wipes it, the next phase write restores it.
 * - JobTrack.summary.follow_up_phases_finalize_pending is set by the deferral
 *   listener so the last markFinishedForCore knows to flip state back to completed.
 */
class PhaseProgressTracker
{
    public const PHASES_PER_BATCH = 4;

    public function __construct(
        protected JobTrackRepository $jobTrackRepository,
        protected ShopifyBulkOperationRepository $bulkOperationRepository,
    ) {}

    /**
     * Bump the per-core-op counter when a batch dispatches its phase jobs.
     */
    public function registerPhaseJobsForCore(int $coreBulkOpId, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        DB::transaction(function () use ($coreBulkOpId, $count) {
            $coreOp = $this->lockBulkOp($coreBulkOpId);

            if (! $coreOp) {
                return;
            }

            $meta = $coreOp->meta ?? [];
            $meta['unfinished_phase_jobs'] = (int) ($meta['unfinished_phase_jobs'] ?? 0) + $count;

            $coreOp->meta = $meta;
            $coreOp->save();
        });
    }

    /**
     * UI hint: write the currently running phase name to summary.
     * Best-effort; concurrent jobs may overwrite each other.
     */
    public function markStarted(?int $jobTrackId, string $phase): void
    {
        if (! $jobTrackId) {
            return;
        }

        DB::transaction(function () use ($jobTrackId, $phase) {
            $jobTrack = $this->lockJobTrack($jobTrackId);

            if (! $jobTrack) {
                return;
            }

            $summary = $jobTrack->summary ?? [];
            $summary['current_phase'] = $phase;

            $this->jobTrackRepository->update(['summary' => $summary], $jobTrackId);
        });
    }

    /**
     * Decrement a core op's counter when a phase work unit settles.
     *
     * If the JobTrack-wide total reaches zero AND the listener has flagged
     * finalize_pending, flip state back to completed.
     */
    public function markFinishedForCore(int $coreBulkOpId, ?int $jobTrackId, string $phase): void
    {
        if (! $jobTrackId) {
            return;
        }

        DB::transaction(function () use ($coreBulkOpId, $jobTrackId, $phase) {
            $coreOp = $this->lockBulkOp($coreBulkOpId);

            if ($coreOp) {
                $meta = $coreOp->meta ?? [];
                $meta['unfinished_phase_jobs'] = max(0, (int) ($meta['unfinished_phase_jobs'] ?? 0) - 1);
                $coreOp->meta = $meta;
                $coreOp->save();
            }

            $remainingTotal = $this->totalUnfinishedForJobTrack($jobTrackId);

            $jobTrack = $this->lockJobTrack($jobTrackId);

            if (! $jobTrack) {
                return;
            }

            $summary = $jobTrack->summary ?? [];

            if (($summary['current_phase'] ?? null) === $phase) {
                $summary['current_phase'] = $remainingTotal > 0 ? $phase : null;
            }

            $update = ['summary' => $summary];

            $finalizePending = ! empty($summary['follow_up_phases_finalize_pending']);
            if ($remainingTotal === 0 && $finalizePending && $jobTrack->state !== ExportHelper::STATE_COMPLETED) {
                $summary['follow_up_phases_finalize_pending'] = false;
                $update['summary'] = $summary;
                $update['state'] = ExportHelper::STATE_COMPLETED;
                $update['completed_at'] = now();
            }

            $this->jobTrackRepository->update($update, $jobTrackId);
        });
    }

    /**
     * Sum of unfinished_phase_jobs across every core bulk op tied to this JobTrack.
     * Source of truth used by both the deferral listener and markFinishedForCore.
     */
    public function totalUnfinishedForJobTrack(int $jobTrackId): int
    {
        $coreOps = ShopifyBulkOperation::query()
            ->where('job_track_id', $jobTrackId)
            ->where(function ($q) {
                $q->where('phase', BulkOperationService::CORE_PRODUCT_PHASE)->orWhereNull('phase');
            })
            ->get(['id', 'meta']);

        return (int) $coreOps->sum(fn ($op) => (int) (($op->meta ?? [])['unfinished_phase_jobs'] ?? 0));
    }

    /**
     * Whether any core bulk op for this JobTrack actually scheduled follow-ups.
     */
    public function followUpsScheduled(int $jobTrackId): bool
    {
        return ShopifyBulkOperation::query()
            ->where('job_track_id', $jobTrackId)
            ->where(function ($q) {
                $q->where('phase', BulkOperationService::CORE_PRODUCT_PHASE)->orWhereNull('phase');
            })
            ->get(['meta'])
            ->contains(fn ($op) => ! empty(($op->meta ?? [])['follow_up_phases_enabled']));
    }

    protected function lockJobTrack(int $jobTrackId)
    {
        $modelClass = JobTrackProxy::modelClass();

        return $modelClass::query()
            ->whereKey($jobTrackId)
            ->lockForUpdate()
            ->first();
    }

    protected function lockBulkOp(int $coreBulkOpId): ?ShopifyBulkOperation
    {
        return ShopifyBulkOperation::query()
            ->whereKey($coreBulkOpId)
            ->lockForUpdate()
            ->first();
    }
}
