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
    { field: 'Cost per item [cost]', inputName: 'cost', placeholder: 'Cost per item' },
    { field: 'Attribute to be used as image', inputName: 'images', placeholder: 'Select option' }
];

const dropdownMappings = [
    { field: 'Name [title]', inputName: 'title', desiredOption: 'Name' },
    // { field: 'Cost [cast]', inputName: 'cost', desiredOption: 'Cost' },
    // { field: 'Description [descriptionHtml]', inputName: 'descriptionHtml', desiredOption: 'Description' }
];
test.describe('UnoPim Shopify mapping tab Navigation', () => {
    test.beforeEach(async ({ page }) => {
        // Navigate to the Shopify Credentials Page
        await page.goto('admin/shopify/credentials');
        await page.getByRole('link', { name: 'Export Mappings' }).click();
        await expect(page.url()).toMatch(/\/admin\/shopify\/export\/mapping\/[0-9]+$/);
    });

    // Playwright test to map fields


    test('Map Shopify Fields', async ({ page }) => {
        for (const element of mappingElements) {
            console.log(`Mapping ${element.field}`);

            const input = page.locator(`input[name="${element.inputName}"]`);
        }

        const saveButton = page.getByRole('button', { name: 'Save' });
        await saveButton.click();

        await expect(page.locator('span:text("Export Mapping saved successfully")')).toBeVisible({ timeout: 10000 });

    });

    test('should navigate to shopify mapping page', async ({ page }) => {
        // Go directly to the admin dashboard (User is already logged in)
        await expect(page.getByRole('link', { name: 'General' })).toBeVisible();
        await expect(page.locator('#app')).toContainText('General');
        await expect(page.getByRole('paragraph').filter({ hasText: 'Export Mappings' })).toBeVisible();
        await expect(page.locator('#app')).toContainText('Export Mappings');
        await page.getByRole('button', { name: 'Save' }).click();
        await expect(page.getByText('Export Mapping saved successfully Close')).toBeVisible();
        await expect(page.locator('#app')).toContainText('Export Mapping saved successfully');
        await page.locator('div').filter({ hasText: /^Name$/ }).click();
        await page.getByText('Name', { exact: true }).click();
        await page.getByText('Description', { exact: true }).click();
        await page.locator('div').filter({ hasText: /^Price$/ }).click();
        await page.locator('#default_productType').click();
        await page.locator('#default_productType').clear();
        await page.locator('#default_productType').fill('unopim');
        await page.locator('#default_productType').click();
        await page.locator('#default_tags').click();
        await page.locator('#default_tags').clear();
        await page.locator('#default_tags').fill('shopify');
        await page.locator('div').filter({ hasText: /^Select option$/ }).first().click();
        await page.locator('div:nth-child(4) > .flex > div:nth-child(2)').click();
        await page.getByRole('combobox').filter({ hasText: /^No elements found\. Consider changing the search query\.List is empty\.$/ }).getByPlaceholder('Select option').click();
        await page.getByText('Select option').nth(2).click();
        await page.getByText('Attributes to be used as').click();
        await page.locator('div').filter({ hasText: /^Select option$/ }).nth(2).click();
        await page.getByText('Select option').nth(1).click();
        await page.getByRole('button', { name: 'Save' }).click();
        await expect(page.getByText('Export Mapping saved')).toBeVisible();
        await page.getByRole('button', { name: 'Save' }).click();
        await expect(page.locator('#app')).toContainText('Export Mapping saved successfully');
        await page.getByRole('link', { name: 'Back' }).click();
        await page.getByRole('link', { name: 'Export Mappings' }).click();

    });
});

