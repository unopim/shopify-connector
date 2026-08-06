<?php

return [
    [
        'key'   => 'shopify',
        'name'  => 'shopify::app.components.layouts.sidebar.shopify',
        'route' => 'shopify.credentials.index',
        'sort'  => 11,
    ], [
        'key'   => 'shopify.credentials',
        'name'  => 'shopify::app.components.layouts.sidebar.credentials',
        'route' => 'shopify.credentials.index',
        'sort'  => 1,
    ], [
        'key'   => 'shopify.credentials.create',
        'name'  => 'shopify::app.shopify.acl.credential.create',
        'route' => 'shopify.credentials.store',
        'sort'  => 1,
    ], [
        'key'   => 'shopify.credentials.edit',
        'name'  => 'shopify::app.shopify.acl.credential.edit',
        'route' => 'shopify.credentials.edit',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.credentials.delete',
        'name'  => 'shopify::app.shopify.acl.credential.delete',
        'route' => 'shopify.credentials.delete',
        'sort'  => 3,
    ], [
        'key'   => 'shopify.export-mappings',
        'name'  => 'shopify::app.components.layouts.sidebar.export-mappings',
        'route' => 'admin.shopify.export-mappings',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.collection-mappings',
        'name'  => 'shopify::app.components.layouts.sidebar.collection-mappings',
        'route' => 'admin.shopify.collection-mappings',
        'sort'  => 3,
    ], [
        'key'   => 'shopify.import-mappings',
        'name'  => 'shopify::app.components.layouts.sidebar.import-mappings',
        'route' => 'admin.shopify.import-mappings',
        'sort'  => 3,
    ], [
        'key'   => 'shopify.meta-fields',
        'name'  => 'shopify::app.components.layouts.sidebar.metafield-definitions',
        'route' => 'shopify.metafield.index',
        'sort'  => 4,
    ], [
        'key'   => 'shopify.meta-fields.create',
        'name'  => 'shopify::app.shopify.acl.metafield.create',
        'route' => 'shopify.metafield.store',
        'sort'  => 1,
    ], [
        'key'   => 'shopify.meta-fields.edit',
        'name'  => 'shopify::app.shopify.acl.metafield.edit',
        'route' => 'shopify.metafield.edit',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.meta-fields.delete',
        'name'  => 'shopify::app.shopify.acl.metafield.delete',
        'route' => 'shopify.metafield.delete',
        'sort'  => 3,
    ], [
        'key'   => 'shopify.metaobjects',
        'name'  => 'shopify::app.components.layouts.sidebar.metaobjects',
        'route' => 'shopify.metaobject.index',
        'sort'  => 5,
    ], [
        'key'   => 'shopify.metaobjects.store',
        'name'  => 'shopify::app.shopify.acl.metaobject.save',
        'route' => 'shopify.metaobject.store',
        'sort'  => 1,
    ], [
        'key'   => 'shopify.metaobjects.edit',
        'name'  => 'shopify::app.shopify.acl.metaobject.edit',
        'route' => 'shopify.metaobject.edit',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.metaobjects.delete',
        'name'  => 'shopify::app.shopify.acl.metaobject.delete',
        'route' => 'shopify.metaobject.destroy',
        'sort'  => 3,
    ], [
        'key'   => 'shopify.metaobjects.entry-save',
        'name'  => 'shopify::app.shopify.acl.metaobject-entry.save',
        'route' => 'shopify.metaobject.entry.store',
        'sort'  => 4,
    ], [
        'key'   => 'shopify.metaobjects.entry-delete',
        'name'  => 'shopify::app.shopify.acl.metaobject-entry.delete',
        'route' => 'shopify.metaobject.entry.delete',
        'sort'  => 5,
    ], [
        'key'   => 'shopify.settings',
        'name'  => 'shopify::app.components.layouts.sidebar.settings',
        'route' => 'admin.shopify.settings',
        'sort'  => 6,
    ],
];
