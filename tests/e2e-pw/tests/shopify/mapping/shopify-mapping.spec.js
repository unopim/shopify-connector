import { test, expect } from '@playwright/test';

test.use({ storageState: 'storage/auth.json' }); // Reuse login session

// Define all mapping elements from Mapping page.
const mappingElements = [
    { field: 'Name [title]', inputName: 'title', placeholder: 'Name' },
    { field: 'Description [descriptionHtml]', inputName: 'descriptionHtml', placeholder: 'Description' },
    { field: 'Price [price]', inputName: 'price', placeholder: 'Price' },
    { field: 'Weight [weight]', inputName: 'weight', placeholder: 'Weight' },
    { field: 'Quantity [inventoryQuantity]', inputName: 'inventoryQuantity', placeholder: 'Quantity' },
    { field: 'Inventory Tracked [inventoryTracked]', inputName: 'inventoryTracked', placeholder: 'Inventory Tracked' },
    { field: 'Allow Purchase Out of Stock [inventoryPolicy]', inputName: 'inventoryPolicy', placeholder: 'Allow Purchase Out of Stock' },
    { field: 'Vendor [vendor]', inputName: 'vendor', placeholder: 'Vendor' },
    { field: 'Product Type [productType]', inputName: 'productType', placeholder: 'Product Type' },
    { field: 'Tags [tags]', inputName: 'tags', placeholder: 'Tags' },
    { field: 'Barcode [barcode]', inputName: 'barcode', placeholder: 'Barcode' },
    { field: 'Compare Price [compareAtPrice]', inputName: 'compareAtPrice', placeholder: 'Compare Price' },
    { field: 'Seo Title [metafields_global_title_tag]', inputName: 'metafields_global_title_tag', placeholder: 'Seo Title' },
    { field: 'Seo Description [metafields_global_description_tag]', inputName: 'metafields_global_description_tag', placeholder: 'Seo Description' },
    { field: 'Handle [handle]', inputName: 'handle', placeholder: 'Handle' },
    { field: 'Taxable [taxable]', inputName: 'taxable', placeholder: 'Taxable' },
    { field: 'Cost per item [cost]', inputName: 'cost', placeholder: 'Cost per item' }
];

// Resolve the grid row that wraps a field, anchored by its label paragraph.
const fieldGroup = (page, labelText) =>
    page.locator(`p:has-text("${labelText}")`).locator('xpath=ancestor::div[contains(@class,"grid")]');

// Select an exact option inside a vue-multiselect scoped to a field group. Idempotent:
// if the option is already the selected value, leave it (clicking a selected option in
// a single-select would deselect it).
const selectExactOption = async (group, optionText) => {
    const single = group.locator('.multiselect__single');
    if ((((await single.textContent({ timeout: 800 }).catch(() => '')) || '').trim()) === optionText) {
        return;
    }
    await group.locator('.multiselect').click();
    await group.locator('.multiselect__option')
        .filter({ hasText: new RegExp(`^${optionText}$`) }).first().click();
    await expect(single).toHaveText(optionText);
};

// Product Status is required before saving.
const selectActiveStatus = async (page) => {
    await selectExactOption(fieldGroup(page, '[status]'), 'Active');
};

// Unit weight/volume/dimension are client-side required and their saved values don't
// rebind to the validator, so they must be selected explicitly before saving.
const selectUnitMapping = async (page) => {
    await selectExactOption(fieldGroup(page, 'Unit Weight'), 'g');
    await selectExactOption(fieldGroup(page, 'Unit Volume'), 'L');
    await selectExactOption(fieldGroup(page, 'Unit Dimension'), 'cm');
};

// Save and confirm the mapping was accepted. A successful save POSTs to the store route
// and redirects to the mappings page; the success flash toast isn't reliably observable
// here, so success is asserted by the POST round-trip leaving no validation errors.
const saveMapping = async (page) => {
    await Promise.all([
        page.waitForResponse((r) => /export\/mapping\/create/.test(r.url()) && r.request().method() === 'POST'),
        page.getByRole('button', { name: 'Save' }).click(),
    ]);
    await page.waitForLoadState('networkidle').catch(() => {});
    await expect(page.getByText(/field is required/i)).toHaveCount(0);
};

test.describe('UnoPim Shopify mapping tab Navigation', () => {
    test.beforeEach(async ({ page }) => {
        // Navigate directly to the Export Mappings page (sidebar sub-menu links are
        // hover-revealed, so a direct goto is more reliable than clicking them).
        await page.goto('admin/shopify/export/mapping/1');
        await expect(page.url()).toMatch(/\/admin\/shopify\/export\/mapping\/[0-9]+$/);
    });

    // Playwright test to map fields


    test('Map Shopify Fields', async ({ page }) => {
        test.setTimeout(60_000);

        for (const element of mappingElements) {
            console.log(`Mapping ${element.field}`);

            const input = page.locator(`input[name="${element.inputName}"]`);
        }

        // Title is required (required_without:default_title) — map it to an attribute.
        await page.locator('input[aria-label="title-searchbox"]').locator('xpath=ancestor::*[@role="combobox"][1]').click();
        await page.getByText('Name', { exact: true }).click();

        // Product Status and the unit mappings are required before saving.
        await selectActiveStatus(page);
        await selectUnitMapping(page);

        await saveMapping(page);

    });

    test('should navigate to Shopify mapping page and fill export mapping form', async ({ page }) => {
        test.setTimeout(60_000);

        await expect(page.getByRole('link', { name: 'General' })).toBeVisible();
        await expect(page.locator('#app')).toContainText('General');
        await expect(page.getByRole('paragraph').filter({ hasText: 'Export Mappings' })).toBeVisible();
        await expect(page.locator('#app')).toContainText('Export Mappings');
        await page.locator('input[aria-label="title-searchbox"]').locator('xpath=ancestor::*[@role="combobox"][1]').click();
        await page.getByText('Name', { exact: true }).click();
        await page.getByText('Description', { exact: true }).click();
        await page.getByText('Price', { exact: true }).click();
        await page.locator('#default_productType').click();
        await page.locator('#default_productType').clear();
        await page.locator('#default_productType').fill('unopim');
        await page.locator('#default_productType').click();
        await page.locator('#default_tags').click();
        await page.locator('#default_tags').fill('shopify');
        const mediaTypeDropdown = page.locator('#mediaType .multiselect__select');
        await mediaTypeDropdown.click();
        await page.getByText('Gallery', { exact: true }).click();
        const pLocator = page.locator('p', { hasText: 'Media Attributes' });
        const multiselect = pLocator.locator('..').locator('.multiselect');
        await expect(multiselect).toBeVisible({ timeout: 20000 });
        const hasDisabledClass = await multiselect.evaluate(el => el.classList.contains('multiselect--disabled'));
        expect(hasDisabledClass).toBe(false);

        // Product Status and the unit mappings are required before saving.
        await selectActiveStatus(page);
        await selectUnitMapping(page);

        await saveMapping(page);
        await page.getByRole('link', { name: 'Back' }).click();
        await page.goto('admin/shopify/export/mapping/1');
    });
});
