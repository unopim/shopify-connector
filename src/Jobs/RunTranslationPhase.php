<?php

namespace Webkul\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Services\BulkOperationResultReader;
use Webkul\Shopify\Services\TranslationPhaseService;

class RunTranslationPhase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $bulkOperationId) {}

    public function handle(
        ShopifyBulkOperationRepository $repository,
        BulkOperationResultReader $resultReader,
        TranslationPhaseService $phaseService,
    ): void {
        $bulkOperation = $repository->find($this->bulkOperationId);

        if (! $bulkOperation) {
            return;
        }

        $result = $phaseService->handle($bulkOperation, $resultReader->read($bulkOperation));
        $this->storeResult($bulkOperation, $result);
    }

    protected function storeResult(object $bulkOperation, array $result): void
    {
        $meta = $bulkOperation->meta ?? [];
        $meta['phase_results']['translations'] = $result;
        $bulkOperation->meta = $meta;
        $bulkOperation->save();
    }
}
