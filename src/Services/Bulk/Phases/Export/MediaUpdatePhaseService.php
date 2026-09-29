<?php

namespace Webkul\Shopify\Services\Bulk\Phases\Export;

use Illuminate\Support\Facades\Storage;
use Webkul\Shopify\Services\Bulk\Phases\BasePhaseService;

class MediaUpdatePhaseService extends BasePhaseService
{
    /**
     * Code-refresh plan read from the media phase's payload file; surfaced in the
     * manifest so BulkResultFinalizer can rewrite each mapping's `code` on success.
     *
     * @var array<string, array{rowId: int, code: string}>
     */
    protected array $pendingPlan = [];

    protected function buildPayloadLines(array $operationData): array
    {
        $payload = $this->readPendingUpdate();
        $this->pendingPlan = $payload['plan'] ?? [];

        return $payload['lines'] ?? [];
    }

    protected function getExtraManifestData(array $operationData): array
    {
        return ['media_update_plan' => $this->pendingPlan];
    }

    /**
     * Read the update payload the media phase persisted for this core op.
     */
    protected function readPendingUpdate(): array
    {
        $path = sprintf(
            'shopify/bulk/%s/media_update_%s.json',
            $this->manifest['job_track_id'] ?? '',
            $this->coreBulkOperation->id
        );

        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        return $this->bulkOperationService->readManifest($path);
    }

    protected function getPhaseName(): string
    {
        return 'media_update';
    }

    protected function getMutationKey(): string
    {
        return 'productUpdateMediaBulk';
    }

    protected function getManifestMutationName(): string
    {
        return 'productUpdateMedia';
    }
}
