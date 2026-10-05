<?php

namespace Webkul\Shopify\Listeners;

use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\DataTransfer\Repositories\JobTrackRepository;
use Webkul\Shopify\Contracts\ReportsUpdatedCount;

class ExportUpdatedSummary
{
    public function __construct(
        protected JobTrackRepository $jobTrackRepository,
        protected JobTrackBatchRepository $jobTrackBatchRepository,
    ) {}

    /**
     * Carry the `updated` count of an export into its finished job summary.
     *
     * Core totals the batches from three fixed keys when the export completes,
     * so the count they recorded would be dropped and the tracker would report
     * every re-exported record as newly created. Only exporters that report
     * the count are totalled again; every other summary is core's own.
     */
    public function completed(object $export): void
    {
        $entityType = $export->jobInstance->entity_type ?? '';

        if (! is_a((string) config('exporters.'.$entityType.'.exporter'), ReportsUpdatedCount::class, true)) {
            return;
        }

        $totals = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($this->jobTrackBatchRepository->findWhere(['job_track_id' => $export->id]) as $batch) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (int) ($batch->summary[$key] ?? 0);
            }
        }

        $this->jobTrackRepository->update(['summary' => array_merge((array) ($export->summary ?? []), $totals)], $export->id);
    }
}
