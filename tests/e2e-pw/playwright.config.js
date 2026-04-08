// @ts-check
const path = require('path');
const { defineConfig, devices } = require('@playwright/test');

// Load env vars from tests/e2e-pw/.env for local runs
require('dotenv').config({ path: path.join(__dirname, '.env') });

module.exports = defineConfig({
  testDir: './tests',
  /* Run tests in files in parallel */
  fullyParallel: false,
  workers: 1,
  /* Fail the build on CI if you accidentally left test.only in the source code. */
  forbidOnly: !!process.env.CI,
  /* Retry on CI only */
  retries: process.env.CI ? 2 : 0,
  /* Opt out of parallel tests on CI. */
  // workers: process.env.CI ? 1 : undefined,
  /* Reporter to use. See https://playwright.dev/docs/test-reporters */
  reporter: 'html',
  /* Global Setup for Authentication */
  globalSetup: './tests/setup/global-setup.js',

  /* Shared settings for all projects */
  use: {
    /* Base URL to use in actions like `await page.goto('/')`. */
    headless: true,
    baseURL: process.env.E2E_BASE_URL || 'http://localhost:8000',

    /* Load saved authentication state */
    storageState: 'storage/auth.json',

    /* Collect trace when retrying a failed test */
    trace: 'on-first-retry',
    video: 'on',  // Enables video recording for each test
    screenshot: 'on', // Optional: to capture screenshots on test failure
  },

 /* Configure projects for major browsers */
projects: [
  {
    name: 'chromium',
    use: {
      ...devices['Desktop Chrome'],
      headless: true, // run in headless mode (set false if you want UI)
    },
  },
  // {
  //   name: 'Mobile Chrome',
  //   use: { ...devices['Pixel 5'] },
  // },
],
});
