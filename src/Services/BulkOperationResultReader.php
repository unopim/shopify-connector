<?php

namespace Webkul\Shopify\Services;

use Illuminate\Support\Facades\Storage;
use Webkul\Shopify\Models\ShopifyBulkOperation;

class BulkOperationResultReader
{
    /**
     * Return normalized result entries for a completed bulk operation.
     */
    public function read(ShopifyBulkOperation $bulkOperation): array
    {
        $manifest = json_decode(Storage::disk('local')->get($bulkOperation->input_file_path), true) ?: [];
        $raw = trim(Storage::disk('local')->get($bulkOperation->result_file_path));
        $resultLines = $raw === '' ? [] : preg_split("/\r\n|\n|\r/", $raw);
        $entries = [];

        foreach ($resultLines as $index => $line) {
            $decoded = json_decode($line, true) ?: [];
            $payload = $decoded['data']['productSet'] ?? [];
            $manifestLine = $manifest['lines'][$index] ?? [];
            $product = $payload['product'] ?? [];

            $entries[] = [
                'line' => $index,
                'manifest' => $manifestLine,
                'product' => $product,
                'user_errors' => $payload['userErrors'] ?? [],
            ];
        }

        return [
            'manifest' => $manifest,
            'entries' => $entries,
        ];
    }
}
