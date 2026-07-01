<?php

namespace Webkul\Shopify\Services\Bulk\Files;

use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Services\Bulk\Media\AssetUrlResolver;
use Webkul\Shopify\Services\BulkOperationService;
use Webkul\Shopify\Services\ShopifyClientFactory;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

/**
 * Uploads UnoPim file/image/video metafield values to Shopify and returns a
 * `assetPath => File GID` map for the product exporter to inject.
 *
 * Manual credentials create each file directly via `fileCreate`. SaaS routes
 * through the proxy (which does not expose `fileCreate`), so its files are
 * created with one `bulkOperationRunMutation` over a staged JSONL — the same
 * bulk pieces the proxy already exposes for product export.
 *
 * Both paths dedupe within a run and cache GIDs in wk_shopify_data_mapping
 * (entityType `shopify_file`) so the same asset is never re-uploaded.
 */
class FileReferenceUploader
{
    use ShopifyGraphqlRequest;

    private const ENTITY_TYPE = 'shopify_file';

    private const POLL_ATTEMPTS = 30;

    private const POLL_SLEEP_SECONDS = 2;

    public function __construct(
        protected ShopifyClientFactory $clientFactory,
        protected AssetUrlResolver $assetUrlResolver,
        protected ShopifyMappingRepository $mappingRepository,
        protected BulkOperationService $bulkOperationService,
    ) {}

    /**
     * @param  array<int, array{path: string, content_type: string}>  $fileValues  unique asset values across the export
     * @param  array  $credential  toApiArray() credential
     * @return array<string, string> assetPath => File GID
     */
    public function buildGidMap(array $fileValues, array $credential, int $jobInstanceId): array
    {
        if (empty($fileValues)) {
            return [];
        }

        $map = [];
        $toUpload = [];
        $shopUrl = $credential['shopUrl'] ?? '';

        foreach ($fileValues as $value) {
            $path = $value['path'];
            if ($path === '' || isset($map[$path]) || isset($toUpload[$path])) {
                continue;
            }

            $cached = $this->cachedGid($path, $shopUrl);
            if ($cached) {
                $map[$path] = $cached;

                continue;
            }

            $toUpload[$path] = [
                'content_type' => $value['content_type'],
                'url' => $value['url'] ?? $this->resolveUrl($path),
            ];
        }

        if (! empty($toUpload)) {
            $created = $this->clientFactory->isSaas($credential)
                ? $this->createFilesViaBulk($toUpload, $credential)
                : $this->createFilesSync($toUpload, $credential);

            foreach ($created as $path => $gid) {
                $map[$path] = $gid;
                $this->cacheGid($path, $gid, $jobInstanceId, $shopUrl);
            }
        }

        return $map;
    }

    private function cachedGid(string $path, string $shopUrl): ?string
    {
        return $this->mappingRepository->findOneWhere([
            ['entityType', '=', self::ENTITY_TYPE],
            ['code', '=', $path],
            ['apiUrl', '=', $shopUrl],
        ])?->externalId;
    }

    private function cacheGid(string $path, string $gid, int $jobInstanceId, string $shopUrl): void
    {
        $this->mappingRepository->create([
            'entityType' => self::ENTITY_TYPE,
            'code' => $path,
            'externalId' => $gid,
            'jobInstanceId' => $jobInstanceId,
            'apiUrl' => $shopUrl,
        ]);
    }

    /**
     * Resolve a stored asset path to a public URL Shopify can fetch.
     */
    private function resolveUrl(string $path): string
    {
        return $this->assetUrlResolver->resolveMedia($path)['url'] ?? '';
    }

    /**
     * Manual transport — create each file directly and wait until ready.
     *
     * @param  array<string, array{content_type: string, url: string}>  $toUpload  path => {content type, url}
     * @return array<string, string> path => File GID
     */
    private function createFilesSync(array $toUpload, array $credential): array
    {
        $map = [];

        foreach ($toUpload as $path => $meta) {
            $url = $meta['url'] ?? '';
            if ($url === '') {
                continue;
            }

            $response = $this->requestGraphQlApiAction('fileCreate', $credential, [
                'files' => [[
                    'alt' => $path,
                    'contentType' => $meta['content_type'],
                    'originalSource' => $url,
                ]],
            ]);

            $errors = $response['body']['data']['fileCreate']['userErrors'] ?? [];
            if (! empty($errors)) {
                logger()->warning('Shopify fileCreate error', ['path' => $path, 'errors' => $errors]);

                continue;
            }

            $gid = $response['body']['data']['fileCreate']['files'][0]['id'] ?? null;
            if ($gid) {
                $map[$path] = $gid;
            }
        }

        $this->waitUntilReady(array_values($map), $credential);

        return $map;
    }

    /**
     * SaaS transport — create all files with one bulk `fileCreate` operation
     * (`alt` carries the asset path so the result maps back to it).
     *
     * @param  array<string, array{content_type: string, url: string}>  $toUpload  path => {content type, url}
     * @return array<string, string> path => File GID
     */
    private function createFilesViaBulk(array $toUpload, array $credential): array
    {
        $lines = [];
        foreach ($toUpload as $path => $meta) {
            $url = $meta['url'] ?? '';
            if ($url === '') {
                continue;
            }
            $lines[] = json_encode(['files' => [[
                'originalSource' => $url,
                'contentType' => $meta['content_type'],
                'alt' => $path,
            ]]]);
        }

        if (empty($lines)) {
            return [];
        }

        $mutation = config('shopify_bulk_mutations.fileCreateBulk');

        try {
            $jsonlPath = $this->bulkOperationService->writeJsonl('shopify/filecreate-'.uniqid().'.jsonl', $lines);
            $target = $this->bulkOperationService->createJsonlUploadTarget($credential, 'filecreate.jsonl');
            if (empty($target['url'])) {
                return [];
            }

            $stagedPath = $this->bulkOperationService->uploadJsonlFile($target, $jsonlPath);
            $result = $this->bulkOperationService->runMutation($credential, $mutation, $stagedPath);

            $opId = $result['bulkOperation']['id'] ?? null;
            if (! $opId) {
                logger()->warning('Shopify bulk fileCreate rejected', ['userErrors' => $result['userErrors'] ?? []]);

                return [];
            }

            $op = $this->pollBulk($opId, $credential);
            if (($op['status'] ?? '') !== 'COMPLETED' || empty($op['url'])) {
                logger()->warning('Shopify bulk fileCreate did not complete', ['op' => $op]);

                return [];
            }

            $local = $this->bulkOperationService->downloadResult($op['url'], 'shopify/filecreate-result-'.uniqid().'.jsonl');

            return $this->parseBulkResult($local);
        } catch (\Throwable $e) {
            logger()->warning('Shopify bulk fileCreate failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Poll a bulk operation until it reaches a terminal state.
     *
     * @return array the final bulk operation node (empty on timeout)
     */
    private function pollBulk(string $operationId, array $credential): array
    {
        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            $op = $this->bulkOperationService->getOperation($credential, $operationId);
            if (in_array($op['status'] ?? '', ['COMPLETED', 'FAILED', 'CANCELED'], true)) {
                return $op;
            }
            sleep(self::POLL_SLEEP_SECONDS);
        }

        return [];
    }

    /**
     * Map each bulk-result line's `alt` (the asset path) to its created File GID.
     *
     * @return array<string, string> path => File GID
     */
    private function parseBulkResult(string $localPath): array
    {
        $map = [];

        foreach (file($localPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $files = json_decode($line, true)['data']['fileCreate']['files'] ?? [];
            foreach ($files as $file) {
                if (! empty($file['alt']) && ! empty($file['id'])) {
                    $map[$file['alt']] = $file['id'];
                }
            }
        }

        return $map;
    }

    /**
     * Poll the given GIDs until none are PROCESSING or attempts are exhausted.
     *
     * @param  array<int, string>  $gids
     */
    private function waitUntilReady(array $gids, array $credential): void
    {
        if (empty($gids)) {
            return;
        }

        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            $response = $this->requestGraphQlApiAction('getFileById', $credential, ['ids' => $gids]);
            $nodes = $response['body']['data']['nodes'] ?? [];

            $stillProcessing = false;
            foreach ($nodes as $node) {
                if (($node['fileStatus'] ?? 'READY') === 'PROCESSING') {
                    $stillProcessing = true;
                    break;
                }
            }

            if (! $stillProcessing) {
                return;
            }

            sleep(self::POLL_SLEEP_SECONDS);
        }
    }
}
