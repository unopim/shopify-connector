<?php

namespace Webkul\Shopify\Http\Client;

use Illuminate\Support\Facades\Http;

class GraphQLApiClient
{
    protected $url;

    protected $accessToken;

    protected $apiVersion;

    protected $options;

    /**
     * Create object of this class
     */
    public function __construct(string $url, string $accessToken, string $apiVersion, array $options = [])
    {
        $this->apiVersion = $apiVersion;
        $this->accessToken = $accessToken;
        $this->options = $options;
        $this->url = $this->buildApiUrl($url);

    }

    /**
     * Build the API URL for making requests to the GraphQL endpoint.
     */
    protected function buildApiUrl(string $url): string
    {
        $url = str_replace(['http://'], ['https://'], $url);

        return rtrim($url, '/').'/admin/api/'.$this->apiVersion.'/graphql.json';
    }

    /**
     * Retrieve the headers for making API requests to Shopify.
     */
    protected function getRequestHeaders(): array
    {
        return [
            'Accept'                 => 'application/json',
            'Content-type'           => 'application/json',
            'X-Shopify-Access-Token' => $this->accessToken,
        ];
    }

    /**
     * Create a request array for a specific API endpoint.
     */
    protected function createRequest(string $endpoint, array $parameters = [], array $data = [], $logger = null)
    {
        if (! array_key_exists($endpoint, $this->endpoints)) {
            return null;
        }

        $method = $this->endpoints[$endpoint]['method'];
        $query = $this->endpoints[$endpoint]['query'];
        $variables = $parameters;

        $body = ['query' => $query];

        if (! empty($variables)) {
            $body['variables'] = $variables;
        }

        return [
            'url'    => $this->url,
            'method' => $method,
            'body'   => json_encode($body, true),
        ];
    }

    /**
     * Send the HTTP request and create a response array.
     */
    protected function createResponse(array $request): array
    {
        try {
            $response = Http::withHeaders($this->getRequestHeaders())
                ->timeout($this->options['timeout'] ?? 120)
                ->retry(3, 100)
                ->send($request['method'], $request['url'], [
                    'body' => $request['body'],
                ]);

            return [
                'code' => $response->status(),
                'body' => $response->json(),
            ];
        } catch (\Exception $e) {
            return [
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
            ];
        }
    }

    /**
     * Make an API request to a specific endpoint with given parameters and payload.
     */
    public function request(string $endpoint, array $parameters = [], array $payload = [], $logger = null): array
    {
        $request = $this->createRequest($endpoint, $parameters, $payload, $logger);

        if (! $request) {
            return ['error' => 'Invalid endpoint'];
        }

        $response = $this->createResponse($request);

        // Rate Limit Handling
        if ($response['code'] == 429) {
            sleep(4);

            return $this->request($endpoint, $parameters, $payload, $logger);
        }

        return $response;
    }

    /**
     * Stores Grapql mutations
     */
    protected $endpoints = [
        'getShopPublishedLocales' => [
            'query'  => '{shopLocales (published: true) {locale name primary published } }',
            'method' => 'POST',
        ],
        'createTranslation' => [
            'query'  => 'mutation CreateTranslation($id: ID!, $translations: [TranslationInput!]!) { translationsRegister(resourceId: $id, translations: $translations) {  userErrors { message field }  translations {  locale key value }, }}',
            'method' => 'POST',
        ],
        'createCollection' => [
            'query'  => 'mutation CollectionCreate($input: CollectionInput!) { collectionCreate(input: $input) { userErrors { field message } collection { id title descriptionHtml handle} } }',
            'method' => 'POST',
        ],

        'manualCollectionGetting' => [
            'query'  => 'query MyCollections($first: Int!, $collectionType: String!) { collections(first: $first, query: $collectionType) { pageInfo { hasNextPage hasPreviousPage } edges { cursor node { id title handle} } } }',
            'method' => 'POST',
        ],

        'GetCollectionsByCursor' => [
            'query'  => 'query GetCollections($first: Int!, $collectionType: String!, $afterCursor: String!) { collections(first: $first, query: $collectionType, after: $afterCursor) { pageInfo { hasNextPage hasPreviousPage } edges { cursor node { id title handle} } } }',
            'method' => 'POST',
        ],

        'updateCollection' => [
            'query'  => 'mutation updateCollectionTitle($input: CollectionInput!) { collectionUpdate(input: $input) { userErrors { field message } collection { id title descriptionHtml} } }',
            'method' => 'POST',
        ],

        'getOneProduct' => [
            'query'  => '{ products(first: 1) { edges { node { id title descriptionHtml createdAt updatedAt } } } }',
            'method' => 'POST',
        ],

        'createProduct' => [
            'query'  => 'mutation ProductCreate($input: ProductInput!, $media: [CreateMediaInput!]) { productCreate(input: $input, media: $media) { product { id title handle productType vendor tags handle media(first: 10) { nodes { id } } options { id name values optionValues { id name } } variants(first: 10) { edges { node { id }  } } } userErrors { field message } } }',
            'method' => 'POST',
        ],

        'ProductVariantUpdate' => [
            'query'  => 'mutation ProductVariantUpdate($input: ProductVariantInput!) { productVariantUpdate(input: $input) { productVariant { id price inventoryItem { id inventoryLevels(first: 10) { edges { node { id location { id name address { address1 city province country zip } } } } } } } userErrors { field message } } }',
            'method' => 'POST',
        ],

        'productVariantCreate' => [
            'query'  => 'mutation ProductVariantCreate($input: ProductVariantInput!) { productVariantCreate(input: $input) { productVariant { id price } userErrors { field message } }  }',
            'method' => 'POST',
        ],

        'productVariantDelete' => [
            'query'  => 'mutation ProductVariantDelete($id: ID!) { productVariantDelete(id: $id) { product { id } } }',
            'method' => 'POST',
        ],

        'UpdateCostPerItem' => [
            'query'  => 'mutation inventoryItemUpdate($id: ID!, $input: InventoryItemUpdateInput!) { inventoryItemUpdate(id: $id, input: $input) { inventoryItem { id inventoryLevels(first: 10) { edges { node { id location { id name address { address1 city province country zip } } } } } unitCost { amount } tracked countryCodeOfOrigin provinceCodeOfOrigin harmonizedSystemCode countryHarmonizedSystemCodes(first: 1) { edges { node { harmonizedSystemCode countryCode } } } } userErrors { message } } }',
            'method' => 'POST',
        ],

        'inventoryAdjustQuantities' => [
            'query'  => 'mutation inventoryAdjustQuantities($input: InventoryAdjustQuantitiesInput!) { inventoryAdjustQuantities(input: $input) { userErrors { field message } inventoryAdjustmentGroup { createdAt reason referenceDocumentUri changes { name delta } } } }',
            'method' => 'POST',
        ],

        'updateImageToProduct' => [
            'query'  => 'mutation productAppendImages($inputImg: ProductAppendImagesInput! ) { productAppendImages(input: $inputImg) { newImages { id altText } userErrors { field message } }  }',
            'method' => 'POST',
        ],

        'productUpdate' => [
            'query'  => 'mutation ProductUpdate($input: ProductInput!, $media: [CreateMediaInput!]) { productUpdate(input: $input, media: $media) { product { id title handle productType vendor tags descriptionHtml options { id name values optionValues { id name hasVariants } } media(first: 10) { nodes { id } } collections(first: 10) { edges { node { id handle title } } } variants(first: 10) { edges { node { id }  } } } userErrors { field message } } }',
            'method' => 'POST',
        ],

        'productImageUpdate' => [
            'query'  => 'mutation productImageUpdate($productId: ID!, $image: ImageInput!) { productImageUpdate(productId: $productId, image: $image) { image { id altText src } userErrors { field message } } }',
            'method' => 'POST',
        ],

        'getPublications' => [
            'query'  => 'query publications { publications(first: 250) { pageInfo { hasNextPage hasPreviousPage } edges  { cursor node { id name supportsFuturePublishing app { id title description developerName } } } } }',
            'method' => 'POST',
        ],

        'createOptions' => [
            'query'  => 'mutation createOptions($productId: ID!, $options: [OptionCreateInput!]!) { productOptionsCreate(productId: $productId, options: $options) { userErrors { field message code } product { id variants(first: 5) { nodes { id title selectedOptions { name value } } } options { id name values position optionValues { id name hasVariants } } } } }',
            'method' => 'POST',
        ],

        'CreateProductVariants' => [
            'query'  => 'mutation CreateProductVariants($productId: ID!, $variantsInput: [ProductVariantsBulkInput!]!, $media: [CreateMediaInput!]!) { productVariantsBulkCreate(productId: $productId, variants: $variantsInput, media: $media) { productVariants { id title inventoryItem { id inventoryLevels(first: 10) { edges { node { id location { id name address { address1 city province country zip } } } } } } selectedOptions { name value } } userErrors { field message } product { id media(first: 10) { nodes { id } }  options { id name values optionValues { id name hasVariants } } } } }',
            'method' => 'POST',
        ],

        'getFullfillmentAndLocation' => [
            'query'  => '{ locations(first: 10) { edges { node { id name } } } shop { fulfillmentServices { id serviceName handle inventoryManagement } } }',
            'method' => 'POST',
        ],

        'inventoryBulkToggleActivation' => [
            'query'  => 'mutation InventoryBulkToggleActivation($inventoryItemId: ID!, $inventoryItemUpdates: [InventoryBulkToggleActivationInput!]!) { inventoryBulkToggleActivation(inventoryItemId: $inventoryItemId   inventoryItemUpdates: $inventoryItemUpdates ) {   userErrors {  message     __typename    }   __typename }}',
            'method' => 'POST',
        ],

        'productGettingOptions' => [
            'query'  => 'query { products(first: 10, reverse: true) { edges { cursor node { id productType vendor options { id name position values } variants(first: 10) { edges { node { id title price sku compareAtPrice selectedOptions { name value } } } } } } } }',
            'method' => 'POST',
        ],

        'productOptionByCursor' => [
            'query'  => 'query GetProducts($first: Int!, $afterCursor: String!) { products(first: $first, after: $afterCursor, reverse: true) { edges { cursor node { id productType vendor options { id name position values } variants(first: 10) { edges { node { id title price sku compareAtPrice selectedOptions { name value } } } } } } } }',
            'method' => 'POST',
        ],

        'productAllvalueGetting' => [
            'query'  => 'query { products(first: 10, reverse: true) { edges { cursor node {  id title description resourcePublications(first: 10) { nodes { isPublished publication { name id } } } descriptionHtml productType vendor tags status handle publishedAt createdAt updatedAt  collections(first: 10) { edges { node { id title } } } images(first: 10) { edges { node { id originalSrc altText } } } options { id name values } variants(first: 10) { edges { node { id title price sku compareAtPrice barcode taxable inventoryManagement  inventoryQuantity inventoryPolicy fulfillmentService { id handle serviceName } inventoryItem { id tracked inventoryLevels(first: 10) { edges { node { id location { id name address { address1 city province country zip } } } } } }  weight weightUnit  selectedOptions { name value } image { id originalSrc altText } } } } seo { title description } metafields(first: 10) { edges { node { id namespace key value } } } } } } }',
            'method' => 'POST',
        ],

        'productAllvalueGettingByCursor' => [
            'query'  => 'query GetProducts($first: Int!, $afterCursor: String!) { products(first: $first, after: $afterCursor, reverse: true) { edges { cursor node {  id title description resourcePublications(first: 10) { nodes { isPublished publication { name id } } } descriptionHtml productType vendor tags status handle publishedAt createdAt updatedAt  collections(first: 10) { edges { node { id title } } } images(first: 10) { edges { node { id originalSrc altText } } } options { id name values } variants(first: 10) { edges { node { id title price sku compareAtPrice barcode taxable inventoryQuantity inventoryManagement inventoryPolicy fulfillmentService { id handle serviceName } inventoryItem { id tracked inventoryLevels(first: 10) { edges { node { id location { id name address { address1 city province country zip } } } } } }  weight weightUnit  selectedOptions { name value } image { id originalSrc altText } } } } seo { title description } metafields(first: 10) { edges { node { id namespace key value } } } } } } }',
            'method' => 'POST',
        ],

        'productMetafields' => [
            'query'  => 'query GetProduct($id: ID!) { product(id: $id) {   metafields(first: 10) { edges { cursor node  {  id namespace key value type } } } } }',
            'method' => 'POST',
        ],

        'productMetafieldsByCursor' => [
            'query'  => 'query GetProduct($id: ID!, $first: Int!, $afterCursor: String!) { product(id: $id) {   metafields(first: $first, after: $afterCursor) { edges { cursor node  {  id namespace key value type } } } } }',
            'method' => 'POST',
        ],

        'deleteMetafield' => [
            'query'  => 'mutation metafieldDelete($input: MetafieldDeleteInput!) { metafieldDelete(input: $input) { deletedId userErrors { field message } } }',
            'method' => 'POST',
        ],

        'productVariantMetafield' => [
            'query'  => 'query productVariant($id: ID!) { productVariant(id: $id) {   metafields(first: 1) { edges { cursor node  {  id namespace key value type } } } } }',
            'method' => 'POST',
        ],

        'productVariantMetafieldByCursor' => [
            'query'  => 'query productVariant($id: ID!, $first: Int!, $afterCursor: String!) { productVariant(id: $id) {   metafields(first: $first, after: $afterCursor) { edges { cursor node  {  id namespace key value type } } } } }',
            'method' => 'POST',
        ],

        'productOptionUpdated' => [
            'query'  => 'mutation UpdateOptionNameAndPosition($productId: ID!, $optionInput: OptionUpdateInput!, $optionValuesToUpdate: [OptionValueUpdateInput!], $optionValuesToDelete: [ID!], $optionValuesToAdd: [OptionValueCreateInput!]) { productOptionUpdate(productId: $productId, option: $optionInput, optionValuesToUpdate: $optionValuesToUpdate, optionValuesToDelete: $optionValuesToDelete, optionValuesToAdd: $optionValuesToAdd) { product { options { id name position optionValues { id name hasVariants } } } userErrors { field message } } }',
            'method' => 'POST',
        ],

        'productUpdateMedia' => [
            'query'  => 'mutation productUpdateMedia($media: [UpdateMediaInput!]!, $productId: ID!) { productUpdateMedia(media: $media, productId: $productId) { media { alt id } } }',
            'method' => 'POST',
        ],

        'productCreateMedia' => [
            'query'  => 'mutation productCreateMedia($media: [CreateMediaInput!]!, $productId: ID!) { productCreateMedia(media: $media, productId: $productId) { media { alt id mediaContentType status } mediaUserErrors { field message } product { id title } } }',
            'method' => 'POST',
        ],

        'getignLocations' => [
            'query'  => '{ locations(first: 80, includeLegacy: true) { edges { node { id name  fulfillmentService { id } } } } }',
            'method' => 'POST',
        ],

        'productDelete' => [
            'query'  => 'mutation productDelete($input: ProductDeleteInput!) { productDelete(input: $input) { deletedProductId userErrors { field message } } }',
            'method' => 'POST',
        ],
    ];
}
