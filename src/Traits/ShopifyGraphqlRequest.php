<?php

namespace Webkul\Shopify\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Models\JobTrack;
use Webkul\Shopify\Exceptions\InvalidCredential;
use Webkul\Shopify\Services\ShopifyClientFactory;

/**
 * Trait for making GraphQL API requests to Shopify.
 */
trait ShopifyGraphqlRequest
{
    /**
     * Sends a Shopify API request for the given operation and credentials.
     *
     * The credential's transport (manual Shopify Admin API vs. SaaS proxy) is
     * resolved by ShopifyClientFactory — this method depends only on the
     * ShopifyClient contract and never on a concrete transport. A failed call
     * (no/401/404 response code) fails the running export job.
     *
     * @param  string  $mutationType  The operation/endpoint name to execute.
     * @param  array|null  $credential  Shopify credentials assembled by the caller.
     * @param  array|null  $formatedVariable  Variables to send with the operation.
     * @return array The response in the Shopify GraphQL envelope shape.
     */
    protected function requestGraphQlApiAction(string $mutationType, ?array $credential = [], ?array $formatedVariable = []): array
    {
        $client = app(ShopifyClientFactory::class)->make($credential ?? []);

        $response = $client->request($mutationType, $formatedVariable ?? []);

        if (
            (empty($response['code']) || in_array($response['code'], [401, 404], true))
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

    /**
     * Attempts to download the image from the provided URL.
     */
    public function handleUrlField(mixed $imageUrl, string $imagePath): string|bool
    {

        try {
            $response = Http::get($imageUrl);

            if ($response->failed()) {
                return false;
            }

            $imageContents = $response->body();

            $path = parse_url($imageUrl, PHP_URL_PATH);

            $fileName = basename($path);

            if (! preg_match('/\.[a-zA-Z0-9]+$/', $fileName)) {
                $fileName .= '.png';
            }

            $path = $imagePath.$fileName;

            StorageFacade::disk('public')->put($path, $imageContents);

            return $path;
        } catch (\Exception $e) {
            return false;
        }
    }
}
