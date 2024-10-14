<?php

return [
    'exporters' => [
        'shopify' => [
            'product'  => 'Producto de Shopify',
            'category' => 'Categoría de Shopify',
        ],
    ],
    'components' => [
        'layouts' => [
            'sidebar' => [
                'shopify'         => 'Shopify',
                'credentials'     => 'Credenciales',
                'export-mappings' => 'Mapeos de Exportación',
                'settings'        => 'Configuraciones',
            ],
        ],
    ],

    'shopify' => [
        'acl' => [
            'credential' => [
                'create' => 'Crear',
                'edit'   => 'Editar',
                'delete' => 'Eliminar',
            ],
        ],

        'version' => 'Versión: 1.0.0',

        'credential' => [
            'export' => [
                'locales' => 'Mapeo de Locales',
            ],
            'shopify' => [
                'locale' => 'Local de Shopify',
            ],
            'unopim' => [
                'locale' => 'Local de Unopim',
            ],
            'delete-success' => 'Credencial Eliminada con Éxito',
            'created'        => 'Credencial Creada con Éxito',
            'update-success' => 'Actualización Exitosa',
            'invalid'        => 'Credencial Inválida',
            'invalidurl'     => 'URL Inválida',
            'index'          => [
                'title'                 => 'Credenciales de Shopify',
                'create'                => 'Crear Credencial',
                'url'                   => 'URL de Shopify',
                'shopifyurlplaceholder' => 'URL de Shopify (ej. http://demo.myshopify.com)',
                'accesstoken'           => 'Token de acceso de Admin API',
                'apiVersion'            => 'Versión de API',
                'save'                  => 'Guardar',
                'back-btn'              => 'Volver',
                'channel'               => 'Publicación (Canales de venta)',
                'locations'             => 'Lista de Localizaciones',
            ],
            'edit' => [
                'title'    => 'Editar Credencial',
                'delete'   => 'Eliminar Credencial',
                'back-btn' => 'Volver',
                'update'   => 'Actualizar',
                'save'     => 'Guardar',
            ],
            'datagrid' => [
                'shopUrl'    => 'URL de Shopify',
                'apiVersion' => 'Versión de API',
                'enabled'    => 'Habilitar',
            ],
        ],
        'export' => [
            'mapping' => [
                'title'         => 'Mapeos de Exportación',
                'back-btn'      => 'Volver',
                'save'          => 'Guardar',
                'created'       => 'Mapeo de Exportación Creado',
                'image'         => 'Atributo usado como imagen',
                'metafields'    => 'Atributos usados como Metafields',
                'filed-shopify' => 'Campo en Shopify',
                'attribute'     => 'Atributo',
                'fixed-value'   => 'Valor Fijo',
            ],
            'setting' => [
                'title'                        => 'Configuración',
                'tags'                         => 'Configuración de Exportación de Etiquetas',
                'enable_metric_tags_attribute' => '¿Quieres incluir el nombre de la Unidad Métrica en las etiquetas?',
                'enable_named_tags_attribute'  => '¿Quieres incluir etiquetas nombradas?',
                'tagSeprator'                  => 'Usar Separador de Nombre de Atributo en las Etiquetas',
                'enable_tags_attribute'        => '¿Quieres incluir también el nombre del atributo en las etiquetas?',
                'metafields'                   => 'Configuración de Exportación de Meta Fields',
                'metaFieldsKey'                => 'Usar Clave para Meta Field como Código/Label del Atributo',
                'metaFieldsNameSpace'          => 'Usar Namespace para Meta Field como Código de Grupo de Atributo/global',
                'other-settings'               => 'Otras Configuraciones',
                'roundof-attribute-value'      => 'Eliminar Decimales Extras de los Valores Métricos (ej. 201.2000 como 201.2)',
                'option_name_label'            => 'Valor para el Nombre de Opción como Label del Atributo (Por Defecto Código de Atributo)',
            ],

            'errors' => [
                'invalid-credential' => 'Credencial no válida. La credencial está deshabilitada o es incorrecta.',
                'invalid-locale'     => 'Localización no válida. Por favor, mapea la localización en la sección de edición de credenciales',
            ],
        ],
        'fields' => [
            'name'                        => 'Nombre',
            'description'                 => 'Descripción',
            'price'                       => 'Precio',
            'weight'                      => 'Peso',
            'quantity'                    => 'Cantidad',
            'inventory_tracked'           => 'Inventario Rastreador',
            'allow_purchase_out_of_stock' => 'Permitir Compra sin Stock',
            'vendor'                      => 'Proveedor',
            'product_type'                => 'Tipo de Producto',
            'tags'                        => 'Etiquetas',
            'barcode'                     => 'Código de Barras',
            'compare_at_price'            => 'Comparar Precio',
            'seo_title'                   => 'Título SEO',
            'seo_description'             => 'Descripción SEO',
            'handle'                      => 'Manejar',
            'taxable'                     => 'Gravable',
            'inventory_cost'              => 'Costo de Inventario',
        ],
        'exportmapping' => 'Mapeo de Atributos',
    ],
];
