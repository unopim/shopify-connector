<?php

use Webkul\Shopify\Services\Bulk\Metaobject\MetaobjectValueResolver;

/**
 * Seed the resolver's in-memory caches so resolveAttributes() can be tested
 * without touching Shopify or the database.
 */
function seedMetaobjectResolver(array $entriesByGid, array $mappingByType): MetaobjectValueResolver
{
    $resolver = app(MetaobjectValueResolver::class);
    $reflection = new ReflectionClass($resolver);

    foreach (['entriesByGid' => $entriesByGid, 'mappingByType' => $mappingByType, 'loaded' => true] as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($resolver, $value);
    }

    return $resolver;
}

it('resolves a flat metaobject reference to mapped attribute values', function () {
    $resolver = seedMetaobjectResolver(
        [
            'gid://shopify/Metaobject/1' => [
                'id' => 'gid://shopify/Metaobject/1',
                'type' => 'laptop',
                'fields' => ['manufacturer' => 'lenovo', 'ram' => '8'],
            ],
        ],
        [
            'laptop' => [
                'manufacturer' => ['key' => 'manufacturer', 'source' => 'attribute', 'attribute_code' => 'Manufacturer'],
                'ram' => ['key' => 'ram', 'source' => 'attribute', 'attribute_code' => 'Installed_RAM'],
            ],
        ]
    );

    expect($resolver->resolveAttributes('gid://shopify/Metaobject/1'))
        ->toEqual(['Manufacturer' => 'lenovo', 'Installed_RAM' => '8']);
});

it('resolves nested metaobject references at multiple levels', function () {
    $resolver = seedMetaobjectResolver(
        [
            'gid://shopify/Metaobject/1' => [
                'id' => 'gid://shopify/Metaobject/1',
                'type' => 'laptop',
                'fields' => ['manufacturer' => 'lenovo', 'cpu' => 'gid://shopify/Metaobject/2'],
            ],
            'gid://shopify/Metaobject/2' => [
                'id' => 'gid://shopify/Metaobject/2',
                'type' => 'processor',
                'fields' => ['generation' => '13th', 'memory' => 'gid://shopify/Metaobject/3'],
            ],
            'gid://shopify/Metaobject/3' => [
                'id' => 'gid://shopify/Metaobject/3',
                'type' => 'memory',
                'fields' => ['ram_type' => 'DDR5'],
            ],
        ],
        [
            'laptop' => [
                'manufacturer' => ['key' => 'manufacturer', 'source' => 'attribute', 'attribute_code' => 'Manufacturer'],
                'cpu' => ['key' => 'cpu', 'source' => 'metaobject', 'attribute_code' => ''],
            ],
            'processor' => [
                'generation' => ['key' => 'generation', 'source' => 'attribute', 'attribute_code' => 'Generation'],
                'memory' => ['key' => 'memory', 'source' => 'metaobject', 'attribute_code' => ''],
            ],
            'memory' => [
                'ram_type' => ['key' => 'ram_type', 'source' => 'attribute', 'attribute_code' => 'RAM_Type'],
            ],
        ]
    );

    expect($resolver->resolveAttributes('gid://shopify/Metaobject/1'))
        ->toEqual([
            'Manufacturer' => 'lenovo',
            'Generation' => '13th',
            'RAM_Type' => 'DDR5',
        ]);
});

it('expands a json list of metaobject references', function () {
    $resolver = seedMetaobjectResolver(
        [
            'gid://shopify/Metaobject/1' => [
                'id' => 'gid://shopify/Metaobject/1',
                'type' => 'laptop',
                'fields' => ['parts' => json_encode(['gid://shopify/Metaobject/2', 'gid://shopify/Metaobject/3'])],
            ],
            'gid://shopify/Metaobject/2' => ['id' => 'gid://shopify/Metaobject/2', 'type' => 'part', 'fields' => ['a' => 'x']],
            'gid://shopify/Metaobject/3' => ['id' => 'gid://shopify/Metaobject/3', 'type' => 'part', 'fields' => ['b' => 'y']],
        ],
        [
            'laptop' => ['parts' => ['key' => 'parts', 'source' => 'metaobject', 'attribute_code' => '']],
            'part' => [
                'a' => ['key' => 'a', 'source' => 'attribute', 'attribute_code' => 'AttrA'],
                'b' => ['key' => 'b', 'source' => 'attribute', 'attribute_code' => 'AttrB'],
            ],
        ]
    );

    expect($resolver->resolveAttributes('gid://shopify/Metaobject/1'))
        ->toEqual(['AttrA' => 'x', 'AttrB' => 'y']);
});

it('guards against cyclic metaobject references and terminates', function () {
    $resolver = seedMetaobjectResolver(
        [
            'gid://shopify/Metaobject/1' => [
                'id' => 'gid://shopify/Metaobject/1', 'type' => 'a',
                'fields' => ['ref' => 'gid://shopify/Metaobject/2', 'x' => 'X'],
            ],
            'gid://shopify/Metaobject/2' => [
                'id' => 'gid://shopify/Metaobject/2', 'type' => 'b',
                'fields' => ['ref' => 'gid://shopify/Metaobject/1', 'y' => 'Y'],
            ],
        ],
        [
            'a' => [
                'ref' => ['key' => 'ref', 'source' => 'metaobject', 'attribute_code' => ''],
                'x' => ['key' => 'x', 'source' => 'attribute', 'attribute_code' => 'AttrX'],
            ],
            'b' => [
                'ref' => ['key' => 'ref', 'source' => 'metaobject', 'attribute_code' => ''],
                'y' => ['key' => 'y', 'source' => 'attribute', 'attribute_code' => 'AttrY'],
            ],
        ]
    );

    expect($resolver->resolveAttributes('gid://shopify/Metaobject/1'))
        ->toEqual(['AttrX' => 'X', 'AttrY' => 'Y']);
});

it('returns an empty array for an unknown or empty gid', function () {
    $resolver = seedMetaobjectResolver([], []);

    expect($resolver->resolveAttributes('gid://shopify/Metaobject/999'))->toBe([])
        ->and($resolver->resolveAttributes(''))->toBe([]);
});

it('skips fields with no mapping or an empty value', function () {
    $resolver = seedMetaobjectResolver(
        [
            'gid://shopify/Metaobject/1' => [
                'id' => 'gid://shopify/Metaobject/1',
                'type' => 'laptop',
                'fields' => ['manufacturer' => 'lenovo', 'unmapped' => 'z', 'blank' => ''],
            ],
        ],
        [
            'laptop' => [
                'manufacturer' => ['key' => 'manufacturer', 'source' => 'attribute', 'attribute_code' => 'Manufacturer'],
                'blank' => ['key' => 'blank', 'source' => 'attribute', 'attribute_code' => 'Blank'],
            ],
        ]
    );

    expect($resolver->resolveAttributes('gid://shopify/Metaobject/1'))
        ->toEqual(['Manufacturer' => 'lenovo']);
});

it('normalizes a raw metaobject node into id/type/fields', function () {
    $resolver = app(MetaobjectValueResolver::class);
    $method = (new ReflectionClass($resolver))->getMethod('normalizeEntry');
    $method->setAccessible(true);

    expect($method->invoke($resolver, [
        'id' => 'gid://shopify/Metaobject/1',
        'type' => 'laptop',
        'fields' => [
            ['key' => 'manufacturer', 'value' => 'lenovo'],
            ['key' => 'ram', 'value' => '8'],
        ],
    ]))->toEqual([
        'id' => 'gid://shopify/Metaobject/1',
        'type' => 'laptop',
        'fields' => ['manufacturer' => 'lenovo', 'ram' => '8'],
    ]);
});

it('extracts gids from a single value or a json list', function () {
    $resolver = app(MetaobjectValueResolver::class);
    $method = (new ReflectionClass($resolver))->getMethod('extractGids');
    $method->setAccessible(true);

    expect($method->invoke($resolver, 'gid://shopify/Metaobject/1'))
        ->toBe(['gid://shopify/Metaobject/1'])
        ->and($method->invoke($resolver, json_encode(['gid://shopify/Metaobject/1', 'gid://shopify/Metaobject/2'])))
        ->toBe(['gid://shopify/Metaobject/1', 'gid://shopify/Metaobject/2']);
});
