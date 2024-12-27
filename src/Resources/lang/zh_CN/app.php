<?php

return [
    'exporters' => [
        'shopify' => [
            'product'  => 'Shopify 产品',
            'category' => 'Shopify 类别',
        ],
    ],
    'importers' => [
        'shopify' => [
            'product'  => 'Shopify 产品',
            'category' => 'Shopify 类别',
            'attribute'=> 'Shopify 属性',
            'family'   => 'Shopify 家族',
            'metafield'=> 'Shopify 元字段定义',
        ],
    ],

    'components' => [
        'layouts' => [
            'sidebar' => [
                'shopify'         => 'Shopify',
                'credentials'     => '凭据',
                'export-mappings' => '导出映射',
                'settings'        => '设置',
            ],
        ],
    ],

    'shopify' => [
        'acl' => [
            'credential' => [
                'create' => '创建',
                'edit'   => '编辑',
                'delete' => '删除',
            ],
        ],

        'version' => '版本: 1.0.0',

        'credential' => [
            'export' => [
                'locales' => '语言映射',
            ],
            'shopify' => [
                'locale' => 'Shopify 语言',
            ],
            'unopim' => [
                'locale' => 'Unopim 语言',
            ],
            'delete-success' => '凭据删除成功',
            'created'        => '凭据创建成功',
            'update-success' => '更新成功',
            'invalid'        => '无效凭据',
            'invalidurl'     => '无效的 URL',
            'index'          => [
                'title'                 => 'Shopify 凭据',
                'create'                => '创建凭据',
                'url'                   => 'Shopify URL',
                'shopifyurlplaceholder' => 'Shopify URL（例如：http://demo.myshopify.com）',
                'accesstoken'           => '管理员 API 访问令牌',
                'apiVersion'            => 'API 版本',
                'save'                  => '保存',
                'back-btn'              => '返回',
                'channel'               => '发布渠道（销售渠道）',
                'locations'             => '位置列表',
            ],
            'edit' => [
                'title'    => '编辑凭据',
                'delete'   => '删除凭据',
                'back-btn' => '返回',
                'update'   => '更新',
                'save'     => '保存',
            ],
            'datagrid' => [
                'shopUrl'    => 'Shopify URL',
                'apiVersion' => 'API 版本',
                'enabled'    => '启用',

            ],
        ],
        'export' => [
            'mapping' => [
                'title'         => '导出映射',
                'back-btn'      => '返回',
                'save'          => '保存',
                'created'       => '导出映射已创建',
                'image'         => '用于图像的属性',
                'metafields'    => '用于元字段的属性',
                'filed-shopify' => 'Shopify 字段',
                'attribute'     => '属性',
                'fixed-value'   => '固定值',
            ],
            'setting' => [
                'title'                        => '设置',
                'tags'                         => '标签导出设置',
                'enable_metric_tags_attribute' => '是否要在标签中也包含公制单位名称？',
                'enable_named_tags_attribute'  => '是否要将标签导出为命名标签？',
                'tagSeprator'                  => '使用属性名称分隔符导出标签',
                'enable_tags_attribute'        => '是否要在标签中包含属性名称？',
                'metafields'                   => '元字段导出设置',
                'metaFieldsKey'                => '使用属性代码/标签作为元字段键',
                'metaFieldsNameSpace'          => '使用属性组代码/全局作为元字段命名空间',
                'other-settings'               => '其他设置',
                'roundof-attribute-value'      => '去除公制属性值的小数点后多余的零（例如，201.2000 显示为 201.2）',
                'option_name_label'            => '选项名称的值使用属性标签（默认使用属性代码）',
            ],

            'errors' => [
                'invalid-credential' => '凭证无效。凭证已禁用或不正确',
                'invalid-locale'     => '无效的区域设置。请在凭据编辑部分中映射区域设置',
            ],
        ],
        'fields' => [
            'name'                        => '名称',
            'description'                 => '描述',
            'price'                       => '价格',
            'weight'                      => '重量',
            'quantity'                    => '数量',
            'inventory_tracked'           => '库存跟踪',
            'allow_purchase_out_of_stock' => '允许缺货购买',
            'vendor'                      => '供应商',
            'product_type'                => '产品类型',
            'tags'                        => '标签',
            'barcode'                     => '条形码',
            'compare_at_price'            => '比较价格',
            'seo_title'                   => 'SEO 标题',
            'seo_description'             => 'SEO 描述',
            'handle'                      => 'Handle',
            'taxable'                     => '应税',
            'inventory_cost'              => '库存成本',
        ],
        'exportmapping' => '属性映射',
    ],
];
