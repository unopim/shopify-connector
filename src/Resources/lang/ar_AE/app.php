<?php

return [
    'exporters' => [
        'shopify' => [
            'product'  => 'منتج شوبفاي',
            'category' => 'فئة شوبفاي',
        ],
    ],
    'components' => [
        'layouts' => [
            'sidebar' => [
                'shopify'         => 'شوبفاي',
                'credentials'     => 'بيانات الاعتماد',
                'export-mappings' => 'تعيينات التصدير',
                'settings'        => 'الإعدادات',
            ],
        ],
    ],

    'shopify' => [
        'acl' => [
            'credential' => [
                'create' => 'إنشاء',
                'edit'   => 'تحرير',
                'delete' => 'حذف',
            ],
        ],

        'version' => 'الإصدار: 1.0.0',

        'credential' => [
            'export' => [
                'locales' => 'تعيين اللغات',
            ],
            'shopify' => [
                'locale' => 'لغة شوبفاي',
            ],
            'unopim' => [
                'locale' => 'لغة يونوبيم',
            ],
            'delete-success' => 'تم حذف بيانات الاعتماد بنجاح',
            'created'        => 'تم إنشاء بيانات الاعتماد بنجاح',
            'update-success' => 'تم التحديث بنجاح',
            'invalid'        => 'بيانات الاعتماد غير صالحة',
            'invalidurl'     => 'رابط غير صالح',
            'index'          => [
                'title'                 => 'بيانات اعتماد شوبفاي',
                'create'                => 'إنشاء بيانات اعتماد',
                'url'                   => 'رابط شوبفاي',
                'shopifyurlplaceholder' => 'رابط شوبفاي (مثل http://demo.myshopify.com)',
                'accesstoken'           => 'رمز وصول API الإداري',
                'apiVersion'            => 'إصدار API',
                'save'                  => 'حفظ',
                'back-btn'              => 'عودة',
                'channel'               => 'قناة البيع',
                'locations'             => 'قائمة المواقع',
            ],
            'edit' => [
                'title'    => 'تحرير بيانات الاعتماد',
                'delete'   => 'حذف بيانات الاعتماد',
                'back-btn' => 'عودة',
                'update'   => 'تحديث',
                'save'     => 'حفظ',
            ],
            'datagrid' => [
                'shopUrl'    => 'رابط شوبفاي',
                'apiVersion' => 'إصدار API',
                'enabled'    => 'مفعل',
            ],
        ],
        'export' => [
            'mapping' => [
                'title'         => 'تعيينات التصدير',
                'back-btn'      => 'عودة',
                'save'          => 'حفظ',
                'created'       => 'تم إنشاء تعيين التصدير',
                'image'         => 'خاصية تستخدم كصورة',
                'metafields'    => 'خصائص تستخدم كحقول ميتا',
                'filed-shopify' => 'حقل في شوبفاي',
                'attribute'     => 'خاصية',
                'fixed-value'   => 'قيمة ثابتة',
            ],
            'setting' => [
                'title'                        => 'الإعدادات',
                'tags'                         => 'إعدادات تصدير العلامات',
                'enable_metric_tags_attribute' => 'هل تريد تضمين أسماء الوحدات المترية في العلامات أيضًا؟',
                'enable_named_tags_attribute'  => 'هل تريد تضمين العلامات كعلامات مسماة؟',
                'tagSeprator'                  => 'استخدام فاصل أسماء الخصائص في العلامات',
                'enable_tags_attribute'        => 'هل تريد تضمين اسم الخاصية في العلامات أيضًا؟',
                'metafields'                   => 'إعدادات تصدير الحقول الميتا',
                'metaFieldsKey'                => 'استخدام المفتاح للحقول الميتا كرمز الخاصية / التسمية',
                'metaFieldsNameSpace'          => 'استخدام النطاق للحقول الميتا كرمز مجموعة الخصائص / عام',
                'other-settings'               => 'إعدادات أخرى',
                'roundof-attribute-value'      => 'إزالة الكسور الإضافية من القيم المترية (مثل 201,2000 كـ 201.2)',
                'option_name_label'            => 'قيمة اسم الخيارات كاسم خاصية (افتراضيًا كود الخاصية)',
            ],

            'errors' => [
                'invalid-credential' => 'بيانات الاعتماد غير صالحة. بيانات الاعتماد معطلة أو غير صحيحة',
            ],
        ],
        'fields' => [
            'name'                        => 'اسم',
            'description'                 => 'وصف',
            'price'                       => 'سعر',
            'weight'                      => 'وزن',
            'quantity'                    => 'كمية',
            'inventory_tracked'           => 'تتبع المخزون',
            'allow_purchase_out_of_stock' => 'السماح بالشراء عند نفاد المخزون',
            'vendor'                      => 'بائع',
            'product_type'                => 'نوع المنتج',
            'tags'                        => 'علامات',
            'barcode'                     => 'رمز شريطي',
            'compare_at_price'            => 'سعر المقارنة',
            'seo_title'                   => 'عنوان SEO',
            'seo_description'             => 'وصف SEO',
            'handle'                      => 'معالجة',
            'taxable'                     => 'قابل للضريبة',
            'inventory_cost'              => 'تكلفة المخزون',
        ],
        'exportmapping' => 'تعيينات الخصائص',
    ],
];
