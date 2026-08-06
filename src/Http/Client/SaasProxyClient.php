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
     * Scope: Category and Metafield export endpoints, the product bulk-operation
     * flow, and the Category / Attribute / Family / Metafield / Product import
     * read endpoints.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $proxyEndpoints = [
        'createCollection' => [
            'path'   => '/graphql/api/collectionCreate.json',
            'method' => 'POST',
            'field'  => 'collectionCreate',
        ],
        'updateCollection' => [
            'path'   => '/graphql/api/collectionUpdate.json',
            'method' => 'PUT',
            'field'  => 'collectionUpdate',
        ],
        'publishablePublish' => [
            'path'   => '/graphql/api/publishablePublish.json',
            'method' => 'POST',
            'field'  => 'publishablePublish',
            'rename' => ['collectionId' => 'id'],
        ],
        'unpublishableUnpublish' => [
            'path'   => '/graphql/api/publishableUnpublish.json',
            'method' => 'POST',
            'field'  => 'publishableUnpublish',
            'rename' => ['collectionId' => 'id'],
        ],
        'metafieldDefinitionCreate' => [
            'path'   => '/graphql/api/metafieldDefinitionCreate.json',
            'method' => 'POST',
            'field'  => 'metafieldDefinitionCreate',
            'rename' => ['input' => 'definition'],
        ],
        'metafieldDefinitionUpdate' => [
            'path'   => '/graphql/api/metafieldDefinitionUpdate.json',
            'method' => 'PUT',
            'field'  => 'metafieldDefinitionUpdate',
            'rename' => ['input' => 'definition'],
        ],

        // --- Product (bulk) export -----------------------------------------
        'stagedUploadsCreate' => [
            'path'   => '/graphql/api/stagedUploadsCreate.json',
            'method' => 'POST',
            'field'  => 'stagedUploadsCreate',
        ],

        'bulkOperationRunMutation' => [
            'path'   => '/graphql/api/bulkOperationRunMutation.json',
            'method' => 'POST',
            'field'  => 'bulkOperationRunMutation',
            'wrap'   => 'input',
        ],
        'bulkOperationRunQuery' => [
            'path'   => '/graphql/api/bulkOperationRunQuery.json',
            'method' => 'POST',
            'field'  => 'bulkOperationRunQuery',
            'wrap'   => 'input',
        ],
        'bulkOperationStatus' => [
            'path'    => '/graphql/api/bulkOperationStatus.json',
            'method'  => 'GET',
            'field'   => 'bulkOperation',
            'respKey' => 'node',
        ],
        'bulkOperationCancel' => [
            'path'   => '/graphql/api/bulkOperationCancel.json',
            'method' => 'POST',
            'field'  => 'bulkOperationCancel',
        ],
        'productSet' => [
            'path'   => '/graphql/api/productSet.json',
            'method' => 'POST',
            'field'  => 'productSet',
            'rename' => ['input' => 'productSet'],
        ],

        // --- Translations --------------------------------------------------
        'createTranslation' => [
            'path'         => '/graphql/api/translationsRegister.json',
            'method'       => 'POST',
            'field'        => 'translationsRegister',
            'targetFromId' => true,
        ],


        'manualCollectionGetting' => [
            'path'       => '/graphql/api/collections.json',
            'method'     => 'GET',
            'connection' => 'collections',
            'defaults'   => ['first' => 10],
            'override'   => ['fields' => 'id,title,handle,descriptionHtml,seo{title,description},image{id,url},ruleSet{appliedDisjunctively},updatedAt,sortOrder,templateSuffix,productsCount{count,precision}'],
        ],
        'GetCollectionsByCursor' => [
            'path'       => '/graphql/api/collections.json',
            'method'     => 'GET',
            'connection' => 'collections',
            'rename'     => ['afterCursor' => 'after'],
            'override'   => ['fields' => 'id,title,handle,descriptionHtml,seo{title,description},image{id,url},ruleSet{appliedDisjunctively},updatedAt,sortOrder,templateSuffix,productsCount{count,precision}'],
        ],


        'productGettingOptions' => [
            'path'       => '/graphql/api/products.json',
            'method'     => 'GET',
            'connection' => 'products',
            'altKeys'    => ['product'],
            'defaults'   => ['first' => 50],
        ],
        'productOptionByCursor' => [
            'path'       => '/graphql/api/products.json',
            'method'     => 'GET',
            'connection' => 'products',
            'altKeys'    => ['product'],
            'rename'     => ['afterCursor' => 'after'],
        ],


        'productAllvalueGetting' => [
            'path'       => '/graphql/api/products.json',
            'method'     => 'GET',
            'connection' => 'products',
            'altKeys'    => ['product'],
            'defaults'   => ['first' => 20],
        ],
        'productAllvalueGettingByCursor' => [
            'path'       => '/graphql/api/products.json',
            'method'     => 'GET',
            'connection' => 'products',
            'altKeys'    => ['product'],
            'rename'     => ['afterCursor' => 'after'],
        ],


        'metafieldDefinitionsProductType' => [
            'path'       => '/graphql/api/metafieldDefinitions.json',
            'method'     => 'GET',
            'connection' => 'metafieldDefinitions',
            'override'   => [
                'first'     => 250,
                'ownerType' => 'PRODUCT',
                'fields'    => 'id namespace key name ownerType pinnedPosition type { name } validations { name type value } constraints { key values(first: 250) { nodes { value } } }',
            ],
        ],
        'metafieldDefinitionsProductVariantType' => [
            'path'       => '/graphql/api/metafieldDefinitions.json',
            'method'     => 'GET',
            'connection' => 'metafieldDefinitions',
            'override'   => ['first' => 250, 'ownerType' => 'PRODUCTVARIANT'],
        ],


        'getCollectionTranslations' => [
            'path'    => '/graphql/api/translatableResource.json',
            'method'  => 'GET',
            'field'   => 'translatableResource',
            'respKey' => 'node',
        ],

        'metaobjectDefinitions' => [
            'path'       => '/graphql/api/metaobjectDefinitions.json',
            'method'     => 'POST',
            'connection' => 'metaobjectDefinitions',
            'override'   => ['fields' => 'id name type'],
        ],
        'metaobjectDefinitionByType' => [
            'path'     => '/graphql/api/metaobjectDefinitionsByType/{type}.json',
            'method'   => 'POST',
            'field'    => 'metaobjectDefinitionByType',
            'override' => ['fields' => 'id name type fieldDefinitions{ key name required type{ name } validations{ name value } }'],
        ],
        'metaobjectDefinitionCreate' => [
            'path'   => '/graphql/api/metaobjectDefinitionCreate.json',
            'method' => 'POST',
            'field'  => 'metaobjectDefinitionCreate',
        ],
        'metaobjectDefinitionUpdate' => [
            'path'   => '/graphql/api/metaobjectDefinitionUpdate.json',
            'method' => 'PUT',
            'field'  => 'metaobjectDefinitionUpdate',
        ],
        'metaobjectUpsert' => [
            'path'     => '/graphql/api/metaobjectUpsert.json',
            'method'   => 'PUT',
            'field'    => 'metaobjectUpsert',
            'override' => ['fields' => 'id handle'],
        ],
        'getMetaobjectsByType' => [
            'path'       => '/graphql/api/metaobjects.json',
            'method'     => 'GET',
            'connection' => 'metaobjects',
            'override'   => ['fields' => 'id type handle fields{ key value }'],
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
                'locale'    => $locale['locale'],
                'name'      => $locale['name'] ?? $locale['locale'],
                'primary'   => (bool) ($locale['primary'] ?? false),
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
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
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
                'url'     => $url,
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
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer '.$this->jwt,
            ])
                ->timeout($this->timeout)
                ->post($url, $payload);

            return [
                'ok'   => $response->successful(),
                'code' => $response->status(),
                'body' => $response->json() ?? [],
            ];
        } catch (\Throwable $e) {
            Log::warning('Shopify SaaS proxy sync failed', [
                'url'     => $url,
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

        if ($operation === 'getFileById') {
            return $this->getFilesByIds((array) ($variables['ids'] ?? []));
        }

        if (! isset($this->proxyEndpoints[$operation])) {
            return $this->proxyErrorResponse(null, "Shopify SaaS proxy does not support the '{$operation}' operation.");
        }

        $definition = $this->proxyEndpoints[$operation];

        $path = preg_replace_callback('/\{(\w+)\}/', function ($matches) use (&$variables) {
            $value = rawurlencode((string) ($variables[$matches[1]] ?? ''));
            unset($variables[$matches[1]]);

            return $value;
        }, $definition['path']);

        $url = rtrim($this->baseUrl, '/').$path;


        $dataKey = $definition['connection'] ?? $definition['field'] ?? $operation;

        try {
            $request = Http::withHeaders([
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
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
                'url'      => $url,
                'message'  => $e->getMessage(),
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

        foreach ($definition['defaults'] ?? [] as $key => $value) {
            if (! isset($body[$key]) || $body[$key] === '') {
                $body[$key] = $value;
            }
        }

        foreach ($definition['override'] ?? [] as $key => $value) {
            $body[$key] = $value;
        }


        if (! empty($definition['targetFromId'])) {
            $resourceId = $body['id'] ?? null;
            unset($body['id']);
            $body['resourceId'] = $resourceId;
            $body['translations'] = array_map(
                fn ($translation) => $translation + ['target' => $resourceId],
                $body['translations'] ?? []
            );
        }


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

    protected function getFilesByIds(array $ids): array
    {
        $ids = array_values(array_filter($ids));

        if (empty($ids)) {
            return ['code' => 200, 'body' => ['data' => ['nodes' => []]]];
        }

        $query = http_build_query([
            'query'  => implode(' OR ', array_map(fn ($gid) => 'id:'.preg_replace('#^.*/#', '', (string) $gid), $ids)),
            'fields' => 'id fileStatus preview{image{url}} ... on MediaImage{ image{url} } ... on Video{ sources{url} } ... on GenericFile{ url }',
            'first'  => count($ids),
        ]);

        $result = $this->get('/graphql/api/files.json?'.$query);

        return [
            'code' => $result['code'] ?? null,
            'body' => ['data' => ['nodes' => $result['body']['files']['nodes'] ?? []]],
        ];
    }

    protected function get(string $path): array
    {
        $url = rtrim($this->baseUrl, '/').$path;

        try {
            $response = Http::withHeaders([
                'Accept'        => 'application/json',
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
                'url'     => $url,
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
