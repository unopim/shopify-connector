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
        'key'   => 'shopify.credentials.edit',
        'name'  => 'shopify::app.shopify.acl.credential.edit',
        'route' => 'shopify.credentials.update',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.credentials.edit',
        'name'  => 'shopify::app.shopify.acl.credential.edit',
        'route' => 'shopify.credentials.sync',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.credentials.delete',
        'name'  => 'shopify::app.shopify.acl.credential.delete',
        'route' => 'shopify.credentials.delete',
        'sort'  => 3,
    ], [
        'key'   => 'shopify.credentials.delete',
        'name'  => 'shopify::app.shopify.acl.credential.delete',
        'route' => 'shopify.credentials.revoke',
        'sort'  => 3,
    ], [
        'key'   => 'shopify.export-mappings',
        'name'  => 'shopify::app.components.layouts.sidebar.export-mappings',
        'route' => 'admin.shopify.export-mappings',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.export-mappings',
        'name'  => 'shopify::app.components.layouts.sidebar.export-mappings',
        'route' => 'shopify.export-mappings.create',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.export-mappings',
        'name'  => 'shopify::app.components.layouts.sidebar.export-mappings',
        'route' => 'shopify.realtime.index',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.collection-mappings',
        'name'  => 'shopify::app.components.layouts.sidebar.collection-mappings',
        'route' => 'admin.shopify.collection-mappings',
        'sort'  => 3,
    ], [
        'key'   => 'shopify.collection-mappings',
        'name'  => 'shopify::app.components.layouts.sidebar.collection-mappings',
        'route' => 'shopify.collection-mappings.create',
        'sort'  => 3,
    ], [
        'key'   => 'shopify.import-mappings',
        'name'  => 'shopify::app.components.layouts.sidebar.import-mappings',
        'route' => 'admin.shopify.import-mappings',
        'sort'  => 3,
    ], [
        'key'   => 'shopify.import-mappings',
        'name'  => 'shopify::app.components.layouts.sidebar.import-mappings',
        'route' => 'shopify.import-mappings.create',
        'sort'  => 3,
    ], [
        'key'   => 'shopify.meta-fields',
        'name'  => 'shopify::app.components.layouts.sidebar.metafield-definitions',
        'route' => 'shopify.metafield.index',
        'sort'  => 4,
    ], [
        'key'   => 'shopify.meta-fields.create',
        'name'  => 'admin::app.acl.create',
        'route' => 'shopify.metafield.store',
        'sort'  => 1,
    ], [
        'key'   => 'shopify.meta-fields.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'shopify.metafield.edit',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.meta-fields.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'shopify.metafield.update',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.meta-fields.delete',
        'name'  => 'admin::app.acl.delete',
        'route' => 'shopify.metafield.delete',
        'sort'  => 3,
    ], [
        'key'   => 'shopify.meta-fields.mass_delete',
        'name'  => 'admin::app.acl.mass-delete',
        'route' => 'shopify.metafield.mass_delete',
        'sort'  => 4,
    ], [
        'key'   => 'shopify.metaobjects',
        'name'  => 'shopify::app.components.layouts.sidebar.metaobjects',
        'route' => 'shopify.metaobject.index',
        'sort'  => 5,
    ], [
        'key'   => 'shopify.metaobjects.create',
        'name'  => 'admin::app.acl.create',
        'route' => 'shopify.metaobject.create',
        'sort'  => 1,
    ], [
        'key'   => 'shopify.metaobjects.create',
        'name'  => 'admin::app.acl.create',
        'route' => 'shopify.metaobject.store',
        'sort'  => 1,
    ], [
        'key'   => 'shopify.metaobjects.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'shopify.metaobject.edit',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.metaobjects.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'shopify.metaobject.update',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.metaobjects.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'shopify.metaobject.general',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.metaobjects.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'shopify.metaobject.field.store',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.metaobjects.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'shopify.metaobject.field.update',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.metaobjects.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'shopify.metaobject.field.destroy',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.metaobjects.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'shopify.metaobject.field.datagrid',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.metaobjects.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'shopify.metaobject.fields',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.metaobjects.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'shopify.metaobject.field.get',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.metaobjects.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'shopify.metaobject.entry.datagrid',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.metaobjects.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'shopify.metaobject.entry.columns',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.metaobjects.edit',
        'name'  => 'admin::app.acl.edit',
        'route' => 'shopify.metaobject.entry.list',
        'sort'  => 2,
    ], [
        'key'   => 'shopify.metaobjects.edit.create',
        'name'  => 'shopify::app.shopify.acl.metaobject-entry.create',
        'route' => 'shopify.metaobject.entry.store',
        'sort'  => 1,
    ], [
        'key'             => 'shopify.metaobjects.edit.edit',
        'name'            => 'shopify::app.shopify.acl.metaobject-entry.edit',
        'route'           => 'shopify.metaobject.entry.get',
        'sort'            => 2,
        'also_authorizes' => ['shopify.metaobject.entry.store'],
    ], [
        'key'   => 'shopify.metaobjects.edit.delete',
        'name'  => 'shopify::app.shopify.acl.metaobject-entry.delete',
        'route' => 'shopify.metaobject.entry.delete',
        'sort'  => 3,
    ], [
        'key'   => 'shopify.metaobjects.delete',
        'name'  => 'admin::app.acl.delete',
        'route' => 'shopify.metaobject.destroy',
        'sort'  => 3,
    ], [
        'key'   => 'shopify.metaobjects.mass_delete',
        'name'  => 'admin::app.acl.mass-delete',
        'route' => 'shopify.metaobject.mass_delete',
        'sort'  => 4,
    ], [
        'key'   => 'shopify.settings',
        'name'  => 'shopify::app.components.layouts.sidebar.settings',
        'route' => 'admin.shopify.settings',
        'sort'  => 6,
    ], [
        'key'   => 'shopify.settings',
        'name'  => 'shopify::app.components.layouts.sidebar.settings',
        'route' => 'shopify.export-settings.create',
        'sort'  => 6,
    ], [
        'key'   => 'shopify.credentials.catalogs',
        'name'  => 'shopify::app.shopify.catalogs.title',
        'route' => 'shopify.credentials.catalogs.index',
        'sort'  => 9,
    ], [
        'key'   => 'shopify.credentials.catalogs',
        'name'  => 'shopify::app.shopify.catalogs.title',
        'route' => 'shopify.credentials.realtime.index',
        'sort'  => 9,
    ],
];
