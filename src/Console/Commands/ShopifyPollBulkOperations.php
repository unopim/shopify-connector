<?php

namespace Webkul\Shopify\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shopify\Jobs\PollBulkShopifyOperation;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;

class ShopifyPollBulkOperations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:bulk-operations:poll {operationId?}';

    protected $description = 'Poll Shopify bulk operations and finalize completed core product syncs.';

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
            PollBulkShopifyOperation::dispatchSync($operation->id);
        }

        $this->info('Shopify bulk operation polling completed.');

        return self::SUCCESS;
    }
}
