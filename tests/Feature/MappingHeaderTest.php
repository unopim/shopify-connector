<?php

use function Pest\Laravel\get;

beforeEach(function () {
    $this->loginAsAdmin();
});

dataset('mapping pages', [
    'export mapping'     => ['admin.shopify.export-mappings', 1, 'Export Mappings', 'shopify-export-mapping-form'],
    'import mapping'     => ['admin.shopify.import-mappings', 3, 'Import Mappings', 'shopify-import-mapping-form'],
    'collection mapping' => ['admin.shopify.collection-mappings', 1, 'Collection Mappings', 'shopify-collection-mapping-form'],
    'export settings'    => ['admin.shopify.settings', 2, 'Settings', 'shopify-export-settings-form'],
]);

it('shows one heading with the back and save buttons beside it', function (string $routeName, int $id, string $heading, string $formId) {
    $html = get(route($routeName, $id))->assertOk()->getContent();

    expect(preg_match_all('/<p class="text-xl[^"]*">\s*'.preg_quote($heading, '/').'/', $html))->toBe(0)
        ->and($html)->toContain('form="'.$formId.'"')
        ->and($html)->toContain('id="'.$formId.'"');
})->with('mapping pages');
