<?php

namespace Webkul\Shopify\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class BulkOperationService
{
    use ShopifyGraphqlRequest;

    public const CORE_PRODUCT_PHASE = 'core_product_sync';

    /**
     * Create a staged upload target for a JSONL bulk mutation file.
     */
    public function createJsonlUploadTarget(array $credential, string $filename): array
    {
        $response = $this->requestGraphQlApiAction('stagedUploadsCreate', $credential, [
            'input' => [[
                'resource' => 'BULK_MUTATION_VARIABLES',
                'filename' => $filename,
                'mimeType' => 'text/jsonl',
                'httpMethod' => 'POST',
            ]],
        ]);

        return $response['body']['data']['stagedUploadsCreate'] ?? [];
    }

    /**
     * Upload JSONL file to Shopify's staged upload target.
     */
    public function uploadJsonlFile(array $target, string $absoluteFilePath): string
    {
        $multipart = [];

        foreach ($target['parameters'] ?? [] as $parameter) {
            $multipart[] = [
                'name' => $parameter['name'],
                'contents' => $parameter['value'],
            ];
        }

        $multipart[] = [
            'name' => 'file',
            'contents' => fopen($absoluteFilePath, 'r'),
            'filename' => basename($absoluteFilePath),
            'headers' => [
                'Content-Type' => 'text/jsonl',
            ],
        ];

        $response = Http::asMultipart()->timeout(300)->post($target['url'], $multipart);

        if ($response->failed()) {
            throw new \RuntimeException('Shopify staged upload failed.');
        }

        return $this->extractStagedUploadPath($target['parameters'] ?? []);
    }

    /**
     * Run a bulk mutation using the uploaded JSONL file.
     */
    public function runMutation(array $credential, string $mutation, string $stagedUploadPath): array
    {
        $response = $this->requestGraphQlApiAction('bulkOperationRunMutation', $credential, [
            'mutation' => $mutation,
            'stagedUploadPath' => $stagedUploadPath,
        ]);

        return $response['body']['data']['bulkOperationRunMutation'] ?? [];
    }

    /**
     * Fetch current Shopify bulk operation status.
     */
    public function getOperation(array $credential, string $operationId): array
    {
        $response = $this->requestGraphQlApiAction('bulkOperationStatus', $credential, [
            'id' => $operationId,
        ]);

        return $response['body']['data']['bulkOperation'] ?? [];
    }

    /**
     * Download a bulk operation result file to local storage.
     */
    public function downloadResult(string $url, string $targetPath): string
    {
        $response = Http::timeout(300)->get($url);

        if ($response->failed()) {
            throw new \RuntimeException('Unable to download Shopify bulk operation result file.');
        }

        Storage::disk('local')->put($targetPath, $response->body());

        return Storage::disk('local')->path($targetPath);
    }

    /**
     * Return the local storage path for a bulk operation input file.
     */
    public function writeJsonl(string $path, array $lines): string
    {
        Storage::disk('local')->put($path, implode("\n", $lines)."\n");

        return Storage::disk('local')->path($path);
    }

    /**
     * Return the local storage path for a JSON manifest file.
     */
    public function writeManifest(string $path, array $payload): string
    {
        Storage::disk('local')->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Storage::disk('local')->path($path);
    }

    /**
     * Read a local JSON manifest file.
     */
    public function readManifest(string $path): array
    {
        $content = Storage::disk('local')->get($path);

        return json_decode($content, true) ?: [];
    }

    /**
     * Extract Shopify's staged upload path from returned parameters.
     */
    protected function extractStagedUploadPath(array $parameters): string
    {
        foreach ($parameters as $parameter) {
            if (($parameter['name'] ?? null) === 'key') {
                return $parameter['value'];
            }
        }

        throw new \RuntimeException('Shopify staged upload path is missing.');
    }
}
