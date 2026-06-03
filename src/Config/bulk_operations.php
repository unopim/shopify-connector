<?php

return [
    'poll_delay_seconds' => 5,
    'dispatch_followup_phases' => true,

    /*
    | Import-side bulk-operation tuning. The product importer can fetch the
    | catalog via Shopify's bulkOperationRunQuery (one round trip) instead of
    | thousands of paginated GraphQL calls.
    */
    'import_use_bulk_operation' => env('SHOPIFY_IMPORT_USE_BULK', true),
    'import_poll_delay_seconds' => 5,
    'import_max_wait_seconds' => 1800,   // 30 min hard cap on a single bulk-op fetch

    /*
    | Import-side performance tuning. Each flag is independently reversible
    | so a production issue with one can be opted out without losing the rest.
    */
    'import_use_lookup_cache' => env('SHOPIFY_IMPORT_USE_LOOKUP_CACHE', true),
    'import_async_image_download' => env('SHOPIFY_IMPORT_ASYNC_IMAGES', true),
    'import_suppress_observers' => env('SHOPIFY_IMPORT_SUPPRESS_OBSERVERS', true),
    'import_post_batch_completeness' => env('SHOPIFY_IMPORT_POST_BATCH_COMPLETENESS', true),
    'import_post_batch_index' => env('SHOPIFY_IMPORT_POST_BATCH_INDEX', true),
    'import_bulk_translations' => env('SHOPIFY_IMPORT_BULK_TRANSLATIONS', true),
    'import_release_jsonl_memory' => env('SHOPIFY_IMPORT_RELEASE_JSONL_MEM', true),
    'import_image_queue' => env('SHOPIFY_IMPORT_IMAGE_QUEUE', 'default'),
    'import_mapping_chunk_size' => env('SHOPIFY_IMPORT_MAPPING_CHUNK', 200),
];
