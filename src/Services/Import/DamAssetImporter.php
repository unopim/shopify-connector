<?php

namespace Webkul\Shopify\Services\Import;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Webkul\DAM\Jobs\ProcessAssetUpload;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\Directory;

class DamAssetImporter
{
    public function importFromUrl(string $url, string $prefix = '', string $subdir = 'Shopify'): ?int
    {
        if ($url === '' || ! class_exists(Asset::class)) {
            return null;
        }

        try {
            $response = Http::timeout(120)->get($url);

            if ($response->failed()) {
                return null;
            }

            $rootId = Directory::whereNull('parent_id')->orderBy('id')->value('id');
            $directory = Directory::firstOrCreate(['name' => $subdir, 'parent_id' => $rootId]);

            $body = $response->body();
            $mimeType = strtok((string) $response->header('Content-Type'), ';') ?: 'application/octet-stream';
            $fileName = $this->buildFileName($url, $prefix, $mimeType);
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $relativePath = Directory::ASSETS_DIRECTORY.'/'.$directory->generatePath().'/'.$fileName;

            Storage::disk(Directory::getAssetDisk())->put($relativePath, $body);

            $asset = Asset::create([
                'file_name' => $fileName,
                'file_type' => $this->fileType($mimeType),
                'file_size' => strlen($body),
                'mime_type' => $mimeType,
                'extension' => $extension,
                'path'      => $relativePath,
            ]);

            $directory->assets()->attach($asset->id);

            ProcessAssetUpload::dispatch($asset->id);

            return $asset->id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function buildFileName(string $url, string $prefix, string $mimeType): string
    {
        $name = explode('?', basename($url))[0];

        if ($name === '' || ! str_contains($name, '.')) {
            $name = ($name !== '' ? $name : 'asset').'.'.$this->extensionForMime($mimeType);
        }

        $prefix = preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?? '';

        return $prefix !== '' ? $prefix.'-'.$name : $name;
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
