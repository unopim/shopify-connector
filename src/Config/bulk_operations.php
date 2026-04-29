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
    'import_max_wait_seconds'   => 1800,   // 30 min hard cap on a single bulk-op fetch
];
