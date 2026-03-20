<?php

use Webkul\DataTransfer\Models\JobInstances;

use function Pest\Laravel\postJson;

it('should create the shopify category export job', function () {
    $this->loginAsAdmin();
    $exportJob = [
        'code'            => fake()->unique()->word,
        'entity_type'     => 'shopifyCategories',
        'filters'         => [
            'credentials' => 1,
        ],
    ];

    $response = postJson(route('admin.settings.data_transfer.exports.store'), $exportJob);
    $response->assertStatus(302)
        ->assertSessionHas('success');

    $this->assertDatabaseHas($this->getFullTableName(JobInstances::class), [
        'code'        => $exportJob['code'],
        'entity_type' => 'shopifyCategories',
    ]);
});

it('should create the shopify product export job', function () {
    $this->loginAsAdmin();
    $exportJob = [
        'code'            => fake()->unique()->word,
        'entity_type'     => 'shopifyProduct',
        'filters'         => [
            'credentials' => '1',
            'channel'     => 'default',
            'currency'    => 'USD',
        ],
    ];

    $response = postJson(route('admin.settings.data_transfer.exports.store'), $exportJob);
    $response->assertStatus(302)
        ->assertSessionHas('success');

    $this->assertDatabaseHas($this->getFullTableName(JobInstances::class), [
        'code'        => $exportJob['code'],
        'entity_type' => 'shopifyProduct',
    ]);
});

it('should create the shopify Metafield Defintion export job', function () {
    $this->loginAsAdmin();
    $exportJob = [
        'code'            => fake()->unique()->word,
        'entity_type'     => 'shopifyMetafield',
        'filters'         => [
            'credentials' => 1,
        ],
    ];

    $response = postJson(route('admin.settings.data_transfer.exports.store'), $exportJob);
    $response->assertStatus(302)
        ->assertSessionHas('success');

    $this->assertDatabaseHas($this->getFullTableName(JobInstances::class), [
        'code'        => $exportJob['code'],
        'entity_type' => 'shopifyMetafield',
    ]);
});
