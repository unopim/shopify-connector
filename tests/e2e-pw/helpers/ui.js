import { expect } from '@playwright/test';

/**
 * Clear what floats over the admin: the debug bar, the docked AI panel and any
 * promo that asks to be dismissed. They sit outside the app root and can cover
 * the controls a test is about to click.
 */
export async function dismissPromos(page) {
    await page.evaluate(() => {
        document.querySelectorAll('.phpdebugbar, #agenting-pim-panel').forEach((element) => { element.remove(); });

        document.querySelectorAll('button').forEach((btn) => {
            if ((btn.textContent || '').trim() === "Don't show again") btn.click();
        });
    });
}

export async function openDataGridFilters(page) {
    await dismissPromos(page);

    // Vue replaces the v-drawer custom element with its rendered trigger, so
    // locating an ancestor <v-drawer> only works in the unmounted template.
    //
    // The grid's own hook comes first: once the toolbar is condensed - under
    // 1000px of measured width, which is where a 1280px run lands - the word
    // "Filter" is rendered screen-reader only and cannot be clicked. The label
    // stays as the fallback for cores that predate the hook. Both are scoped to
    // the app root, away from the debug bar's own "Filter" controls.
    const hook = page.locator('#app [data-grid-filter]').first();
    const label = page.locator('#app').getByText('Filter', { exact: true }).first();

    await hook.or(label).first().waitFor();

    const toggle = await hook.count() ? hook : label;

    await toggle.scrollIntoViewIfNeeded();
    await toggle.click();

    await expect(page.locator('[data-drawer-panel]')).toBeVisible();
}

/**
 * Navigate to an admin screen. The admin is a Vue app whose images and fonts
 * keep loading long after it is usable, so waiting for `load` charges every
 * navigation the slowest asset on the page; the assertions that follow already
 * wait for what they need.
 */
export async function gotoAdmin(page, path) {
    await page.goto(path);

    // The controls are rendered by Vue, so the admin bundle having run is what
    // makes the page ready.
    await page.waitForFunction(() => Boolean(window.app));
}
