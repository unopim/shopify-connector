<?php

namespace Webkul\Shopify\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadShopifyImage implements ShouldQueue
{
    use Queueable;

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
                    'url'    => $this->imageUrl,
                    'status' => $response->status(),
                    'path'   => $this->storagePath,
                ]);

                return;
            }

            Storage::disk($this->disk)->put($this->storagePath, $response->body());
        } catch (\Throwable $e) {
            Log::warning('Shopify image download exception', [
                'url'     => $this->imageUrl,
                'path'    => $this->storagePath,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
