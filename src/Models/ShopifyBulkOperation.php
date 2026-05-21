<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shopify\Contracts\ShopifyBulkOperation as ShopifyBulkOperationContract;

class ShopifyBulkOperation extends Model implements ShopifyBulkOperationContract
{
    protected $table = 'wk_shopify_bulk_operations';

    protected $fillable = [
        'job_track_id',
        'job_track_batch_id',
        'credential_id',
        'phase',
        'status',
        'shopify_bulk_operation_id',
        'shopify_status',
        'error_code',
        'staged_upload_path',
        'input_file_path',
        'result_file_path',
        'result_url',
        'partial_data_url',
        'object_count',
        'file_size',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
