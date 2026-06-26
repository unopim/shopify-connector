<?php

namespace Webkul\Shopify\Services\Bulk\Media;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves a stored UnoPim asset/media value to a publicly fetchable URL.
 *
 * Single home for the path→URL logic shared by the product media payload
 * builder and the file_reference metafield uploader, so neither duplicates it.
 */
class AssetUrlResolver
{
    /**
     * Normalize a stored media path and resolve it to a public URL.
     *
     * @return array{path: string, url: string}|null
     */
    public function resolveMedia(mixed $path): ?array
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        if ($this->isAbsoluteUrl($path)) {
            return ['path' => $path, 'url' => str_replace(' ', '%20', $path)];
        }

        $normalizedPath = ltrim($path, '/');
        $fullUrl = Storage::url(str_replace(' ', '%20', $normalizedPath));

        if (empty($fullUrl)) {
            return null;
        }

        return ['path' => $normalizedPath, 'url' => $fullUrl];
    }

    /**
     * Build a publicly fetchable URL for a DAM asset path.
     */
    public function resolveAssetUrl(string $path): string
    {
        if ($this->isAbsoluteUrl($path)) {
            return str_replace(' ', '%20', $path);
        }

        if (! Route::has('admin.dam.file.fetch')) {
            return '';
        }

        return route('admin.dam.file.fetch', ['path' => $path]);
    }

    /**
     * Whether a stored media value is already an absolute http(s) URL.
     */
    public function isAbsoluteUrl(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', $value);
    }
}
