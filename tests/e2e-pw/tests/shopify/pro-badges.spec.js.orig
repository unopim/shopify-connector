import { test, expect } from '@playwright/test';
import { dismissPromos } from '../../helpers/ui.js';

test.use({ storageState: 'storage/auth.json' });

/** Filters the Shopify Pro package contributes to the product export. */
const PRO_FILTERS = [
    'locales',
    'attributes',
    'attribute_families',
    'categories',
    'completeness',
    'categories',
    'time_condition',
    'with_media',
    'with_associations',
];

/** Filters the core Shopify connector declares itself. */
const CORE_FILTERS = ['credentials', 'channels', 'currencies', 'sku', 'status'];

const selectExportType = async (page, label) => {
    await page.locator('#export-type').click();

    await page.locator('.multiselect__element', { hasText: label }).first().click();
};

test.describe('Shopify Pro badges', () => {
    for (const [screen, url] of [
        ['export', 'admin/shopify/export-mapping/1'],
        ['import', 'admin/shopify/import-mapping/3'],
    ]) {
        test(`badges the association mapping section on the ${screen} mapping screen`, async ({ page }) => {
            await page.goto(url);
            await dismissPromos(page);

            const heading = page.locator('p', { hasText: 'Association Mapping' }).first();

            await expect(heading).toBeVisible();
            await expect(heading.locator('.shopify-pro-badge')).toHaveText('Pro');
        });
    }

    test('badges the pro filters on the shopify product export', async ({ page }) => {
        await page.goto('admin/data-transfer/exports/create');
        await dismissPromos(page);

        await selectExportType(page, 'Shopify Product');

        await expect(page.locator('label[for="with_media"]')).toBeVisible();

        for (const name of PRO_FILTERS) {
            await expect(
                page.locator(`label[data-shopify-pro-filter="${name}"] .shopify-pro-badge`),
                `${name} should carry a Pro badge`
            ).toHaveCount(1);
        }
    });

    test('leaves the core shopify filters unbadged', async ({ page }) => {
        await page.goto('admin/data-transfer/exports/create');
        await dismissPromos(page);

        await selectExportType(page, 'Shopify Product');

        await expect(page.locator('label[for="with_media"]')).toBeVisible();

        for (const name of CORE_FILTERS) {
            await expect(
                page.locator(`label[for="${name}"] .shopify-pro-badge`),
                `${name} is a core filter and must stay unbadged`
            ).toHaveCount(0);
        }
    });
    test('suffixes the pro metafield types on the definition screen', async ({ page }) => {
        await page.goto('admin/shopify/metafields');
        await dismissPromos(page);

        const source = await page.content();

        expect(source).toContain('Money (Pro)');
        expect(source).toContain('Temperature (Pro)');
    });
});
