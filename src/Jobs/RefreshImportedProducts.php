<?php

namespace Webkul\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Webkul\Completeness\Jobs\ProductCompletenessJob;
use Webkul\ElasticSearch\Observers\Product;
use Webkul\Product\Models\ProductProxy;

/**
 * Post-batch fan-out job that runs the per-product side-effects we suppressed
 * inline during a Shopify product import:
 *
 *  - Completeness recalculation (one ProductCompletenessJob per chunk of IDs)
 *  - Elasticsearch indexing (if enabled)
 *
 * Mirrors the exporter's PhaseOrchestrator pattern (publish/inventory/translation
 * phases dispatched in parallel after the bulk operation submission).
 */
class RefreshImportedProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        protected array $productIds,
        protected bool $recomputeCompleteness = true,
        protected bool $reindex = true,
    ) {}

    /**
     * Wall-clock deadline so the job is not falsely marked failed by a second
     * worker if it runs longer than the queue's `retry_after` window (default
     * 90s) — which is very possible for an import that touched many products.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(): void
    {
        $productIds = array_values(array_unique(array_filter($this->productIds)));
        if (empty($productIds)) {
            return;
        }

        $shouldReindex = $this->reindex
            && config('elasticsearch.enabled')
            && class_exists(Product::class);

        // While we touch() each product for ES reindexing, suppress the
        // completeness observer so it doesn't queue a redundant per-product
        // ProductCompletenessJob in addition to the explicit chunked dispatch
        // below. Always re-enable in finally{} even if a touch throws.
        $disabledCompletenessObserver = false;

        try {
            if ($shouldReindex
                && class_exists(\Webkul\Completeness\Observers\Product::class)
                && method_exists(\Webkul\Completeness\Observers\Product::class, 'isEnabled')
                && \Webkul\Completeness\Observers\Product::isEnabled()
            ) {
                \Webkul\Completeness\Observers\Product::disable();
                $disabledCompletenessObserver = true;
            }

            if ($shouldReindex) {
                try {
                    foreach (array_chunk($productIds, 50) as $chunk) {
                        ProductProxy::query()
                            ->whereIn('id', $chunk)
                            ->get()
                            ->each(function ($product) {
                                try {
                                    $product->touch();
                                } catch (\Throwable) {
                                    // ignore single-row failures so the rest of the batch indexes
                                }
                            });
                    }
                } catch (\Throwable $e) {
                    Log::warning('Shopify post-import reindex failed', [
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            if ($disabledCompletenessObserver) {
                \Webkul\Completeness\Observers\Product::enable();
            }
        }

        if ($this->recomputeCompleteness && class_exists(ProductCompletenessJob::class)) {
            foreach (array_chunk($productIds, 100) as $chunk) {
                try {
                    ProductCompletenessJob::dispatch($chunk);
                } catch (\Throwable $e) {
                    Log::warning('Shopify post-import completeness dispatch failed', [
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
