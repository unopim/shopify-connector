import { test, expect } from '@playwright/test';

test.use({ storageState: 'storage/auth.json' }); // Reuse login session
// test.use({ launchOptions: { slowMo: 1000 } }); // Slow down actions by 1 second

const baseUrl = process.env.E2E_BASE_URL || 'http://localhost:8000';

test.describe('UnoPim Shopify setting tab Navigation', () => {
    const toggleById = (page, inputId) => {
        const input = page.locator(`input#${inputId}`);
        const label = page.locator(`label:has(input#${inputId})`).first();
        const container = label.count().then(c => (c ? label : input.locator('..')));
        const clickTarget = async () => {
            const resolvedContainer = await container;
            const track = resolvedContainer.locator('.rounded-full').first();
            if ((await track.count()) > 0) return track;
            return resolvedContainer;
        };

        return { input, clickTarget };
    };

    const setToggle = async ({ input, clickTarget }, value) => {
        const current = await input.isChecked();
        if (current === value) return;

        await (await clickTarget()).click({ force: true });

        if (value) {
            await expect(input).toBeChecked();
        } else {
            await expect(input).not.toBeChecked();
        }
    };

    test.beforeEach(async ({ page }) => {
        // Navigate to the Shopify Credentials Page
        await page.goto('admin/shopify/credentials');
        await page.getByRole('link', { name: 'Settings', exact: true }).click()
    });
    test('Verify page loads correctly', async ({ page }) => {
        await expect(page).toHaveURL(new URL('/admin/shopify/export/settings/2', baseUrl).toString());
    });

    test('Toggle Named Tags Export setting', async ({ page }) => {
        const toggle = toggleById(page, 'enable_named_tags_attribute');
        await setToggle(toggle, true);
        await setToggle(toggle, false);
    });

    test('Toggle Attribute Name in Tags Export setting', async ({ page }) => {
        const toggle = toggleById(page, 'enable_tags_attribute');
        await setToggle(toggle, true);
        await setToggle(toggle, false);
    });

    test('Enable toggle and select option from dependent dropdown', async ({ page }) => {

        // Toggle: "Do you want to pull through the attribute name as well in tags?"
        const toggle = toggleById(page, 'enable_tags_attribute');

        // Enable the toggle if not already enabled
        await setToggle(toggle, true);

        // Wait for the dropdown to be visible
        const dropdown = page.locator('#tagSeprator .multiselect__select');
        await expect(dropdown).toBeVisible();

        // Click the dropdown to reveal options
        await dropdown.click();

        // Wait for options to load (the list is populated dynamically)
        const options = page.locator('#tagSeprator .multiselect__content li');
        await expect(options.first()).toBeVisible();

        console.log('Available options:');
        const optionsCount = await options.count();
        for (let i = 0; i < optionsCount; i++) {
            const text = await options.nth(i).textContent();
            console.log(`- ${text}`);
        }

        // Select the desired option, e.g., "( ) Space"
        const optionToSelect = page.locator('#tagSeprator .multiselect__option', { hasText: '( ) Space' });
        await expect(optionToSelect).toBeVisible();
        await optionToSelect.click();

        // Verify the selected option
        await expect(page.locator('#tagSeprator .multiselect__single')).toHaveText('( ) Space');
        const selected = await page.locator('#tagSeprator .multiselect__single').textContent();
        console.log(`Selected option: ${selected}`);
    });

    test('Verify dropdown options and select one for both fields', async ({ page }) => {
        // Function to verify and select dropdown options
        const verifyAndSelectDropdown = async (dropdownId, optionText) => {
            const dropdown = page.locator(`${dropdownId} .multiselect__select`);
            await dropdown.click();

            const options = page.locator(`${dropdownId} .multiselect__content li`);
            const optionsCount = await options.count();
            expect(optionsCount).toBeGreaterThan(0);

            console.log(`Options for ${dropdownId}:`);
            for (let i = 0; i < optionsCount; i++) {
                const text = await options.nth(i).textContent();
                console.log(`- ${text}`);
            }

            const optionToSelect = page.locator(`${dropdownId} .multiselect__option`, { hasText: optionText });
            await optionToSelect.click();

            const selected = await page.locator(`${dropdownId} .multiselect__single`).textContent();
            expect(selected).toBe(optionText);
            console.log(`Selected option for ${dropdownId}: ${selected}`);
        };
    });


    test('Toggle value of option name in other setting', async ({ page }) => {
        const toggle = toggleById(page, 'option_name_label');
        await setToggle(toggle, true);
        await setToggle(toggle, false);
    });

    test('Back button should navigate to credentials page', async ({ page }) => {
        await page.getByRole('link', { name: 'Back' }).click();
        await expect(page).toHaveURL(/credentials/);
    });

});
