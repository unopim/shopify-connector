<?php

namespace Webkul\Shopify\Traits;

use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Models\JobTrack;
use Webkul\Shopify\Exceptions\InvalidCredential;
use Webkul\Shopify\Http\Client\GraphQLApiClient;

/**
 * Trait for making GraphQL API requests to Shopify.
 */
trait ShopifyGraphqlRequest
{
    /**
     * Sends a GraphQL API request to Shopify based on the provided mutation type and credentials.
     *
     * @param  string  $mutationType  The GraphQL mutation type or query to execute.
     * @param  array|null  $credential  Optional. Shopify credentials including 'shopUrl', 'accessToken', and 'apiVersion'.
     * @param  array|null  $formatedVariable  Optional. Variables to be sent with the GraphQL query or mutation.
     * @return array The response from Shopify's GraphQL API.
     */
    protected function requestGraphQlApiAction(string $mutationType, ?array $credential = [], ?array $formatedVariable = []): array
    {
        $credential = new GraphQLApiClient($credential['shopUrl'], $credential['accessToken'], $credential['apiVersion']);

        $response = $credential->request($mutationType, $formatedVariable);

        if (
            (! $response['code'] || in_array($response['code'], [401, 404]))
            && property_exists($this, 'export')
            && $this->export instanceof JobTrack
        ) {
            $this->export->state = ExportHelper::STATE_FAILED;
            $this->export->errors = [trans('shopify::app.shopify.export.errors.invalid-credential')];
            $this->export->save();

            throw new InvalidCredential;
        }

        return $response;
    }
}
