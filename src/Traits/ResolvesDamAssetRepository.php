<?php

namespace Webkul\Shopify\Traits;

use Webkul\DAM\Repositories\AssetRepository;

trait ResolvesDamAssetRepository
{
    protected ?AssetRepository $resolvedAssetRepository = null;

    protected bool $assetRepositoryResolved = false;

    /**
     * Resolve the DAM AssetRepository on demand, or null when DAM is absent.
     *
     * Resolved lazily rather than constructor-injected: DAM is an optional
     * module, and a nullable constructor default would make Laravel's container
     * short-circuit the parameter to null anyway — it only auto-builds defaulted
     * params for *bound* classes, and the concrete AssetRepository is not bound.
     * A direct container make() builds it correctly.
     */
    protected function assetRepository(): ?AssetRepository
    {
        if (! $this->assetRepositoryResolved) {
            $this->assetRepositoryResolved = true;

            if (class_exists(AssetRepository::class)) {
                try {
                    $this->resolvedAssetRepository = resolve(AssetRepository::class);
                } catch (\Throwable) {
                    $this->resolvedAssetRepository = null;
                }
            }
        }

        return $this->resolvedAssetRepository;
    }
}
