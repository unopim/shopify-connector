<?php

namespace Webkul\Shopify\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\Shopify\Contracts\ShopifyMetaobjectAttribute;

class ShopifyMetaobjectAttributeRepository extends Repository
{
    public function model(): string
    {
        return ShopifyMetaobjectAttribute::class;
    }

    public function bindingFor(int $attributeId): ?object
    {
        return $this->findOneWhere(['attribute_id' => $attributeId]);
    }

    public function saveBinding(int $attributeId, int $definitionId): void
    {
        $existing = $this->findOneWhere(['attribute_id' => $attributeId]);

        if ($existing) {
            $this->update(['definition_id' => $definitionId], $existing->id);

            return;
        }

        $this->create(['attribute_id' => $attributeId, 'definition_id' => $definitionId]);
    }

    public function deleteBinding(int $attributeId): void
    {
        $existing = $this->findOneWhere(['attribute_id' => $attributeId]);

        if ($existing) {
            $this->delete($existing->id);
        }
    }

    public function deleteForDefinition(int $definitionId): void
    {
        $this->deleteWhere(['definition_id' => $definitionId]);
    }
}
