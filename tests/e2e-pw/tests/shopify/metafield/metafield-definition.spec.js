import { test, expect } from '@playwright/test';

test.use({ storageState: 'storage/auth.json' });
// test.use({ launchOptions: { slowMo: 500 } });

const uniqueSuffix = Date.now().toString();
const definitionName = `E2E Metafield ${uniqueSuffix}`;
const namespaceKey = `custom.e2e${uniqueSuffix}`;

test.describe('Shopify Metafield definitions Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('admin/shopify/metafields');
  });

  test('Verify Shopify Metafield definitions page title is visible', async ({ page }) => {
    await expect(page.locator('p:text("Metafield definitions")')).toBeVisible();
  });

  test('Click on Add definition button', async ({ page }) => {
    await page.locator('button.primary-button:has-text("Add definition")').click();
  });

  test('Verify search functionality is present', async ({ page }) => {
    const searchBox = page.getByRole('textbox', { name: 'Search' }).first();
    await expect(searchBox).toBeVisible();

    await searchBox.fill(`__no_match__${Date.now()}`);
    await searchBox.press('Enter');

    await expect(page.locator('p:text("No Records Available.")')).toBeVisible({ timeout: 10000 });
  });

  test('Click on Filter button', async ({ page }) => {
    await page.getByText('Filter', { exact: true }).click();
  });

  test('Verify pagination dropdown', async ({ page }) => {
    await page.locator('button:has-text("10")').click();
    await page.getByText('50', { exact: true }).click();
    await expect(page.locator('button:has-text("50")')).toBeVisible();
  });

  test('Verify table headers', async ({ page }) => {
    const headerRow = page.locator('#app').locator('div').filter({
      has: page.getByText('Used For', { exact: true }),
      hasText: 'Unopim Attribute',
    }).first();
    const headers = ['Used For', 'Unopim Attribute', 'Definition name'];
    for (const header of headers) {
      await expect(headerRow.getByText(header, { exact: true })).toBeVisible({ timeout: 10000 });
    }
  });
});

test.describe.serial('Shopify Create Metafield Definition Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('admin/shopify/metafields');
  });

  test('Checked Metafield Definition used for Product form and validation', async ({ page }) => {
    await page.getByRole('button', { name: /Add definition/i }).click();

    // Hide debugbar if present (can block clicks in Firefox).
    await page.evaluate(() => {
      const bar = document.querySelector('.phpdebugbar');
      if (bar) bar.style.display = 'none';
    });

    await page.getByRole('button', { name: /Save/i }).click({ force: true });

    await expect(page.getByText(/The UnoPim Attribute field is required/i)).toBeVisible();
    await expect(page.getByText(/The Type field is required/i)).toBeVisible();
    await expect(page.getByText(/The Definition Name field is required/i)).toBeVisible();
    await expect(page.getByText(/The Namespace and key field is required/i)).toBeVisible();

    // Used For = Products if available (fallback Variants).
    await page.locator('#ownerType .multiselect__select').click({ force: true });
    const usedForProducts = page
      .locator('#ownerType .multiselect__element span')
      .filter({ hasText: /^Products$/ })
      .first();
    const usedForVariants = page
      .locator('#ownerType .multiselect__element span')
      .filter({ hasText: /^Variants$/ })
      .first();
    if (await usedForProducts.count()) {
      await usedForProducts.click({ force: true });
    } else if (await usedForVariants.count()) {
      await usedForVariants.click({ force: true });
    }

    await page.locator('input[name="code"]').locator('..').locator('.multiselect__placeholder').click();
    await page.getByText('Name', { exact: true }).click();

    await page.locator('input[name="type"]').locator('..').locator('.multiselect__placeholder').click();
    // await page.getByText('Single line text', { exact: true }).click();
    await page.locator('#type').getByText('Single line text').click();

    await page.locator('input[name="attribute"]').fill(definitionName);
    await page.locator('input[name="name_space_key"]').fill(namespaceKey);

    await page.evaluate(() => {
      const bar = document.querySelector('.phpdebugbar');
      if (bar) bar.style.display = 'none';
    });
    await page.getByRole('button', { name: /Save/i }).click({ force: true });

    const duplicateDefinitionError = page.getByText(/Definition already created in Product Definition/i);

    try {
      await expect(page.getByText(/Create Metafield Definition successfully/i).first()).toBeVisible({ timeout: 15000 });
    } catch {
      await expect(duplicateDefinitionError).toBeVisible({ timeout: 5000 });
    }
  });

  test('Metafield Definition edit required validation', async ({ page }) => {
    await expect(page.getByTitle('Edit').first()).toBeVisible();
    await page.getByTitle('Edit').first().click();

    await expect(page).toHaveURL(/\/admin\/shopify\/metafields\/edit\/\d+$/);

    const multiselect = page.locator('label', { hasText: 'Used For' }).locator('..').locator('.multiselect');
    const hasDisabledClass = await multiselect.evaluate((el) => el.classList.contains('multiselect--disabled'));
    expect(hasDisabledClass).toBe(true);

    const input = page.locator('input[name="code"]');
    await expect(input).toHaveAttribute('readonly', '');

    const contentTypeName = page.locator('input[name="ContentTypeName"]');
    await expect(contentTypeName).toBeDisabled();

    const definitionNameInput = page.locator('input[name="attribute"]');
    await expect(definitionNameInput).toBeVisible();
    await definitionNameInput.fill('New Definition Name');

    const nsKeyInput = page.locator('input[name="name_space_key"]');
    await expect(nsKeyInput).toBeDisabled();
    await expect(nsKeyInput).not.toHaveValue('');

    const descriptionInput = page.locator('input[name="description"]');
    if (await descriptionInput.count()) {
      await descriptionInput.fill('Test metafield description');
    }

    const toggle = async (id, expectedChecked) => {
      const checkbox = page.locator(`input#${id}`).first();
      if (!(await checkbox.count())) return;
      const label = page.locator(`label[for="${id}"]`).first();
      if (expectedChecked) {
        await expect(checkbox).toBeChecked();
      } else {
        await expect(checkbox).not.toBeChecked();
      }
      await label.click();
      if (expectedChecked) {
        await expect(checkbox).not.toBeChecked();
      } else {
        await expect(checkbox).toBeChecked();
      }
      await label.click();
      if (expectedChecked) {
        await expect(checkbox).toBeChecked();
      } else {
        await expect(checkbox).not.toBeChecked();
      }
    };

    await toggle('pin', true);
    await toggle('adminFilterable', false);
    await toggle('smartCollectionCondition', false);
    await toggle('storefronts', true);

    await page.getByRole('button', { name: /Save/i }).click({ force: true });
    await expect(page.locator('#app').getByText(/Updated successfully/i)).toBeVisible({ timeout: 15000 });
  });

  test('Delete the Metafield Definition', async ({ page }) => {
    await expect(page.getByTitle('Delete').first()).toBeVisible();
    await page.getByTitle('Delete').first().click();
    await expect(page.getByText('Are you sure you want to')).toBeVisible();
    await page.getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByText(/Deleted successfully/i)).toBeVisible({ timeout: 15000 });
  });
});
