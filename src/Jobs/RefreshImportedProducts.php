<?php

namespace Webkul\Shopify\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Webkul\Completeness\Jobs\ProductCompletenessJob;
use Webkul\ElasticSearch\Observers\Product;
use Webkul\Product\Models\ProductProxy;

class RefreshImportedProducts implements ShouldQueue
{
    use Queueable;

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

    /**
     * Reindex the imported products, ignoring single row failures so the rest of the batch still indexes.
     */
    public function handle(): void
    {
        $productIds = array_values(array_unique(array_filter($this->productIds)));
        if ($productIds === []) {
            return;
        }

        $shouldReindex = $this->reindex
            && config('elasticsearch.enabled')
            && class_exists(Product::class);

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
                            ->each(function (Model $product): void {
                                try {
                                    $product->touch();
                                } catch (\Throwable) {
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
                    dispatch(new ProductCompletenessJob($chunk));
                } catch (\Throwable $e) {
                    Log::warning('Shopify post-import completeness dispatch failed', [
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
