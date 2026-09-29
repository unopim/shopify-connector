import { expect } from '@playwright/test';
import { gotoAdmin } from './ui.js';

/**
 * Skip a Pro-only test only when the rendered Shopify UI explicitly shows that
 * Pro is unavailable. The mapping screen renders its page-level upgrade notice
 * only in Core; Shopify Pro removes that notice when it boots.
 */
export async function skipUnlessShopifyPro(page, testInfo) {
    await gotoAdmin(page, '/admin/shopify/export-mapping/1');
    await expect(page.getByRole('heading', { name: 'Export Mappings' })).toBeVisible();

    testInfo.skip(
        await page.locator('.shopify-pro-notice--page').count() > 0,
        'Shopify Pro is not available in this environment.',
    );
}
