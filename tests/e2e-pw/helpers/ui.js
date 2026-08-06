export async function dismissPromos(page) {
    await page.evaluate(() => {
        document.querySelectorAll('.phpdebugbar').forEach((el) => { el.style.display = 'none'; });
        document.querySelectorAll('button').forEach((btn) => {
            if ((btn.textContent || '').trim() === "Don't show again") btn.click();
        });
    });
}
