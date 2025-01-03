<?php

return [
    'exporters' => [
        'shopify' => [
            'product'  => 'Produit Shopify',
            'category' => 'Catégorie Shopify',
        ],
    ],
    'importers' => [
        'shopify' => [
            'product'  => 'Produit Shopify',
            'category' => 'Catégorie Shopify',
            'attribute'=> 'Attribut Shopify',
            'family'   => 'Famille Shopify',
            'metafield'=> 'Définitions des métachamps Shopify',
        ],
    ],

    'components' => [
        'layouts' => [
            'sidebar' => [
                'shopify'         => 'Shopify',
                'credentials'     => 'Identifiants',
                'export-mappings' => 'Mappages d\'exportation',
                'import-mappings' => 'Importer les correspondances',
                'settings'        => 'Paramètres',
            ],
        ],
    ],

    'shopify' => [
        'acl' => [
            'credential' => [
                'create' => 'Créer',
                'edit'   => 'Modifier',
                'delete' => 'Supprimer',
            ],
        ],

        'version' => 'Version : 1.0.0',

        'credential' => [
            'export' => [
                'locales' => 'Mappage de langue',
            ],
            'shopify' => [
                'locale' => 'Langue Shopify',
            ],
            'unopim' => [
                'locale' => 'Langue Unopim',
            ],
            'delete-success' => 'Identifiant supprimé avec succès',
            'created'        => 'Identifiant créé avec succès',
            'update-success' => 'Mise à jour réussie',
            'invalid'        => 'Identifiant invalide',
            'invalidurl'     => 'URL invalide',
            'already_taken'  => 'L URL de la boutique a déjà été prise.',
            'index'          => [
                'title'                 => 'Identifiants Shopify',
                'create'                => 'Créer un identifiant',
                'url'                   => 'URL Shopify',
                'shopifyurlplaceholder' => 'URL Shopify (ex. http://demo.myshopify.com)',
                'accesstoken'           => 'Jeton d\'accès API Admin',
                'apiVersion'            => 'Version de l\'API',
                'save'                  => 'Sauvegarder',
                'back-btn'              => 'Retour',
                'channel'               => 'Publication (Canaux de vente)',
                'locations'             => 'Liste des emplacements',
            ],
            'edit' => [
                'title'    => 'Modifier l\'identifiant',
                'delete'   => 'Supprimer l\'identifiant',
                'back-btn' => 'Retour',
                'update'   => 'Mettre à jour',
                'save'     => 'Sauvegarder',
            ],
            'datagrid' => [
                'shopUrl'    => 'URL Shopify',
                'apiVersion' => 'Version de l\'API',
                'enabled'    => 'Activer',
            ],
        ],
        'export' => [
            'mapping' => [
                'title'         => 'Mappages d\'exportation',
                'back-btn'      => 'Retour',
                'save'          => 'Sauvegarder',
                'created'       => 'Mappage d\'exportation créé',
                'image'         => 'Attribut à utiliser comme image',
                'metafields'    => 'Attributs à utiliser comme Méta-champs',
                'filed-shopify' => 'Champ dans Shopify',
                'attribute'     => 'Attribut',
                'fixed-value'   => 'Valeur fixe',
            ],
            'setting' => [
                'title'                        => 'Paramètre',
                'tags'                         => 'Paramètres d\'exportation des étiquettes',
                'enable_metric_tags_attribute' => 'Voulez-vous inclure le nom de l\'unité métrique dans les étiquettes?',
                'enable_named_tags_attribute'  => 'Voulez-vous inclure les étiquettes nommées?',
                'tagSeprator'                  => 'Utiliser le séparateur de nom d\'attribut dans les étiquettes',
                'enable_tags_attribute'        => 'Voulez-vous inclure le nom de l\'attribut dans les étiquettes?',
                'metafields'                   => 'Paramètres d\'exportation des Méta-champs',
                'metaFieldsKey'                => 'Utiliser la clé pour Méta-champ comme Code/Label d\'attribut',
                'metaFieldsNameSpace'          => 'Utiliser l\'espace de nom pour Méta-champ comme Code de groupe d\'attribut ou global',
                'other-settings'               => 'Autres paramètres',
                'roundof-attribute-value'      => 'Supprimer les zéros fractionnaires supplémentaires des valeurs d\'attributs métriques (ex. 201.2000 en 201.2)',
                'option_name_label'            => 'Valeur du nom de l\'option comme étiquette d\'attribut (par défaut Code d\'attribut)',
            ],

            'errors' => [
                'invalid-credential' => 'Informations d\'identification non valides. Les informations d\'identification sont désactivées ou incorrectes.',
                'invalid-locale'     => 'Locale invalide. Veuillez mapper la locale dans la section de modification des identifiants.',
            ],
        ],
        'import' => [
            'mapping' => [
                'title'                => 'Mappings d’importation',
                'back-btn'             => 'Retour',
                'save'                 => 'Enregistrer',
                'created'              => 'Mapping d’importation enregistré avec succès',
                'image'                => 'Attribut utilisé comme image',
                'filed-shopify'        => 'Champ dans Shopify',
                'attribute'            => 'Attribut UnoPim',
                'variantimage'         => 'Attribut utilisé comme image de variante',
                'other'                => 'Autres mappings Shopify',
                'family'               => 'Mapping de la famille (pour les produits)',
                'metafieldDefinitions' => 'Mapping des définitions de champs personnalisés Shopify',
            ],
            'setting' => [
                'credentialmapping' => 'Mapping des identifiants',
            ],
            'job' => [
                'product' => [
                    'family-not-exist'      => 'La famille n’existe pas pour le titre : - :title. Vous devez d’abord importer la famille',
                    'variant-sku-not-exist' => 'SKU de variante introuvable dans le produit : - :id',
                    'duplicate-sku'         => ':sku : - SKU en double trouvé dans le produit',
                    'required-field'        => ':attribute : - Le champ est requis pour le SKU : - :sku',
                    'family-not-mapping'    => 'La famille n’est pas mappée pour le titre : - :title',
                    'attribute-not-exist'   => 'Les attributs :attributes n’existent pas pour le produit',
                    'not-found-sku'         => 'SKU introuvable dans le produit : - :id',
                    'option-not-found'      => ':attribute - :option L’option est introuvable dans le SKU UnoPim : - :sku',
                ],
            ],
        ],

        'fields' => [
            'name'                        => 'Nom',
            'description'                 => 'Description',
            'price'                       => 'Prix',
            'weight'                      => 'Poids',
            'quantity'                    => 'Quantité',
            'inventory_tracked'           => 'Stock suivi',
            'allow_purchase_out_of_stock' => 'Autoriser l\'achat hors stock',
            'vendor'                      => 'Fournisseur',
            'product_type'                => 'Type de produit',
            'tags'                        => 'Étiquettes',
            'barcode'                     => 'Code-barres',
            'compare_at_price'            => 'Prix comparé',
            'seo_title'                   => 'Titre SEO',
            'seo_description'             => 'Description SEO',
            'handle'                      => 'Handle',
            'taxable'                     => 'Imposable',
            'inventory_cost'              => 'Coût des stocks',
        ],
        'exportmapping' => 'Mappages des attributs',
        'job'           => [
            'credentials'      => 'Identifiants Shopify',
            'channel'          => 'Canal',
            'currency'         => 'Devise',
            'productfilter'    => 'Filtre de produits (SKU)',
            'locale'           => 'Langue',
            'attribute-groups' => 'Groupes d’attributs',
        ],

    ],
];
