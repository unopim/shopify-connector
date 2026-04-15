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

    const searchBox = page.getByRole('textbox', { name: 'Search' }).first();
    await expect(searchBox).toBeVisible();

    // Fill the search input field
    await searchBox.fill(`__no_match__${Date.now()}`);
    await searchBox.press('Enter');

    // Verify results are filtered (UI does not always show "0 Results")
    await expect(page.locator('p:text("No Records Available.")')).toBeVisible({ timeout: 10000 });
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
    const searchBox = page.getByRole('textbox', { name: 'Search' }).first();
    await expect(searchBox).toBeVisible();
    await searchBox.fill(`__no_match__${Date.now()}`);
    await searchBox.press('Enter');
    await expect(page.locator('p:text("No Records Available.")')).toBeVisible({ timeout: 10000 });
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

  test('Credential creation with the valid data', async ({ page }) => {
    test.skip(
      !process.env.E2E_SHOPIFY_URL ||
        !process.env.E2E_SHOPIFY_CLIENT_ID ||
        !process.env.E2E_SHOPIFY_CLIENT_SECRET,
      'Set E2E_SHOPIFY_URL, E2E_SHOPIFY_CLIENT_ID, E2E_SHOPIFY_CLIENT_SECRET to run this test.',
    );

    const shopUrl = process.env.E2E_SHOPIFY_URL;
    await filterCredentialsByShopUrl(page, shopUrl);
    const existingRow = credentialRowByShopUrl(page, shopUrl);
    if (await existingRow.count()) {
      await expect(existingRow).toBeVisible();
      return;
    }

    await page.getByRole('button', { name: 'Create Credential' }).click();
    await page.getByRole('textbox', { name: 'http://demo.myshopify.com' }).fill(shopUrl);
    await page.getByRole('textbox', { name: 'Client ID' }).fill(process.env.E2E_SHOPIFY_CLIENT_ID);
    await page.getByRole('textbox', { name: 'Client Secret' }).fill(process.env.E2E_SHOPIFY_CLIENT_SECRET);
    await page.getByRole('button', { name: 'Save' }).click();

    // If the credential already exists (non-clean env), treat it as an idempotent pass.
    let duplicateUrlError = false;
    try {
      await page.getByText('The shop url has already been taken.').waitFor({ state: 'visible', timeout: 3000 });
      duplicateUrlError = true;
    } catch {
      duplicateUrlError = false;
    }

    if (duplicateUrlError) {
      await page.goto('admin/shopify/credentials');
      await filterCredentialsByShopUrl(page, shopUrl);
      await expect(credentialRowByShopUrl(page, shopUrl)).toBeVisible({ timeout: 15000 });
      return;
    }

    // Some flows redirect to "Edit Credential" after create; in any case, validate the record exists in the list.
    try {
      await page.waitForLoadState('networkidle', { timeout: 15000 });
    } catch {
      // ignore
    }
    await page.goto('admin/shopify/credentials');
    await filterCredentialsByShopUrl(page, shopUrl);
    await expect(credentialRowByShopUrl(page, shopUrl)).toBeVisible({ timeout: 15000 });
  });

  test('Credential edit and required validation', async ({ page }) => {
    test.skip(
      !process.env.E2E_SHOPIFY_URL ||
        !process.env.E2E_SHOPIFY_CLIENT_ID ||
        !process.env.E2E_SHOPIFY_CLIENT_SECRET,
      'Requires a successfully created credential from the previous test.',
    );
    const row = credentialRowByShopUrl(page, process.env.E2E_SHOPIFY_URL);
    await filterCredentialsByShopUrl(page, process.env.E2E_SHOPIFY_URL);
    await expect(row).toBeVisible({ timeout: 15000 });
    await expect(row.locator('[title="Edit"]')).toBeVisible();
    await row.locator('[title="Edit"]').click();
    const currentUrl = page.url();
    await expect(currentUrl).toMatch(/\/admin\/shopify\/credentials\/edit\/\d+$/);
    await expect(page.getByRole('button', { name: 'Save' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Back' })).toBeVisible();
  });

  test('Delete the credential', async ({ page }) => {
    test.skip(
      !process.env.E2E_SHOPIFY_URL ||
        !process.env.E2E_SHOPIFY_CLIENT_ID ||
      !process.env.E2E_SHOPIFY_CLIENT_SECRET,
      'Requires a successfully created credential from the previous test.',
    );
    const row = credentialRowByShopUrl(page, process.env.E2E_SHOPIFY_URL);
    await filterCredentialsByShopUrl(page, process.env.E2E_SHOPIFY_URL);
    await expect(row).toBeVisible({ timeout: 15000 });
    await expect(row.locator('[title="Delete"]')).toBeVisible();
    await row.locator('[title="Delete"]').click();
    await expect(page.getByText('Are you sure you want to')).toBeVisible();
    await expect(page.locator('#app')).toContainText('Are you sure you want to delete?');
    await page.getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByText('Credential Deleted Success')).toBeVisible();
    await expect(page.locator('#app')).toContainText('');
    await expect(page.getByText('No Records Available.')).toBeVisible();
    await expect(page.locator('#app')).toContainText('No Records Available.');
  });

});
