<?php

namespace Webkul\Shopify\Http\Client;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webkul\Shopify\Contracts\ShopifyClient;

/**
 * Client for the published Shopify SaaS proxy app.
 *
 * Calls to Shopify for SaaS-installed credentials go through this proxy
 * (which holds the real shpca_/shpat_ token on its side and forwards to
 * Shopify on the merchant's behalf). The proxy authenticates UnoPim using
 * the JWT it issued at install time.
 *
 * As the SaaS implementation of the ShopifyClient contract, request() routes
 * export operations and returns the Shopify GraphQL-shaped envelope. The
 * remaining methods serve the credential page (shop locales, publications,
 * locations, revoke, sync) and return data shaped for the views.
 */
class SaasProxyClient implements ShopifyClient
{
    /**
     * Map of internal GraphQL endpoint name => proxy route definition.
     *
     * The proxy is a per-operation REST API (one endpoint per Shopify
     * mutation/query), not a GraphQL passthrough. request() uses this map to
     * route export AND import calls and reshape the response into the Shopify
     * GraphQL envelope so exporters/importers consuming requestGraphQlApiAction()
     * stay unchanged.
     *
     * - path:       proxy path appended to the base URL
     * - method:     HTTP method
     * - field:      GraphQL field the proxy nests a single (non-list) result under
     * - connection: GraphQL data key for a paginated list result; when set the
     *               proxy response is normalised into a {edges,pageInfo} envelope
     * - altKeys:    extra top-level keys the proxy may nest the list under
     * - rename:     [graphqlVariableKey => proxyKey] adjustments (body or query)
     * - defaults:   query/body values applied when the caller did not supply them
     * - respKey:    read the result from a different proxy key than `field`
     * - wrap:       nest all variables under one key (e.g. {input:{...}})
     *
     * Scope: Category and Metafield export endpoints, the product bulk-operation
     * flow, and the Category / Attribute / Family / Metafield / Product import
     * read endpoints.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $proxyEndpoints = [
        'createCollection' => [
            'path' => '/graphql/api/collectionCreate.json',
            'method' => 'POST',
            'field' => 'collectionCreate',
        ],
        'updateCollection' => [
            'path' => '/graphql/api/collectionUpdate.json',
            'method' => 'PUT',
            'field' => 'collectionUpdate',
        ],
        'publishablePublish' => [
            'path' => '/graphql/api/publishablePublish.json',
            'method' => 'POST',
            'field' => 'publishablePublish',
            'rename' => ['collectionId' => 'id'],
        ],
        'unpublishableUnpublish' => [
            'path' => '/graphql/api/publishableUnpublish.json',
            'method' => 'POST',
            'field' => 'publishableUnpublish',
            'rename' => ['collectionId' => 'id'],
        ],
        'metafieldDefinitionCreate' => [
            'path' => '/graphql/api/metafieldDefinitionCreate.json',
            'method' => 'POST',
            'field' => 'metafieldDefinitionCreate',
            'rename' => ['input' => 'definition'],
        ],
        'metafieldDefinitionUpdate' => [
            'path' => '/graphql/api/metafieldDefinitionUpdate.json',
            'method' => 'PUT',
            'field' => 'metafieldDefinitionUpdate',
            'rename' => ['input' => 'definition'],
        ],

        // --- Product (bulk) export -----------------------------------------
        'stagedUploadsCreate' => [
            'path' => '/graphql/api/stagedUploadsCreate.json',
            'method' => 'POST',
            'field' => 'stagedUploadsCreate',
        ],
        'bulkOperationRunMutation' => [
            'path' => '/graphql/api/bulkOperationRunMutation.json',
            'method' => 'POST',
            'field' => 'bulkOperationRunMutation',
            'wrap' => 'input',
        ],
        'bulkOperationRunQuery' => [
            'path' => '/graphql/api/bulkOperationRunQuery.json',
            'method' => 'POST',
            'field' => 'bulkOperationRunQuery',
            'wrap' => 'input',
        ],
        'bulkOperationStatus' => [
            'path' => '/graphql/api/bulkOperationStatus.json',
            'method' => 'GET',
            'field' => 'bulkOperation',
            'respKey' => 'node',
        ],
        'bulkOperationCancel' => [
            'path' => '/graphql/api/bulkOperationCancel.json',
            'method' => 'POST',
            'field' => 'bulkOperationCancel',
        ],
        'productSet' => [
            'path' => '/graphql/api/productSet.json',
            'method' => 'POST',
            'field' => 'productSet',
            'rename' => ['input' => 'productSet'],
        ],

        // --- Translations --------------------------------------------------
        'createTranslation' => [
            'path' => '/graphql/api/translationsRegister.json',
            'method' => 'POST',
            'field' => 'translationsRegister',
            'targetFromId' => true,
        ],

        // --- Category import ----------------------------------------------
        // CategoryIterator / Category importer paginate collections; both the
        // first-page and the cursor operation hit the same proxy list endpoint.
        'manualCollectionGetting' => [
            'path' => '/graphql/api/collections.json',
            'method' => 'GET',
            'connection' => 'collections',
            'defaults' => ['first' => 10],
        ],
        'GetCollectionsByCursor' => [
            'path' => '/graphql/api/collections.json',
            'method' => 'GET',
            'connection' => 'collections',
            'rename' => ['afterCursor' => 'after'],
        ],

        // --- Attribute / Family import ------------------------------------
        // AttributeIterator and the Family importer read product options from
        // the product list (only node.options is consumed downstream).
        //
        // NOTE: `reverse` is intentionally NOT sent — the proxy forwards the
        // query param verbatim into Shopify's GraphQL `products(reverse:)`
        // argument, which is a Boolean; the string "true"/"false" makes Shopify
        // reject the whole query ("invalid value ... Expected type 'Boolean'")
        // and the proxy returns no `products`. Ordering is irrelevant here.
        'productGettingOptions' => [
            'path' => '/graphql/api/products.json',
            'method' => 'GET',
            'connection' => 'products',
            'altKeys' => ['product'],
            'defaults' => ['first' => 50],
        ],
        'productOptionByCursor' => [
            'path' => '/graphql/api/products.json',
            'method' => 'GET',
            'connection' => 'products',
            'altKeys' => ['product'],
            'rename' => ['afterCursor' => 'after'],
        ],

        // --- Product import (non-bulk fallback) ---------------------------
        // The default product import path is the bulk-operation flow above;
        // these back the ProductIterator fallback when import_use_bulk_operation
        // is disabled. The proxy product list returns shallower nesting than the
        // connector's GraphQL query, so the bulk path remains preferred.
        'productAllvalueGetting' => [
            'path' => '/graphql/api/products.json',
            'method' => 'GET',
            'connection' => 'products',
            'altKeys' => ['product'],
            'defaults' => ['first' => 20],
        ],
        'productAllvalueGettingByCursor' => [
            'path' => '/graphql/api/products.json',
            'method' => 'GET',
            'connection' => 'products',
            'altKeys' => ['product'],
            'rename' => ['afterCursor' => 'after'],
        ],

        // --- Metafield import ---------------------------------------------
        // ownerType is required by the proxy. The proxy's metafieldDefinitions
        // endpoint exposes no `after` cursor, so it returns a single page; the
        // caller's per-page `first` (20) is overridden to Shopify's maximum so
        // every definition is captured in that one page.
        'metafieldDefinitionsProductType' => [
            'path' => '/graphql/api/metafieldDefinitions.json',
            'method' => 'GET',
            'connection' => 'metafieldDefinitions',
            'override' => ['first' => 250, 'ownerType' => 'PRODUCT'],
        ],
        'metafieldDefinitionsProductVariantType' => [
            'path' => '/graphql/api/metafieldDefinitions.json',
            'method' => 'GET',
            'connection' => 'metafieldDefinitions',
            'override' => ['first' => 250, 'ownerType' => 'PRODUCTVARIANT'],
        ],

        // --- Translations (import read) -----------------------------------
        // Per-resource translation lookup used by the Category / Attribute /
        // Product importers. The proxy nests the resource under `node`.
        'getCollectionTranslations' => [
            'path' => '/graphql/api/translatableResource.json',
            'method' => 'GET',
            'field' => 'translatableResource',
            'respKey' => 'node',
        ],
    ];

    public function __construct(
        protected string $baseUrl,
        protected string $jwt,
        protected int $timeout = 30
    ) {}

    /**
     * Quick liveness check — confirms the JWT is accepted by the proxy.
     * Used as the SaaS-equivalent of `getOneProduct` for the manual flow.
     */
    public function ping(): bool
    {
        return ($this->get('/graphql/api/shop.json')['code'] ?? null) === 200;
    }

    /**
     * Fetch published shop locales.
     *
     * Returns array shaped as the trait's `shopLocales` field:
     *     [ ['locale' => 'en', 'name' => 'English', 'primary' => true, 'published' => true], ... ]
     */
    public function shopLocales(): array
    {
        $response = $this->get('/graphql/api/shop/locales.json');

        $body = $response['body'] ?? [];

        $locales = $body['shopLocales'] ?? $body['locales'] ?? $body['shop'] ?? [];

        if (! empty($locales) && $this->isAssoc($locales)) {
            $locales = [$locales];
        }

        $locales = array_values(array_filter($locales, fn ($l) => is_array($l) && ! empty($l['locale'])));

        return array_map(function ($locale) {
            return [
                'locale' => $locale['locale'],
                'name' => $locale['name'] ?? $locale['locale'],
                'primary' => (bool) ($locale['primary'] ?? false),
                'published' => (bool) ($locale['published'] ?? true),
            ];
        }, $locales);
    }

    /**
     * Fetch the publications list shaped as the trait's `publications.edges`:
     *     [ ['node' => ['id' => 'gid://.../Publication/123', 'name' => 'Online Store']], ... ]
     */
    public function publications(): array
    {
        $response = $this->get('/graphql/api/publications.json');

        $body = $response['body'] ?? [];
        $nodes = $body['publications']['nodes']
            ?? $body['publications']['edges']
            ?? $body['publications']
            ?? [];

        return $this->toEdges($nodes);
    }

    /**
     * Fetch the locations list shaped as the trait's `locations.edges`:
     *     [ ['node' => ['id' => 'gid://.../Location/123', 'name' => 'Noida Warehouse']], ... ]
     *
     * The proxy currently exposes only single-location lookup by id and
     * shop/channels (singular). When neither yields a list, returns []; the
     * dropdown will be empty until the proxy adds a list endpoint.
     */
    public function locations(): array
    {
        $response = $this->get('/graphql/api/locations.json?includeLegacy=true');

        $body = $response['body'] ?? [];
        $candidates = $body['locations']['nodes']
            ?? $body['locations']['edges']
            ?? $body['locations']
            ?? [];

        return $this->toEdges(is_array($candidates) ? $candidates : []);
    }

    /**
     * Ask the proxy to remove this merchant's Shopify connection.
     *
     * POSTs `{"domain": "<shop>.myshopify.com"}` to the configured path and
     * treats the response's `status` field as the source of truth (accepts
     * boolean true or the string "true"). 2xx with status=false counts as
     * failure so the caller keeps the local row.
     */
    public function revoke(string $path, string $domain): bool
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->jwt,
            ])
                ->timeout($this->timeout)
                ->post($url, ['domain' => $domain]);

            if ($response->successful()) {

                return true;
            }

            return false;
        } catch (\Throwable $e) {
            Log::warning('Shopify SaaS proxy revoke failed', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Push the regenerated UnoPim secret_key + base_url to the proxy so it
     * can store them against this shop and start calling UnoPim back with
     * the new credentials.
     *
     * @return array{ok: bool, code: int|null, body: array}
     */
    public function syncUnopim(string $path, array $payload): array
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->jwt,
            ])
                ->timeout($this->timeout)
                ->post($url, $payload);

            return [
                'ok' => $response->successful(),
                'code' => $response->status(),
                'body' => $response->json() ?? [],
            ];
        } catch (\Throwable $e) {
            Log::warning('Shopify SaaS proxy sync failed', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return ['ok' => false, 'code' => null, 'body' => []];
        }
    }

    /**
     * {@inheritdoc}
     *
     * Routes a mapped operation to its proxy REST endpoint and reshapes the
     * REST response back into the Shopify GraphQL envelope, so consumers read
     * SaaS and manual responses identically.
     */
    public function request(string $operation, array $variables = []): array
    {
        if (empty($this->jwt)) {
            throw new \InvalidArgumentException(trans('shopify::app.shopify.credential.errors.invalid-credentials-provided'));
        }

        if (! isset($this->proxyEndpoints[$operation])) {
            return $this->proxyErrorResponse(null, "Shopify SaaS proxy does not support the '{$operation}' operation.");
        }

        $definition = $this->proxyEndpoints[$operation];

        $url = rtrim($this->baseUrl, '/').$definition['path'];

        // The data key the consumer reads the result under: a list operation
        // exposes its `connection` key, a single-result operation its `field`.
        $dataKey = $definition['connection'] ?? $definition['field'] ?? $operation;

        try {
            $request = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->jwt,
            ])
                ->timeout($this->timeout)
                ->retry(3, 100);

            if ($definition['method'] === 'GET') {
                $response = $request->get($url, $this->buildProxyQuery($variables, $definition));
            } else {
                $response = $request->send($definition['method'], $url, [
                    'json' => $this->buildProxyRequestBody($variables, $definition),
                ]);
            }

            $json = $response->json() ?? [];

            if (! $response->successful()) {
                return $this->proxyErrorResponse(
                    $response->status(),
                    $json['message'] ?? "Shopify SaaS proxy returned HTTP {$response->status()}.",
                    $dataKey
                );
            }

            // Paginated list reads (import iterators) are reshaped into the
            // Shopify GraphQL connection envelope; single results pass through.
            if (isset($definition['connection'])) {
                return [
                    'code' => $response->status(),
                    'body' => [
                        'data' => [
                            $definition['connection'] => $this->normalizeConnection($json, $definition),
                        ],
                    ],
                ];
            }

            $respKey = $definition['respKey'] ?? $definition['field'];

            return [
                'code' => $response->status(),
                'body' => [
                    'data' => [
                        $definition['field'] => $json[$respKey] ?? $json[$definition['field']] ?? $json,
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Shopify SaaS proxy GraphQL call failed', [
                'endpoint' => $operation,
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return $this->proxyErrorResponse(null, $e->getMessage(), $dataKey);
        }
    }

    /**
     * Translate the connector's GraphQL variables into proxy GET query params.
     *
     * Applies `rename` (e.g. afterCursor => after), drops null/empty values so
     * optional cursor args are simply omitted, fills endpoint `defaults`
     * (page size, ownerType, reverse) the caller did not supply, and finally
     * applies `override` values that always replace the caller's.
     */
    protected function buildProxyQuery(array $variables, array $definition): array
    {
        $query = $variables;

        foreach ($definition['rename'] ?? [] as $from => $to) {
            if (array_key_exists($from, $query)) {
                $query[$to] = $query[$from];
                unset($query[$from]);
            }
        }

        $query = array_filter($query, fn ($value) => $value !== null && $value !== '');

        foreach ($definition['defaults'] ?? [] as $key => $value) {
            if (! array_key_exists($key, $query)) {
                $query[$key] = $value;
            }
        }

        foreach ($definition['override'] ?? [] as $key => $value) {
            $query[$key] = $value;
        }

        return $query;
    }

    /**
     * Reshape a proxy list response into the Shopify GraphQL connection envelope
     * ({edges:[{cursor,node}], pageInfo:{...}}) the connector's import iterators
     * consume.
     *
     * The proxy's list endpoints are documented loosely, so every plausible
     * shape is accepted: a GraphQL-style {edges:[...]}, an {nodes:[...]} list,
     * a bare array, or a single object.
     *
     * @return array{edges: array<int, array{cursor: ?string, node: mixed}>, pageInfo: array}
     */
    protected function normalizeConnection(array $json, array $definition): array
    {
        $container = $this->locateConnectionContainer($json, $definition);

        $pageInfo = isset($container['pageInfo']) && is_array($container['pageInfo'])
            ? $container['pageInfo']
            : [];

        if (isset($container['edges']) && is_array($container['edges'])) {
            // Already edge-shaped — keep any proxy-supplied per-edge cursor.
            $edges = array_values(array_map(function ($edge) {
                if (is_array($edge) && array_key_exists('node', $edge)) {
                    return ['cursor' => $edge['cursor'] ?? null, 'node' => $edge['node']];
                }

                return ['cursor' => null, 'node' => $edge];
            }, $container['edges']));
        } else {
            // {nodes:[...]}, a bare list, or a single object.
            $nodes = $container['nodes'] ?? $container;

            if ($this->isAssoc($nodes)) {
                $nodes = [$nodes];
            }

            $edges = array_values(array_map(
                fn ($node) => ['cursor' => null, 'node' => $node],
                is_array($nodes) ? $nodes : []
            ));
        }

        // The import iterators advance pagination from the LAST edge's cursor.
        // When the proxy returns nodes without per-edge cursors, fall back to
        // the connection's endCursor so the next page can still be requested.
        if (! empty($edges)) {
            $lastIndex = count($edges) - 1;

            if (empty($edges[$lastIndex]['cursor']) && ! empty($pageInfo['endCursor'])) {
                $edges[$lastIndex]['cursor'] = $pageInfo['endCursor'];
            }
        }

        return ['edges' => $edges, 'pageInfo' => $pageInfo];
    }

    /**
     * Locate the list container inside a proxy response, trying the connection
     * key and any declared alternates before falling back to the whole body.
     *
     * @return array<string, mixed>
     */
    protected function locateConnectionContainer(array $json, array $definition): array
    {
        $keys = array_merge([$definition['connection']], $definition['altKeys'] ?? []);

        foreach ($keys as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                return $json[$key];
            }
        }

        return $json;
    }

    /**
     * Translate the connector's GraphQL variables into the proxy request body.
     */
    protected function buildProxyRequestBody(array $variables, array $definition): array
    {
        $body = $variables;

        foreach ($definition['rename'] ?? [] as $from => $to) {
            if (array_key_exists($from, $body)) {
                $body[$to] = $body[$from];
                unset($body[$from]);
            }
        }

        // The proxy's translationsRegister endpoint requires the resource id as
        // a top-level `resourceId` (Shopify's translationsRegister(resourceId:)
        // argument); its doc also shows a per-item `target`. Send both so the
        // proxy resolves the resource id whichever field it reads.
        if (! empty($definition['targetFromId'])) {
            $resourceId = $body['id'] ?? null;
            unset($body['id']);
            $body['resourceId'] = $resourceId;
            $body['translations'] = array_map(
                fn ($translation) => $translation + ['target' => $resourceId],
                $body['translations'] ?? []
            );
        }

        // Some proxy endpoints expect every variable nested under one key
        // (e.g. bulkOperationRunMutation wants {input: {mutation, ...}}).
        if (! empty($definition['wrap'])) {
            $body = [$definition['wrap'] => $body];
        }

        return $body;
    }

    /**
     * Build a response shaped like the Shopify GraphQL error envelope so the
     * trait's failure handling and the exporters' error checks keep working.
     *
     * @return array{code: int|null, body: array}
     */
    protected function proxyErrorResponse(?int $code, string $message, ?string $field = null): array
    {
        $body = ['errors' => [['message' => $message]]];

        if ($field) {
            $body['data'] = [$field => []];
        }

        return ['code' => $code, 'body' => $body];
    }

    /**
     * Normalise a list to the `edges` shape expected by the view.
     */
    protected function toEdges(array $list): array
    {
        if (empty($list)) {
            return [];
        }

        return array_map(function ($item) {
            if (isset($item['node'])) {
                return $item;
            }

            return ['node' => $item];
        }, array_values($list));
    }

    /**
     * Run a JSON GET against the proxy with Bearer auth.
     */
    protected function get(string $path): array
    {
        $url = rtrim($this->baseUrl, '/').$path;

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$this->jwt,
            ])
                ->timeout($this->timeout)
                ->get($url);

            return [
                'code' => $response->status(),
                'body' => $response->json() ?? [],
            ];
        } catch (\Throwable $e) {
            Log::warning('Shopify SaaS proxy call failed', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return [
                'code' => null,
                'body' => [],
            ];
        }
    }

    protected function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
