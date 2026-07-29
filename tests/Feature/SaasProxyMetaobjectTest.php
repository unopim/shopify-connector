<?php

use Illuminate\Support\Facades\Http;
use Webkul\Shopify\Http\Client\SaasProxyClient;

it('substitutes a path parameter and strips it from the request body', function () {
    Http::fake(['*' => Http::response(['metaobjectDefinitionByType' => null], 200)]);

    (new SaasProxyClient('https://proxy.test', 'jwt-token'))
        ->request('metaobjectDefinitionByType', ['type' => 'laptop specs', 'first' => 5]);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://proxy.test/graphql/api/metaobjectDefinitionsByType/laptop%20specs.json'
            && ! array_key_exists('type', $request->data())
            && ($request->data()['first'] ?? null) === 5;
    });
});

it('applies the endpoint override to the request body', function () {
    Http::fake(['*' => Http::response(['metaobjectUpsert' => ['metaobject' => []]], 200)]);

    (new SaasProxyClient('https://proxy.test', 'jwt-token'))
        ->request('metaobjectUpsert', ['handle' => ['type' => 'laptop'], 'fields' => 'ignored']);

    Http::assertSent(fn ($request) => ($request->data()['fields'] ?? null) === 'id handle');
});

it('forwards the metaobject definition create body verbatim', function () {
    Http::fake(['*' => Http::response(['metaobjectDefinitionCreate' => ['metaobjectDefinition' => ['id' => 'gid://shopify/MetaobjectDefinition/1']]], 200)]);

    (new SaasProxyClient('https://proxy.test', 'jwt-token'))
        ->request('metaobjectDefinitionCreate', ['definition' => ['type' => 'laptop', 'name' => 'Laptop']]);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://proxy.test/graphql/api/metaobjectDefinitionCreate.json'
            && ($request->data()['definition']['type'] ?? null) === 'laptop';
    });
});

it('passes the type as a query param and normalizes the metaobjects list', function () {
    Http::fake(['*' => Http::response([
        'metaobjects' => [
            'nodes' => [
                ['id' => 'gid://shopify/Metaobject/1', 'type' => 'brand', 'handle' => 'dell', 'fields' => []],
            ],
            'pageInfo' => ['hasNextPage' => false],
        ],
    ], 200)]);

    $result = (new SaasProxyClient('https://proxy.test', 'jwt-token'))
        ->request('getMetaobjectsByType', ['type' => 'brand', 'first' => 50]);

    Http::assertSent(function ($request) {
        return $request->method() === 'GET'
            && str_contains($request->url(), '/graphql/api/metaobjects.json')
            && ($request->data()['type'] ?? null) === 'brand';
    });

    expect($result['body']['data']['metaobjects']['edges'][0]['node']['handle'])->toBe('dell');
});

it('throws when the proxy jwt is missing', function () {
    (new SaasProxyClient('https://proxy.test', ''))->request('metaobjectDefinitions');
})->throws(InvalidArgumentException::class);

it('returns an error envelope for an unsupported operation without hitting the network', function () {
    Http::fake();

    $result = (new SaasProxyClient('https://proxy.test', 'jwt-token'))->request('notARealOperation');

    expect($result['code'])->toBeNull()
        ->and($result['body']['errors'][0]['message'])->toContain('does not support');

    Http::assertNothingSent();
});
