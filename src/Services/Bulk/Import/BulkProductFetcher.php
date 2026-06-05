<?php

namespace Webkul\Shopify\Services\Bulk\Import;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Shopify\Services\BulkOperationService;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

/**
 * Submits Shopify bulkOperationRunQuery passes for the product import, polls
 * each to completion, and downloads the resulting JSONL files locally.
 *
 * Two passes are needed because Shopify caps bulk queries at 5 connections:
 *   - "core"       — products + variants + nested-under-variant data
 *   - "relations"  — product-level media / metafields / collections / publications
 *
 * The two JSONL files are merged by BulkOperationProductIterator using the
 * product id (and __parentId chain) — children land under the same product
 * regardless of which pass produced them.
 */
class BulkProductFetcher
{
    use ShopifyGraphqlRequest;

    public const LOCALE_PLACEHOLDER = '%LOCALE%';

    public const LOCALE_NONE = '__NONE__';

    public const PRODUCT_FILTER_PLACEHOLDER = '%PRODUCT_FILTER%';

    /**
     * Ordered list of bulk-query template config keys to fetch sequentially.
     * Only the first uses the locale placeholder — relations pass has no
     * translations field so the placeholder substitution is a no-op there.
     */
    protected const QUERY_KEYS = [
        'productImportBulkQueryCore',
        'productImportBulkQueryRelations',
    ];

    public function __construct(
        protected BulkOperationService $bulkOperationService,
    ) {}

    /**
     * Run all bulk-query passes and return the local JSONL paths in order.
     *
     * @return array<int,string> absolute file paths
     */
    public function fetch(array $credential, ?string $shopifyLocale, ?string $statusFilter = null): array
    {
        $paths = [];

        foreach (self::QUERY_KEYS as $key) {
            $template = (string) config('shopify_bulk_mutations.'.$key, '');

            if ($template === '') {
                throw new \RuntimeException("Shopify bulk import query template '{$key}' is not configured.");
            }

            $query = $this->resolveQuery($template, $shopifyLocale, $statusFilter);

            $operation = $this->submit($credential, $query);
            $url = $this->pollUntilComplete($credential, $operation['id']);

            $paths[] = empty($url)
                ? $this->writeEmptyJsonl()
                : $this->bulkOperationService->downloadResult($url, $this->relativeJsonlPath());
        }

        return $paths;
    }

    /**
     * Substitute the locale placeholder in the configured query template.
     * If no locale is mapped, %LOCALE% is replaced with a sentinel that yields
     * an empty translations array on Shopify's side.
     */
    protected function resolveQuery(string $template, ?string $shopifyLocale, ?string $statusFilter = null): string
    {
        $locale = $shopifyLocale !== null && $shopifyLocale !== ''
            ? $shopifyLocale
            : self::LOCALE_NONE;

        $template = str_replace(self::LOCALE_PLACEHOLDER, addslashes($locale), $template);

        return str_replace(
            self::PRODUCT_FILTER_PLACEHOLDER,
            $this->buildProductFilterClause($statusFilter),
            $template
        );
    }

    /**
     * Map the import's status filter to a Shopify search-syntax clause for the
     * top-level products connection. Returns the full argument group including
     * parentheses, or an empty string when no filter applies.
     *
     * Only hardcoded literals are emitted — the raw $statusFilter is never
     * interpolated, so there is no query-injection surface. 'disable' is the
     * negation of 'enable' (DRAFT + ARCHIVED) to match Importer::validateRow().
     */
    protected function buildProductFilterClause(?string $statusFilter): string
    {
        $query = match ($statusFilter) {
            'enable' => 'status:active',
            'disable' => 'status:draft OR status:archived',
            default => null,
        };

        return $query === null ? '' : '(query: "'.$query.'")';
    }

    /**
     * Submit a single bulk query. Throws on userErrors.
     *
     * @return array{id: string, status: string}
     */
    protected function submit(array $credential, string $query): array
    {
        $response = $this->requestGraphQlApiAction('bulkOperationRunQuery', $credential, [
            'query' => $query,
        ]);

        $payload = $response['body']['data']['bulkOperationRunQuery'] ?? [];
        $userErrors = $payload['userErrors'] ?? [];

        if (! empty($userErrors)) {
            $msg = $userErrors[0]['message'] ?? 'unknown error';

            throw new \RuntimeException('Shopify bulk import submit rejected: '.$msg);
        }

        $bulkOperation = $payload['bulkOperation'] ?? null;

        if (empty($bulkOperation['id'])) {
            throw new \RuntimeException('Shopify bulk import submit returned no operation id.');
        }

        return [
            'id' => $bulkOperation['id'],
            'status' => $bulkOperation['status'] ?? 'CREATED',
        ];
    }

    /**
     * Poll bulkOperationStatus until the operation reaches a terminal state.
     * Returns the result file URL on COMPLETED (or null on COMPLETED-with-no-rows).
     * Throws on FAILED / CANCELED / EXPIRED / timeout.
     */
    protected function pollUntilComplete(array $credential, string $operationId): ?string
    {
        $delay = max(1, (int) config('shopify-bulk-operations.import_poll_delay_seconds', 5));
        $maxWait = max(60, (int) config('shopify-bulk-operations.import_max_wait_seconds', 1800));
        $deadline = time() + $maxWait;

        while (time() < $deadline) {
            $state = $this->bulkOperationService->getOperation($credential, $operationId);
            $status = strtoupper((string) ($state['status'] ?? ''));

            if (in_array($status, ['CREATED', 'RUNNING', 'CANCELING'], true)) {
                sleep($delay);

                continue;
            }

            if ($status === 'COMPLETED') {
                return $state['url'] ?? null;
            }

            if (in_array($status, ['FAILED', 'CANCELED', 'EXPIRED'], true)) {
                $errorCode = $state['errorCode'] ?? 'unknown';

                throw new \RuntimeException(sprintf(
                    'Shopify bulk import ended in %s state (errorCode=%s).',
                    $status,
                    $errorCode,
                ));
            }

            sleep($delay);
        }

        $this->cancel($credential, $operationId);

        throw new \RuntimeException('Shopify bulk import timed out after '.$maxWait.' seconds.');
    }

    /**
     * Best-effort cancel — used when polling times out.
     */
    protected function cancel(array $credential, string $operationId): void
    {
        try {
            $this->requestGraphQlApiAction('bulkOperationCancel', $credential, [
                'id' => $operationId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Shopify bulk import cancel failed', [
                'operationId' => $operationId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Shopify returns an empty `url` when the bulk op produced zero rows.
     * Write an empty JSONL so the iterator can open it normally.
     */
    protected function writeEmptyJsonl(): string
    {
        $relativePath = $this->relativeJsonlPath();
        Storage::disk('local')->put($relativePath, '');

        return Storage::disk('local')->path($relativePath);
    }

    protected function relativeJsonlPath(): string
    {
        return sprintf('shopify/imports/%s.jsonl', Str::uuid());
    }
}
