<?php

use Webkul\DataTransfer\Models\JobInstancesProxy;

use function Pest\Laravel\get;

/**
 * The schedule fields a Shopify exporter offers, as core renders them from the
 * exporter config.
 *
 * @return array<string, array<string, mixed>>
 */
function scheduleFields(string $entityType = 'shopifyProduct'): array
{
    return collect(config("exporters.{$entityType}.filters.fields", []))
        ->keyBy('name')
        ->all();
}

it('offers the schedule on every shopify export that can run unattended', function () {
    $entityTypes = config('shopify_schedule.entity_types', []);

    expect($entityTypes)->not->toBeEmpty();

    foreach ($entityTypes as $entityType) {
        expect(array_keys(scheduleFields($entityType)))->toContain('schedule_cron_preset');
    }
});

/**
 * Without Pro the connector offers the preset and nothing behind it: the
 * presets, the expression, the timezone and the run type shape a schedule
 * nothing would run, and the cron field they need never ships. What Pro then
 * adds lives in Pro's own suite.
 */
it('offers the preset with nothing behind it while the pro package is absent', function () {
    $preset = scheduleFields()['schedule_cron_preset'];

    expect($preset['options'])->toBe([])
        ->and(array_keys(scheduleFields()))
        ->not->toContain('schedule_cron_expression', 'schedule_timezone', 'schedule_type');
})->skip(fn (): bool => shopifyProBooted(), 'Pro is the one that names what a preset schedules.');

it('offers the preset alone while the pro package is absent', function () {
    $this->loginAsAdmin();

    $job = JobInstancesProxy::create([
        'code'        => 'schedule-teaser-'.uniqid(),
        'type'        => 'export',
        'entity_type' => 'shopifyProduct',
        'action'      => 'export',
        'filters'     => ['schedule_cron_preset' => '*/5 * * * *'],
    ]);

    $html = get(route('admin.settings.data_transfer.exports.edit', $job->id))->assertOk()->getContent();

    expect($html)->toContain('only="schedule_cron_preset"')
        ->not->toContain('v-field-cron');
})->skip(fn (): bool => shopifyProBooted(), 'Pro is the one that ships the rest of the card.');

it('saves a profile with no schedule without asking for an expression', function () {
    $this->loginAsAdmin();

    $response = $this->post(route('admin.settings.data_transfer.exports.store'), [
        'code'        => 'schedule-off-'.uniqid(),
        'entity_type' => 'shopifyProduct',
        'action'      => 'export',
        'filters'     => ['credentials' => 1, 'channels' => 'default', 'currencies' => 'USD'],
    ]);

    $response->assertSessionHasNoErrors();
});
