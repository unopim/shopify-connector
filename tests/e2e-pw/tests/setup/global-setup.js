import { chromium } from '@playwright/test';
import fs from 'fs';

async function globalSetup() {
    const browser = await chromium.launch(); // Use chromium, Playwright handles cross-browser storage
    const context = await browser.newContext();
    const page = await context.newPage();

    const baseUrl = process.env.E2E_BASE_URL || 'http://localhost:8000';
    const adminEmail = process.env.E2E_ADMIN_EMAIL;
    const adminPassword = process.env.E2E_ADMIN_PASSWORD;

    if (!adminEmail || !adminPassword) {
        throw new Error(
            'Set E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD in tests/e2e-pw/.env to run global setup.',
        );
    }

    // Perform login
    await page.goto(baseUrl);
    await page.fill('input[name="email"]', adminEmail);
    await page.fill('input[name="password"]', adminPassword);
    await page.click('.primary-button');

    // Wait for successful login (Adjust selector based on actual dashboard page)
    await page.waitForURL(new URL('/admin/dashboard', baseUrl).toString());

    // Save authentication state
    await context.storageState({ path: 'storage/auth.json' });

    // Ensure the storage file exists
    if (!fs.existsSync('storage/auth.json')) {
        throw new Error('Auth storage file was not created.');
    }

    // await browser.close();
}

export default globalSetup;
