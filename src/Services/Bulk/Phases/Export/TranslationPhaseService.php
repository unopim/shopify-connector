<?php

namespace Webkul\Shopify\Services\Bulk\Phases\Export;

use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Services\Bulk\Phases\BasePhaseService;
use Webkul\Shopify\Services\BulkOperationService;
use Webkul\Shopify\Services\Bulk\PayloadBuilders\TranslationsBulkPayloadBuilder;

/**
 * Translation phase service — creates product translations using bulk translationsRegister.
 */
class TranslationPhaseService extends BasePhaseService
{
    public function __construct(
        TranslationsBulkPayloadBuilder $payloadBuilder,
        BulkOperationService $bulkOperationService,
        ShopifyBulkOperationRepository $bulkOperationRepository,
        ShopifyCredentialRepository $credentialRepository
    ) {
        parent::__construct($bulkOperationService, $bulkOperationRepository, $credentialRepository);
        $this->payloadBuilder = $payloadBuilder;
    }

    protected function buildPayloadLines(array $operationData): array
    {
        $storeLocaleMapping = $this->credential->storelocaleMapping ?? [];
        $storeLocales = $this->credential->storeLocales ?? [];

        // Only proceed if multiple locales are configured (i.e., translations are needed)
        if (count($storeLocaleMapping) < 2) {
            return [];
        }

        return $this->payloadBuilder->build(
            $operationData['entries'],
            $this->credential->id,
            $this->manifest['channel'] ?? 'default',
            $this->manifest['currency'] ?? 'USD',
            $storeLocaleMapping,
            $storeLocales
        );
    }

    protected function getPhaseName(): string
    {
        return 'translations';
    }

    protected function getMutationKey(): string
    {
        return 'translationsRegisterBulk';
    }

    protected function getManifestMutationName(): string
    {
        return 'translationsRegister';
    }
}
