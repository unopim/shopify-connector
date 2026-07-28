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

    public function saveBinding(int $attributeId, int $definitionId, bool $isList = false): void
    {
        $existing = $this->findOneWhere(['attribute_id' => $attributeId]);

        if ($existing) {
            $this->update(['definition_id' => $definitionId, 'is_list' => $isList], $existing->id);

            return;
        }

        $this->create(['attribute_id' => $attributeId, 'definition_id' => $definitionId, 'is_list' => $isList]);
    }

    public function bindingsForDefinition(int $definitionId)
    {
        return $this->findWhere(['definition_id' => $definitionId]);
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
