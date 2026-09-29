<?php

namespace Webkul\Shopify\Support;

use Illuminate\View\ComponentAttributeBag;
use Webkul\DataTransfer\Models\JobInstancesProxy;
use Webkul\Shopify\Helpers\ShoifyMetaFieldType;
use Webkul\Shopify\Services\Measurement\MeasurementTypeRegistry;

class ProFeatures
{
    /**
     * Whether the Shopify Pro package is installed.
     *
     * The Pro package announces itself while booting, so an absent package
     * simply never sets the flag and nothing here names it.
     */
    public function isInstalled(): bool
    {
        return (bool) config('shopify.pro.installed', false);
    }

    public function upgradeUrl(): string
    {
        return $this->storeUrl();
    }

    public function storeUrl(): string
    {
        return (string) config('shopify.pro.url');
    }

    /**
     * Pro-only export filter names, keyed by entity type.
     *
     * A name is Pro only for the entities the Pro package contributes it to;
     * `currencies`, for instance, is a core filter on the product export.
     *
     * @return array<string, array<int, string>>
     */
    public function exportFilterMap(): array
    {
        return array_map(
            array_keys(...),
            (array) config('shopify.pro.export_filters', []),
        );
    }

    /**
     * Translated titles of the Pro filters, keyed by entity type and name.
     *
     * Core renders a few filters, such as categories, with its own label rather
     * than the shared field component, so those carry no id to match on. The
     * Pro package publishes translation keys, so the locale is resolved here
     * rather than frozen when it booted.
     *
     * @return array<string, array<string, string>>
     */
    public function exportFilterTitles(): array
    {
        $titles = [];

        foreach ((array) config('shopify.pro.export_filters', []) as $entityType => $fields) {
            foreach ($fields as $name => $titleKey) {
                if ($titleKey === null) {
                    continue;
                }

                $titles[$entityType][$name] = trans($titleKey);
            }
        }

        return $titles;
    }

    /**
     * The entity type of the export being edited, empty while creating one.
     */
    public function currentExportEntityType(): string
    {
        $id = request()->route('id');

        if (! $id) {
            return '';
        }

        return (string) (JobInstancesProxy::query()->whereKey($id)->value('entity_type') ?? '');
    }

    /**
     * The rendered badge, for the places that can only inject markup as a string.
     */
    public function badgeHtml(): string
    {
        return trim(view('shopify::components.pro-cta', ['variant' => 'badge', 'attributes' => new ComponentAttributeBag])->render());
    }

    /**
     * Suffix a dropdown option label, which cannot carry badge markup.
     */
    public function optionLabel(string $label): string
    {
        return trans('shopify::app.shopify.pro.option-label', ['label' => $label]);
    }

    /**
     * The metafield types Shopify Pro exports. Without it they are shown but
     * cannot be picked, so a definition never outlives the package that reads it.
     *
     * @return array<int, string>
     */
    public function lockedMetafieldTypes(): array
    {
        if ($this->isInstalled()) {
            return [];
        }

        return array_merge(
            [ShoifyMetaFieldType::MONEY],
            array_keys((new MeasurementTypeRegistry)->types()),
        );
    }

    /**
     * The export filters an entity offers but only Pro can act on, including the
     * schedule it keeps.
     *
     * @return array<int, string>
     */
    public function lockedExportFilters(string $entityType): array
    {
        return $this->isInstalled() ? [] : ($this->lockedExportFilterMap()[$entityType] ?? []);
    }

    /**
     * The same filters for every entity type, for the screens that decorate
     * whatever the current selection turns out to be.
     *
     * @return array<string, array<int, string>>
     */
    public function lockedExportFilterMap(): array
    {
        $map = $this->exportFilterMap();
        $schedule = array_column(config('shopify_schedule.fields', []), 'name');

        foreach ((array) config('shopify_schedule.entity_types', []) as $entityType) {
            $map[$entityType] = array_merge($map[$entityType] ?? [], $schedule);
        }

        return $map;
    }

    /**
     * Pro filters core renders under a card heading rather than a label, keyed
     * by the heading text so the screens can be read without an id to match on.
     *
     * @return array<string, string>
     */
    public function exportFilterHeadings(): array
    {
        return [
            trans('admin::app.settings.data-transfer.exports.create.attribute-conditions') => 'custom_attributes',
        ];
    }
}
