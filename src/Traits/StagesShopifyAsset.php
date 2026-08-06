<?php

namespace Webkul\Shopify\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Webkul\DAM\Models\Directory;

trait StagesShopifyAsset
{
    protected function stageAssetUpload(array $asset, array $credential): ?string
    {
        if (empty($asset) || empty($credential) || ! class_exists(Directory::class)) {
            return null;
        }

        $path = $asset['path'] ?? null;

        if (empty($path)) {
            return null;
        }

        $mimeType = $asset['mime_type'] ?? 'application/octet-stream';
        $filename = $asset['file_name'] ?? basename((string) $path);
        $resource = str_starts_with($mimeType, 'image/')
            ? 'IMAGE'
            : (str_starts_with($mimeType, 'video/') ? 'VIDEO' : 'FILE');

        try {
            $response = $this->requestGraphQlApiAction('stagedUploadsCreate', $credential, [
                'input' => [
                    'filename' => $filename,
                    'mimeType' => $mimeType,
                    'resource' => $resource,
                    'fileSize' => (string) ($asset['file_size'] ?? 0),
                ],
            ]);

            $target = $response['body']['data']['stagedUploadsCreate']['stagedTargets'][0] ?? null;

            if (! $target || empty($target['url'])) {
                return null;
            }

            $disk = Directory::getAssetDisk();

            if (! Storage::disk($disk)->exists($path)) {
                return null;
            }

            if (str_contains($target['url'], 'X-Goog-Signature') || str_contains($target['url'], 'GoogleAccessId')) {
                $upload = Http::withBody(Storage::disk($disk)->get($path), $mimeType)
                    ->timeout(300)
                    ->put($target['url']);

                return $upload->failed() ? null : ($target['resourceUrl'] ?? null);
            }

            $multipart = [];

            foreach ($target['parameters'] ?? [] as $param) {
                $multipart[] = ['name' => $param['name'], 'contents' => $param['value']];
            }

            $stream = Storage::disk($disk)->readStream($path);

            if ($stream === false) {
                return null;
            }

            try {
                $multipart[] = [
                    'name'     => 'file',
                    'contents' => $stream,
                    'filename' => $filename,
                    'headers'  => ['Content-Type' => $mimeType],
                ];

                $upload = Http::asMultipart()->timeout(300)->post($target['url'], $multipart);

                if ($upload->failed()) {
                    return null;
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return $target['resourceUrl'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
