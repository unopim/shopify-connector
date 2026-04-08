import { test, expect } from '@playwright/test';
import { login } from '../helpers/login';

const baseUrl = process.env.E2E_BASE_URL || 'http://localhost:8000';

test.use({
    browserName: 'chromium',
    storageState: 'storage/auth.json', // Load session state
});

test.describe('UnoPim Authenticated Tests', () => {
    test('should navigate to dashboard without login', async ({ page }) => {
        await page.goto('/admin/dashboard'); // Directly go to dashboard
        await expect(page).toHaveURL(new URL('/admin/dashboard', baseUrl).toString());
    });
});
