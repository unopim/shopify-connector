<?php

return [
    'exporters' => [
        'shopify' => [
            'product'  => 'Shopify Product',
            'category' => 'Shopify Category',
        ],
    ],
    'components' => [
        'layouts' => [
            'sidebar' => [
                'shopify'         => 'Shopify',
                'credentials'     => 'Credentials',
                'export-mappings' => 'Export Mappings',
                'settings'        => 'Settings',
            ],
        ],
    ],

    'shopify' => [
        'acl' => [
            'credential' => [
                'create' => 'Create',
                'edit'   => 'Edit',
                'delete' => 'Delete',
            ],
        ],

        'version' => 'Version: 1.0.0',

        'credential' => [
            'export' => [
                'locales' => 'Locale Mapping',
            ],
            'shopify' => [
                'locale' => 'Shopify Locale',
            ],
            'unopim' => [
                'locale' => 'Unopim Locale',
            ],
            'delete-success' => 'Credential Deleted Success',
            'created'        => 'Credential Created Success',
            'update-success' => 'Credential Updated Success',
            'invalid'        => 'Invalid Credential',
            'invalidurl'     => 'Invalid URL',
            'index'          => [
                'title'                 => 'Shopify Credentials',
                'create'                => 'Create Credential',
                'url'                   => 'Shopify URL',
                'shopifyurlplaceholder' => 'Shopify URL (http://demo.myshopify.com)',
                'accesstoken'           => 'Admin API access token',
                'apiVersion'            => 'API Version',
                'save'                  => 'Save',
                'back-btn'              => 'Back',
                'channel'               => 'Publishing (Sales channels)',
                'locations'             => 'Location List',
            ],
            'edit' => [
                'title'    => 'Edit Credential',
                'delete'   => 'Delete Credential',
                'back-btn' => 'Back',
                'update'   => 'Update',
                'save'     => 'Save',
            ],
            'datagrid' => [
                'shopUrl'    => 'Shopify URL',
                'apiVersion' => 'API Version',
                'enabled'    => 'Enable',

            ],
        ],
        'export' => [
            'mapping' => [
                'title'         => 'Export Mappings',
                'back-btn'      => 'Back',
                'save'          => 'Save',
                'created'       => 'Export Mapping saved successfully',
                'image'         => 'Attribute to used as image',
                'metafields'    => 'Attributes to be used as Metafields',
                'filed-shopify' => 'Field in Shopify',
                'attribute'     => 'Attribute',
                'fixed-value'   => 'Fixed Value',
            ],

            'settings' => [
                'created' => 'Export Settings saved successfully',
            ],

            'setting' => [
                'title'                        => 'Setting',
                'tags'                         => 'Tags Export Setting',
                'enable_metric_tags_attribute' => 'Do you want to pull through the Metric UNIT name as well in tags ?',
                'enable_named_tags_attribute'  => 'Do you want to pull tags as Named Tags',
                'tagSeprator'                  => 'Use Attribute Name Separator in Tags',
                'enable_tags_attribute'        => 'Do you want to pull through the attribute name as well in tags ?',
                'metafields'                   => 'Meta Fields Export Setting',
                'metaFieldsKey'                => 'Use Key for Meta Field as Attribute Code / Label',
                'metaFieldsNameSpace'          => 'Use Namespace for Meta Field as Attribute Group Code / global',
                'other-settings'               => 'Other Settings',
                'roundof-attribute-value'      => 'Remove Extra fractional Zeros of Metric Attribute Value (e.g. 201.2000 as 201.2)',
                'option_name_label'            => 'Value for Option Name as Attribute Label (By Default Attribute Code)',
            ],

            'errors' => [
                'invalid-credential' => 'Invalid Credential.The credential is either disabled or incorrect',
                'invalid-locale'     => 'Invalid Locale. Please mapp the locale in credential edit section',
            ],
        ],
        'fields' => [
            'name'                        => 'Name',
            'description'                 => 'Description',
            'price'                       => 'Price',
            'weight'                      => 'Weight',
            'quantity'                    => 'Quantity',
            'inventory_tracked'           => 'Inventory Tracked',
            'allow_purchase_out_of_stock' => 'Allow Purchase Out of Stock',
            'vendor'                      => 'Vendor',
            'product_type'                => 'Product Type',
            'tags'                        => 'Tags',
            'barcode'                     => 'Barcode',
            'compare_at_price'            => 'Compare Price',
            'seo_title'                   => 'Seo Title',
            'seo_description'             => 'Seo Description',
            'handle'                      => 'Handle',
            'taxable'                     => 'Taxable',
            'inventory_cost'              => 'Cost per item',

        ],
        'exportmapping' => 'Attribute Mappings',
    ],

];
