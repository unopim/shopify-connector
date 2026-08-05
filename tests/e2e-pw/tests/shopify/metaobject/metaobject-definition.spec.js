import { test, expect } from '@playwright/test';
import { dismissPromos } from '../../../helpers/ui.js';

test.use({ storageState: 'storage/auth.json' });

test.describe('Shopify Metaobject definitions Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('admin/shopify/metaobject');
    await dismissPromos(page);
  });

  test('Verify Metaobject definitions page title is visible', async ({ page }) => {
    await expect(page.locator('p:text("Metaobject Definitions")').first()).toBeVisible();
  });

  test('Verify Create Metaobject button is visible', async ({ page }) => {
    await expect(page.locator('a.primary-button, button.primary-button').filter({ hasText: /Create Metaobject/i }).first()).toBeVisible();
  });

  test('Verify search returns no records for a random term', async ({ page }) => {
    const searchBox = page.locator('input[name="search"]:visible').first();
    await expect(searchBox).toBeVisible();

    await searchBox.fill(`__no_match__${Date.now()}`);
    await searchBox.press('Enter');

    await expect(
      page.getByText(/No records match the filters you applied\.|No Records Available\./i).first()
    ).toBeVisible({ timeout: 10000 });
  });

  test('Click on Filter button', async ({ page }) => {
    await page.getByText('Filter', { exact: true }).click();
  });

  test('Verify pagination dropdown', async ({ page }) => {
    await page.locator('button:has-text("10")').first().click();
    await page.getByText('50', { exact: true }).click();
    await expect(page.locator('button:has-text("50")').first()).toBeVisible();
  });
});

test.describe('Shopify Create Metaobject Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('admin/shopify/metaobject/create');
    await dismissPromos(page);
  });

  test('Create Metaobject page loads with Name field and Save button', async ({ page }) => {
    await expect(page.getByText('Create Metaobject', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('General', { exact: true }).first()).toBeVisible();

    const nameInput = page.locator('label:has-text("Name")').first().locator('..').locator('input[type="text"]').first();
    await expect(nameInput).toBeVisible();

    await expect(page.getByRole('button', { name: /^Save$/ }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: /Add Field/i }).first()).toBeVisible();
  });

  test('Add Field reveals field name and type inputs', async ({ page }) => {
    await page.getByRole('button', { name: /Add Field/i }).first().click();

    await expect(page.getByPlaceholder(/Field name/i).first()).toBeVisible();
    await expect(page.locator('.multiselect').first()).toBeVisible();
  });
});
