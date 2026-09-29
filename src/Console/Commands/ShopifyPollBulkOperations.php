<?php

namespace Webkul\Shopify\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Webkul\Shopify\Jobs\PollBulkShopifyOperation;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;

#[Description('Poll Shopify bulk operations and finalize completed core product syncs.')]
#[Signature('shopify:bulk-operations:poll {operationId?}')]
class ShopifyPollBulkOperations extends Command
{
    public function __construct(protected ShopifyBulkOperationRepository $bulkOperationRepository)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $operationId = $this->argument('operationId');

        $operations = $operationId
            ? collect([$this->bulkOperationRepository->find((int) $operationId)])->filter()
            : $this->bulkOperationRepository->whereIn('status', ['created', 'running'])->get();

        if ($operations->isEmpty()) {
            $this->info('No Shopify bulk operations are waiting to be polled.');

            return self::SUCCESS;
        }

        foreach ($operations as $operation) {
            dispatch_sync(new PollBulkShopifyOperation($operation->id));
        }

        $this->info('Shopify bulk operation polling completed.');

        return self::SUCCESS;
    }
}
