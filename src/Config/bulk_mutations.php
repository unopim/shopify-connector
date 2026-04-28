<?php

return [
    'productSetBulk' => <<<'GRAPHQL'
mutation productSetBulk($identifier: ProductSetIdentifiers, $input: ProductSetInput!) {
  productSet(identifier: $identifier, input: $input) {
    product {
      id
      handle
      variants(first: 250) {
        nodes {
          id
          sku
          inventoryItem {
            id
          }
        }
      }
    }
    userErrors {
      code
      field
      message
    }
  }
}
GRAPHQL,

    'publishablePublishBulk' => <<<'GRAPHQL'
mutation publishablePublishBulk($id: ID!, $input: [PublicationInput!]!) {
  publishablePublish(id: $id, input: $input) {
    userErrors { field message }
  }
}
GRAPHQL,

    'collectionAddProductsBulk' => <<<'GRAPHQL'
mutation collectionAddProductsBulk($id: ID!, $productIds: [ID!]!) {
  collectionAddProducts(id: $id, productIds: $productIds) {
    userErrors { field message }
  }
}
GRAPHQL,

    'translationsRegisterBulk' => <<<'GRAPHQL'
mutation translationsRegisterBulk($resourceId: ID!, $translations: [TranslationInput!]!) {
  translationsRegister(resourceId: $resourceId, translations: $translations) {
    userErrors { field message }
    translations { locale key value }
  }
}
GRAPHQL,

    'inventorySetOnHandQuantitiesBulk' => <<<'GRAPHQL'
mutation inventorySetOnHandQuantitiesBulk($input: InventorySetOnHandQuantitiesInput!) {
  inventorySetOnHandQuantities(input: $input) {
    userErrors { field message }
    inventoryAdjustmentGroup { reason referenceDocumentUri }
  }
}
GRAPHQL,
];
