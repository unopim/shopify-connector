<?php

namespace Webkul\Shopify\Services\Bulk\Files;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Services\Bulk\Media\AssetUrlResolver;
use Webkul\Shopify\Services\BulkOperationService;
use Webkul\Shopify\Services\ShopifyClientFactory;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;
use Webkul\Shopify\Traits\StagesShopifyAsset;

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
    use StagesShopifyAsset;

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

            $cached = $this->cachedGid($path, $shopUrl, $value['content_type']);
            if ($cached) {
                $map[$path] = $cached;

                continue;
            }

            $toUpload[$path] = [
                'content_type' => $value['content_type'],
                'url'          => $value['url'] ?? $this->resolveUrl($path),
                'asset'        => $value['asset'] ?? null,
            ];
        }

        if (! empty($toUpload)) {
            $created = $this->clientFactory->isSaas($credential)
                ? $this->createFilesViaBulk($toUpload, $credential)
                : $this->createFilesSync($toUpload, $credential);

            foreach ($created as $path => $gid) {
                $map[$path] = $gid;
                $this->cacheGid($path, $gid, $jobInstanceId, $shopUrl, $toUpload[$path]['content_type']);
            }
        }

        return $map;
    }

    private function cachedGid(string $path, string $shopUrl, string $contentType): ?string
    {
        return $this->mappingRepository->findOneWhere([
            ['entityType', '=', self::ENTITY_TYPE],
            ['code', '=', $path],
            ['apiUrl', '=', $shopUrl],
            ['relatedSource', '=', $contentType],
        ])?->externalId;
    }

    private function cacheGid(string $path, string $gid, int $jobInstanceId, string $shopUrl, string $contentType): void
    {
        $this->mappingRepository->create([
            'entityType'    => self::ENTITY_TYPE,
            'code'          => $path,
            'externalId'    => $gid,
            'relatedSource' => $contentType,
            'jobInstanceId' => $jobInstanceId,
            'apiUrl'        => $shopUrl,
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
     * @param  array<string, array{content_type: string, url: string}>  $toUpload  path => {content type, url}
     * @return array<string, string> path => File GID
     */
    private function createFilesSync(array $toUpload, array $credential): array
    {
        $map = [];
        $files = [];

        foreach ($toUpload as $path => $meta) {
            if (! empty($meta['asset'])) {
                $source = $this->stageAssetUpload($meta['asset'], $credential);

                if ($source) {
                    $files[] = [
                        'alt'            => $path,
                        'contentType'    => $meta['content_type'],
                        'originalSource' => $source,
                    ];
                }

                continue;
            }

            // Shopify rejects video external URLs; each video must be staged
            // uploaded on its own so one bad video never fails the image batch.
            if (($meta['content_type'] ?? '') === 'VIDEO') {
                $gid = $this->createVideoViaStaged($path, $credential);
                if ($gid) {
                    $map[$path] = $gid;
                }

                continue;
            }

            $url = $meta['url'] ?? '';
            if ($url === '') {
                continue;
            }

            $files[] = [
                'alt'            => $path,
                'contentType'    => $meta['content_type'],
                'originalSource' => $url,
            ];
        }

        foreach (array_chunk($files, 250) as $chunk) {
            $response = $this->requestGraphQlApiAction('fileCreate', $credential, ['files' => $chunk]);

            $errors = $response['body']['data']['fileCreate']['userErrors'] ?? [];
            if (! empty($errors)) {
                logger()->warning('Shopify fileCreate error', ['errors' => $errors]);
            }

            foreach ($response['body']['data']['fileCreate']['files'] ?? [] as $file) {
                if (! empty($file['alt']) && ! empty($file['id'])) {
                    $map[$file['alt']] = $file['id'];
                }
            }
        }

        $this->waitUntilReady(array_values($map), $credential);

        return $map;
    }

    /**
     * Create a single Shopify Video file via staged upload — the only path the
     * Files API accepts for videos (external URLs error as "Invalid video url").
     * Returns the File GID, or null when the upload could not complete.
     */
    private function createVideoViaStaged(string $path, array $credential): ?string
    {
        $resourceUrl = $this->stageVideoUpload($path, $credential);
        if (! $resourceUrl) {
            return null;
        }

        $created = $this->requestGraphQlApiAction('fileCreate', $credential, [
            'files' => [[
                'alt'            => $path,
                'contentType'    => 'VIDEO',
                'originalSource' => $resourceUrl,
            ]],
        ]);

        $errors = $created['body']['data']['fileCreate']['userErrors'] ?? [];
        if (! empty($errors)) {
            logger()->warning('Shopify video fileCreate error', ['path' => $path, 'errors' => $errors]);

            return null;
        }

        return $created['body']['data']['fileCreate']['files'][0]['id'] ?? null;
    }

    /**
     * Stage a local video to Shopify (stagedUploadsCreate + byte upload) and
     * return the staged resourceUrl for use as a fileCreate/bulk originalSource.
     * Works on manual and SaaS — stagedUploadsCreate is exposed on the proxy.
     */
    private function stageVideoUpload(string $path, array $credential): ?string
    {
        $disk = Storage::disk();

        if (! $disk->exists($path)) {
            logger()->warning('Shopify video upload skipped, file missing', ['path' => $path]);

            return null;
        }

        $staged = $this->requestGraphQlApiAction('stagedUploadsCreate', $credential, [
            'input' => [[
                'resource' => 'VIDEO',
                'filename' => basename($path),
                'mimeType' => $this->videoMimeType($path),
                'fileSize' => (string) $disk->size($path),
            ]],
        ]);

        $target = $staged['body']['data']['stagedUploadsCreate']['stagedTargets'][0] ?? null;
        if (empty($target['url']) || empty($target['resourceUrl'])) {
            logger()->warning('Shopify staged upload target missing', [
                'path'   => $path,
                'errors' => $staged['body']['data']['stagedUploadsCreate']['userErrors'] ?? [],
            ]);

            return null;
        }

        $multipart = [];
        foreach ($target['parameters'] as $param) {
            $multipart[] = ['name' => $param['name'], 'contents' => $param['value']];
        }
        $multipart[] = ['name' => 'file', 'contents' => $disk->readStream($path), 'filename' => basename($path)];

        $upload = Http::asMultipart()->post($target['url'], $multipart);
        if ($upload->failed()) {
            logger()->warning('Shopify staged video upload failed', ['path' => $path, 'status' => $upload->status()]);

            return null;
        }

        return $target['resourceUrl'];
    }

    /**
     * Best-effort video MIME type from a stored path's extension.
     */
    private function videoMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mov'   => 'video/quicktime',
            'webm'  => 'video/webm',
            default => 'video/mp4',
        };
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
            $contentType = $meta['content_type'] ?? '';

            if (! empty($meta['asset'])) {
                $source = $this->stageAssetUpload($meta['asset'], $credential);
            } elseif ($contentType === 'VIDEO') {
                $source = $this->stageVideoUpload($path, $credential);
            } else {
                $source = $meta['url'] ?? '';
            }

            if (empty($source)) {
                continue;
            }

            $lines[] = json_encode(['files' => [[
                'originalSource' => $source,
                'contentType'    => $contentType,
                'alt'            => $path,
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
