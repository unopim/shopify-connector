<?php

namespace Webkul\Shopify\Services\Import;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;

class DamAssetImporter
{
    public function __construct(protected ShopifyMappingRepository $mappingRepository) {}

    public function importFromUrl(string $url, string $shopUrl = '', string $subdir = 'Shopify'): ?int
    {
        $assetClass = sprintf('%s\\%s', 'Webkul\\DAM\\Models', 'Asset');
        $directoryClass = sprintf('%s\\%s', 'Webkul\\DAM\\Models', 'Directory');

        if ($url === '' || ! class_exists($assetClass) || ! class_exists($directoryClass)) {
            return null;
        }

        $dedupKey = md5(strtok($url, '?'));

        $cached = $this->mappingRepository->findOneWhere([
            'entityType' => 'shopifyFileAsset',
            'code'       => $dedupKey,
            'apiUrl'     => $shopUrl,
        ]);

        if ($cached && $cached->externalId && $assetClass::whereKey($cached->externalId)->exists()) {
            return (int) $cached->externalId;
        }

        try {
            $response = Http::timeout(120)->get($url);

            if ($response->failed()) {
                return null;
            }

            $rootId = $directoryClass::whereNull('parent_id')->orderBy('id')->value('id');
            $directory = $directoryClass::firstOrCreate(['name' => $subdir, 'parent_id' => $rootId]);

            $body = $response->body();
            $mimeType = strtok((string) $response->header('Content-Type'), ';') ?: 'application/octet-stream';
            $fileName = $this->buildFileName($url, $mimeType);
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $relativePath = $directoryClass::ASSETS_DIRECTORY.'/'.$directory->generatePath().'/'.$fileName;

            Storage::disk($directoryClass::getAssetDisk())->put($relativePath, $body);

            $asset = $assetClass::create([
                'file_name' => $fileName,
                'file_type' => $this->fileType($mimeType),
                'file_size' => strlen($body),
                'mime_type' => $mimeType,
                'extension' => $extension,
                'path'      => $relativePath,
            ]);

            $directory->assets()->attach($asset->id);

            $assetUploadJob = sprintf('%s\\%s', 'Webkul\\DAM\\Jobs', 'ProcessAssetUpload');
            $dispatcher = sprintf('%s\\Contracts\\Bus\\Dispatcher', 'Illuminate');

            if (class_exists($assetUploadJob)) {
                resolve($dispatcher)->dispatch(new $assetUploadJob($asset->id));
            }

            $this->mappingRepository->create([
                'entityType'    => 'shopifyFileAsset',
                'code'          => $dedupKey,
                'externalId'    => (string) $asset->id,
                'jobInstanceId' => 0,
                'apiUrl'        => $shopUrl,
            ]);

            return $asset->id;
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildFileName(string $url, string $mimeType): string
    {
        $name = explode('?', basename($url))[0];

        if ($name === '' || ! str_contains($name, '.')) {
            $name = ($name !== '' ? $name : 'asset').'.'.$this->extensionForMime($mimeType);
        }

        return bin2hex(random_bytes(6)).'-'.$name;
    }

    private function fileType(string $mimeType): string
    {
        return match (true) {
            str_contains($mimeType, 'image') => 'image',
            str_contains($mimeType, 'video') => 'video',
            str_contains($mimeType, 'audio') => 'audio',
            default                          => 'document',
        };
    }

    private function extensionForMime(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'video/mp4'  => 'mp4',
            default      => 'bin',
        };
    }
}
