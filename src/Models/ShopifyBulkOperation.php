<?php

namespace Webkul\Shopify\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Webkul\Shopify\Contracts\ShopifyBulkOperation as ShopifyBulkOperationContract;

#[Fillable([
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
])]
#[Table(name: 'wk_shopify_bulk_operations')]
class ShopifyBulkOperation extends Model implements ShopifyBulkOperationContract
{
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }
}
