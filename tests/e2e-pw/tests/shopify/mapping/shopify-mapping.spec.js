import { test, expect } from '@playwright/test';

test.use({ storageState: 'storage/auth.json' }); // Reuse login session
// test.use({ launchOptions: { slowMo: 500 } }); // Slow down actions by 1 second


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

const metaFieldsMapping = [
    { field: 'Meta String', inputName: 'meta_fields_string', placeholder: 'Select option' },
    { field: 'Meta Integer', inputName: 'meta_fields_integer', placeholder: 'Select option' },
    { field: 'Meta JSON', inputName: 'meta_fields_json', placeholder: 'Select option' }
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
            // await expect(input).toBeVisible();

            // await input.fill(element.field);
        }

        const saveButton = page.getByRole('button', { name: 'Save' });
        await saveButton.click();

        await expect(page.getByText('Mapping saved successfully')).toBeVisible();
    });


    test('Map Shopify Meta Fields', async ({ page }) => {
        for (const meta of metaFieldsMapping) {
            console.log(`Mapping ${meta.field}`);

            const input = page.locator(`input[name="${meta.inputName}"]`);
            // await expect(input).toBeVisible();

            // await input.fill(meta.field);
        }

        const saveButton = page.getByRole('button', { name: 'Save' });
        await saveButton.click();

        await expect(page.getByText('Mapping saved successfully')).toBeVisible();
    });


    // test.only('Dynamically Map Fields with Conditional Selection', async ({ page }) => {
    //     for (const mapping of dropdownMappings) {
    //         console.log(`Checking options for ${mapping.field}`);

    //         // Click on the dropdown for the current field
    //         const dropdown = page.locator(`.multiselect__placeholder`, { hasText: mapping.field.split('[')[0].trim() });
    //         await dropdown.click();

    //         // Wait for the dropdown list to load
    //         const optionsList = page.locator('.multiselect__content[style*="display: inline-block"]');
    //         await expect(optionsList).toBeVisible();

    //         // Fetch all available options dynamically
    //         const options = await optionsList.locator('.multiselect__option span').allInnerTexts();
    //         console.log(`Options for ${mapping.field}:`, options);

    //         if (options.includes(mapping.desiredOption)) {
    //             console.log(`Selecting ${mapping.desiredOption} for ${mapping.field}`);
    //             const optionToSelect = optionsList.locator('.multiselect__option', { hasText: mapping.desiredOption });
    //             await optionToSelect.click();

    //             //   const selectedTag = page.locator('.multiselect__single');
    //             //   await expect(selectedTag).toHaveText(mapping.desiredOption);
    //             const dropdownContainer = dropdown.locator('.multiselect'); // Scope to current dropdown
    //             const selectedTag = dropdownContainer.locator('.multiselect__single');

    //             await expect(selectedTag).toHaveText(mapping.desiredOption);
    //         } else {
    //             console.log(`No matching option for ${mapping.field}. Leaving blank.`);
    //             await page.keyboard.press('Escape');
    //         }
    //     }

    //     const saveButton = page.getByRole('button', { name: 'Save' });
    //     await saveButton.click();

    //     await expect(page.getByText('Mapping saved successfully')).toBeVisible();
    // });
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
        // await page.getByText('Barcode', { exact: true }).click();
        // await page.getByRole('listbox').getByText('Product Number').click();
        await page.locator('div').filter({ hasText: /^Seo Description$/ }).click();
        await page.getByRole('listbox').getByText('Meta Description').click();
        // await page.getByText('Cost per item [cost]Cost per').click();
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

