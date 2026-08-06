import { test, expect } from '@playwright/test';
test.use({ storageState: 'storage/auth.json' });
// test.use({ launchOptions: { slowMo: 500 } }); // Slow down actions by 1 second
// Reuse login session

const credentialRowByShopUrl = (page, shopUrl) =>
  page
    .locator('#app')
    .locator('div')
    .filter({
      hasText: (() => {
        try {
          return new URL(shopUrl).hostname;
        } catch {
          return shopUrl;
        }
      })(),
    })
    .filter({ has: page.locator('[title="Edit"]') })
    .first();

const filterCredentialsByShopUrl = async (page, shopUrl) => {
  const searchBox = page.getByRole('textbox', { name: 'Search' }).first();
  await expect(searchBox).toBeVisible();

  let query = shopUrl;
  try {
    query = new URL(shopUrl).hostname;
  } catch {
    query = shopUrl;
  }

  await searchBox.fill(query);
  await searchBox.press('Enter');
};

test.describe('Shopify Credentials Page', () => {
  test.beforeEach(async ({ page }) => {
    // Navigate to the Shopify Credentials Page
    await page.goto('admin/shopify/credentials');
  });

  test('Verify Shopify Credentials page title is visible', async ({ page }) => {
    await expect(page.locator('p:text("Shopify Credentials")')).toBeVisible();
  });

  test('Click on Create Credential button', async ({ page }) => {
    await page.locator('button.primary-button:has-text("Create Credential")').click();
    // await expect(page.locator('.fixed.inset-0.bg-gray-500')).toBeVisible(); // Verify modal opened
  });

  test('Verify search functionality is present', async ({ page }) => {

    const searchBox = page.locator('input[name="search"]:visible').first();
    await expect(searchBox).toBeVisible();

    // Fill the search input field
    await searchBox.fill(`__no_match__${Date.now()}`);
    await searchBox.press('Enter');

    // Verify results are filtered (UI does not always show "0 Results")
    await expect(page.getByText(/No records match the filters you applied\.|No Records Available\./i).first()).toBeVisible({ timeout: 10000 });
  });

  test('Click on Filter button', async ({ page }) => {
    await page.getByText('Filter', { exact: true }).click();
    // await expect(page.locator('.z-10.hidden')).not.toHaveClass(/hidden/);
  });

  test('Verify pagination dropdown', async ({ page }) => {
    await page.locator('button:has-text("10")').click();
    // await page.locator('li:has-text("50")').click();
    await page.getByText('50', { exact: true }).click();
    await expect(page.locator('button:has-text("50")')).toBeVisible();
  });

  test('Verify table headers', async ({ page }) => {
    const headerRow = page.locator('#app').locator('div').filter({
      has: page.getByText('Shopify URL', { exact: true }),
      hasText: 'API Version',
    }).first();
    const headers = ['Shopify URL', 'API Version', 'Enable', 'Actions'];

    for (const header of headers) {
      await expect(headerRow.getByText(header, { exact: true })).toBeVisible({ timeout: 10000 });
    }
  });

  test('Verify No Records Available message', async ({ page }) => {
    const searchBox = page.locator('input[name="search"]:visible').first();
    await expect(searchBox).toBeVisible();
    await searchBox.fill(`__no_match__${Date.now()}`);
    await searchBox.press('Enter');
    await expect(page.getByText(/No records match the filters you applied\.|No Records Available\./i).first()).toBeVisible({ timeout: 10000 });
  });
});

test.describe.serial('Shopify Create credential Page', () => {
  test.beforeEach(async ({ page }) => {
    // Navigate to the Shopify Credentials Page
    await page.goto('admin/shopify/credentials');
  });

  test('Checked credential form and validation', async ({ page }) => {
    await page.getByRole('button', { name: 'Create Credential' }).click();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('The Shopify URL field is required')).toBeVisible();
    await expect(page.getByText('The Client ID field is required')).toBeVisible();
    await expect(page.getByText('The Client Secret field is required')).toBeVisible();

    // Invalid URL should trigger URL validation
    await page.getByRole('textbox', { name: 'http://demo.myshopify.com' }).fill('not-a-url');
    await page.getByRole('textbox', { name: 'Client ID' }).fill('dummy-client-id');
    await page.getByRole('textbox', { name: 'Client Secret' }).fill('dummy-client-secret');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText(/invalid url|valid url/i)).toBeVisible();

  });

});
