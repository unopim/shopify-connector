<?php

return [
    /*
    | Bulk-operation QUERY used by the product importer. Shopify runs this query
    | server-side and returns a JSONL file. The %LOCALE% placeholder is replaced
    | with the import's resolved Shopify locale (or '__NONE__' to skip translations).
    |
    | Bulk-operation rules respected here:
    | - No `first` / `last` / `after` / `before` arguments anywhere
    | - No fragments, no variables, no aliases
    | - Nested connections use `edges { node { ... } }` and Shopify auto-paginates
    */
    /*
    | Pass 1 — products + variants + nested-under-variant connections.
    | Connections (5 max): products, variants, variant.metafields,
    |                     variant.media, variant.inventoryItem.inventoryLevels.
    | Inlined scalars on the variant: selectedOptions, inventoryItem.{id,tracked,
    | requiresShipping,unitCost,measurement}.
    | Inlined on the product: scalar fields + seo + options + translations.
    */
    'productImportBulkQueryCore' => <<<'GRAPHQL'
{
  products%PRODUCT_FILTER% {
    edges {
      node {
        id
        title
        description
        descriptionHtml
        handle
        status
        productType
        vendor
        tags
        publishedAt
        createdAt
        updatedAt
        seo {
          title
          description
        }
        options {
          id
          name
          position
          values
        }
        translations(locale: "%LOCALE%") {
          key
          value
          locale
        }
        variants {
          edges {
            node {
              id
              title
              sku
              price
              compareAtPrice
              barcode
              taxable
              inventoryQuantity
              inventoryPolicy
              selectedOptions {
                name
                value
              }
              inventoryItem {
                id
                tracked
                requiresShipping
                unitCost {
                  amount
                }
                measurement {
                  weight {
                    value
                    unit
                  }
                }
                inventoryLevels {
                  edges {
                    node {
                      id
                      quantities(names: ["available"]) {
                        name
                        quantity
                      }
                      location {
                        id
                        name
                        address {
                          address1
                          city
                          province
                          country
                          zip
                        }
                      }
                    }
                  }
                }
              }
              metafields {
                edges {
                  node {
                    id
                    namespace
                    key
                    value
                    type
                  }
                }
              }
              media {
                edges {
                  node {
                    id
                    __typename
                    ... on MediaImage {
                      image {
                        url
                        altText
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
  }
}
GRAPHQL,

    /*
    | Pass 2 — product-level relations not fetched in pass 1.
    | Connections (5 max): products, media, metafields, collections,
    |                     resourcePublications.
    */
    'productImportBulkQueryRelations' => <<<'GRAPHQL'
{
  products%PRODUCT_FILTER% {
    edges {
      node {
        id
        media {
          edges {
            node {
              id
              __typename
              ... on MediaImage {
                image {
                  url
                  altText
                }
              }
            }
          }
        }
        metafields {
          edges {
            node {
              id
              namespace
              key
              value
              type
            }
          }
        }
        collections {
          edges {
            node {
              id
              handle
              title
            }
          }
        }
        resourcePublications {
          edges {
            node {
              isPublished
              publication {
                id
                name
              }
            }
          }
        }
      }
    }
  }
}
GRAPHQL,

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

    'translationsRegisterBulk' => <<<'GRAPHQL'
mutation translationsRegisterBulk($resourceId: ID!, $translations: [TranslationInput!]!) {
  translationsRegister(resourceId: $resourceId, translations: $translations) {
    userErrors { field message }
    translations { locale key value }
  }
}
GRAPHQL,

    'productCreateMediaBulk' => <<<'GRAPHQL'
mutation productCreateMediaBulk($productId: ID!, $media: [CreateMediaInput!]!) {
  productCreateMedia(productId: $productId, media: $media) {
    media { id alt mediaContentType status }
    mediaUserErrors { field message }
    product { id }
  }
}
GRAPHQL,
];
