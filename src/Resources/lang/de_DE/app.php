<?php

return [
    'exporters' => [
        'shopify' => [
            'product'  => 'Shopify-Produkt',
            'category' => 'Shopify-Kategorie',
        ],
    ],
    'importers' => [
        'shopify' => [
            'product'  => 'Shopify-Produkt',
            'category' => 'Shopify-Kategorie',
            'attribute'=> 'Shopify-Attribut',
            'family'   => 'Shopify-Familie',
            'metafield'=> 'Metafelddefinitionen von Shopify',
        ],
    ],

    'components' => [
        'layouts' => [
            'sidebar' => [
                'shopify'         => 'Shopify',
                'credentials'     => 'Anmeldeinformationen',
                'export-mappings' => 'Export-Zuordnungen',
                'settings'        => 'Einstellungen',
            ],
        ],
    ],

    'shopify' => [
        'acl' => [
            'credential' => [
                'create' => 'Erstellen',
                'edit'   => 'Bearbeiten',
                'delete' => 'Löschen',
            ],
        ],

        'version' => 'Version: 1.0.0',

        'credential' => [
            'export' => [
                'locales' => 'Sprachzuordnung',
            ],
            'shopify' => [
                'locale' => 'Shopify-Sprache',
            ],
            'unopim' => [
                'locale' => 'Unopim-Sprache',
            ],
            'delete-success' => 'Anmeldeinformationen erfolgreich gelöscht',
            'created'        => 'Anmeldeinformationen erfolgreich erstellt',
            'update-success' => 'Erfolgreich aktualisiert',
            'invalid'        => 'Ungültige Anmeldeinformationen',
            'invalidurl'     => 'Ungültige URL',
            'index'          => [
                'title'                 => 'Shopify-Anmeldeinformationen',
                'create'                => 'Anmeldeinformationen erstellen',
                'url'                   => 'Shopify-URL',
                'shopifyurlplaceholder' => 'Shopify-URL (z.B. http://demo.myshopify.com)',
                'accesstoken'           => 'Admin-API-Zugriffstoken',
                'apiVersion'            => 'API-Version',
                'save'                  => 'Speichern',
                'back-btn'              => 'Zurück',
                'channel'               => 'Veröffentlichung (Verkaufskanäle)',
                'locations'             => 'Standortliste',
            ],
            'edit' => [
                'title'    => 'Anmeldeinformationen bearbeiten',
                'delete'   => 'Anmeldeinformationen löschen',
                'back-btn' => 'Zurück',
                'update'   => 'Aktualisieren',
                'save'     => 'Speichern',
            ],
            'datagrid' => [
                'shopUrl'    => 'Shopify-URL',
                'apiVersion' => 'API-Version',
                'enabled'    => 'Aktivieren',
            ],
        ],
        'export' => [
            'mapping' => [
                'title'         => 'Export-Zuordnungen',
                'back-btn'      => 'Zurück',
                'save'          => 'Speichern',
                'created'       => 'Export-Zuordnung erstellt',
                'image'         => 'Attribut, das als Bild verwendet wird',
                'metafields'    => 'Attribute, die als Metafelder verwendet werden',
                'filed-shopify' => 'Feld in Shopify',
                'attribute'     => 'Attribut',
                'fixed-value'   => 'Fester Wert',
            ],
            'setting' => [
                'title'                        => 'Einstellungen',
                'tags'                         => 'Tag-Export-Einstellungen',
                'enable_metric_tags_attribute' => 'Möchten Sie auch den metrischen Einheitennamen in Tags übernehmen?',
                'enable_named_tags_attribute'  => 'Möchten Sie Tags als benannte Tags übernehmen?',
                'tagSeprator'                  => 'Attributnamen-Trenner in Tags verwenden',
                'enable_tags_attribute'        => 'Möchten Sie auch den Attributnamen in Tags übernehmen?',
                'metafields'                   => 'Metafelder-Export-Einstellungen',
                'metaFieldsKey'                => 'Schlüssel für Metafeld als Attributcode / Beschriftung verwenden',
                'metaFieldsNameSpace'          => 'Namespace für Metafeld als Attributgruppen-Code / global verwenden',
                'other-settings'               => 'Weitere Einstellungen',
                'roundof-attribute-value'      => 'Zusätzliche Bruchstellen bei metrischen Attributwerten entfernen (z.B. 201,2000 als 201,2)',
                'option_name_label'            => 'Wert für Optionsnamen als Attributbeschriftung (Standardmäßig Attributcode)',
            ],

            'errors' => [
                'invalid-credential' => 'Ungültige Anmeldeinformationen. Die Anmeldeinformationen sind entweder deaktiviert oder falsch',
                'invalid-locale'     => 'Ungültige Locale. Bitte mappen Sie die Locale im Abschnitt zur Bearbeitung der Anmeldeinformationen.',
            ],
        ],
        'fields' => [
            'name'                        => 'Name',
            'description'                 => 'Beschreibung',
            'price'                       => 'Preis',
            'weight'                      => 'Gewicht',
            'quantity'                    => 'Menge',
            'inventory_tracked'           => 'Bestand nachverfolgt',
            'allow_purchase_out_of_stock' => 'Kauf bei Lagerknappheit erlauben',
            'vendor'                      => 'Lieferant',
            'product_type'                => 'Produkttyp',
            'tags'                        => 'Tags',
            'barcode'                     => 'Strichcode',
            'compare_at_price'            => 'Vergleichspreis',
            'seo_title'                   => 'SEO-Titel',
            'seo_description'             => 'SEO-Beschreibung',
            'handle'                      => 'Handle',
            'taxable'                     => 'Besteuerbar',
            'inventory_cost'              => 'Lagerkosten',
        ],
        'exportmapping' => 'Attributzuordnungen',
    ],
];
