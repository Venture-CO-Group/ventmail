import { expect, test } from '@playwright/test';

test('a smoke oldal betölt és @venture/design tokent használ', async ({ page }) => {
    await page.goto('/');

    await expect(page.getByRole('heading', { name: 'ventMail' })).toBeVisible();
    await expect(page.getByTestId('smoke-button')).toBeVisible();

    // A design-tokenek CSS-változóként jelen vannak (nem beégetett érték).
    const hasTokens = await page.evaluate(() => {
        const styles = getComputedStyle(document.documentElement);

        return Array.from(document.styleSheets)
            .flatMap((sheet) => {
                try {
                    return Array.from(sheet.cssRules);
                } catch {
                    return [];
                }
            })
            .some((rule) => rule.cssText.includes('--vt-')) || styles.getPropertyValue('--vt-color-bg') !== '';
    });

    expect(hasTokens).toBe(true);
});
