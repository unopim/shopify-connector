<?php

namespace Webkul\Shopify\Services;

use Webkul\Shopify\Jobs\RunCollectionAssignmentPhase;
use Webkul\Shopify\Jobs\RunInventoryPhase;
use Webkul\Shopify\Jobs\RunPublishingPhase;
use Webkul\Shopify\Jobs\RunTranslationPhase;
use Webkul\Shopify\Models\ShopifyBulkOperation;

class PhaseOrchestrator
{
    /**
     * Register follow-up phase metadata after a core sync completes.
     */
    public function registerPendingPhases(ShopifyBulkOperation $bulkOperation, array $phaseContext): void
    {
        $meta = $bulkOperation->meta ?? [];
        $meta['follow_up_phases'] = $phaseContext;

        $bulkOperation->meta = $meta;
        $bulkOperation->save();
    }

    /**
     * Dispatch follow-up phases when explicitly enabled.
     */
    public function dispatchPendingPhases(ShopifyBulkOperation $bulkOperation): void
    {
        if (! config('shopify-bulk-operations.dispatch_followup_phases', false)) {
            return;
        }

        $meta = $bulkOperation->meta ?? [];
        $meta['follow_up_phases_enabled'] = true;

        $bulkOperation->meta = $meta;
        $bulkOperation->save();

        RunPublishingPhase::dispatch($bulkOperation->id);
        RunCollectionAssignmentPhase::dispatch($bulkOperation->id);
        RunTranslationPhase::dispatch($bulkOperation->id);
        RunInventoryPhase::dispatch($bulkOperation->id);
    }
}
