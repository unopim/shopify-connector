<?php

return [
    'entity_types' => [
        'shopifyProduct',
        'shopifyCategories',
        'shopifyMetafield',
        'shopifyMetaobject',
    ],

    /**
     * The schedule a merchant picks. The presets it offers, and everything kept
     * beside them, belong to the package that runs the export unattended.
     */
    'fields' => [
        [
            'name'     => 'schedule_cron_preset',
            'title'    => 'shopify::app.export.schedule.preset',
            'info'     => 'shopify::app.export.schedule.preset-info',
            'required' => false,
            'type'     => 'select',
            'options'  => [],
        ],
    ],
];
