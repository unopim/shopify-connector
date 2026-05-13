<?php

namespace Webkul\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Async download of a Shopify product image to the public disk.
 *
 * The Shopify importer computes the deterministic storage path during the import
 * batch and queues this job to fetch the bytes in the background — turning the
 * 30-second-per-image worst case into a non-blocking dispatch.
 */
class DownloadShopifyImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        protected string $imageUrl,
        protected string $storagePath,
        protected string $disk = 'public',
    ) {}

    /**
     * Use a wall-clock deadline instead of a tries-based limit. Without this,
     * a worker that takes longer than the queue's `retry_after` (default 90s)
     * gets the job re-issued to another worker, which then marks it failed
     * via markJobAsFailedIfAlreadyExceedsMaxAttempts. Using retryUntil bypasses
     * that race — the job is only failed after the deadline regardless of how
     * many times it gets picked up.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(): void
    {
        if (Storage::disk($this->disk)->exists($this->storagePath)) {
            return;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; UnoPIM/1.0; +https://unopim.com)',
            ])
                ->withoutVerifying()
                ->timeout(30)
                ->retry(2, 1000, throw: false)
                ->get($this->imageUrl);

            if (! $response->successful()) {
                Log::warning('Shopify image download failed', [
                    'url' => $this->imageUrl,
                    'status' => $response->status(),
                    'path' => $this->storagePath,
                ]);

                return;
            }

            Storage::disk($this->disk)->put($this->storagePath, $response->body());
        } catch (\Throwable $e) {
            Log::warning('Shopify image download exception', [
                'url' => $this->imageUrl,
                'path' => $this->storagePath,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
