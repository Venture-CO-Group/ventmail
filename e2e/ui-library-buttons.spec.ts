import { expect, test, type Locator } from '@playwright/test';

/*
 * UI-POLÍROZÁS · 1. pont — @venture/ui 0.3.0 VtButton: az ikon és a szöveg függőleges közepe
 * legfeljebb 1px-re tér el (a böngésző mérése). A ventMailben lokális igazítási trükk nincs.
 */

/** Az ikon és a szöveg függőleges középpontjának eltérése egy gombban (px). */
async function iconTextOffset(button: Locator): Promise<number> {
    return button.evaluate((node) => {
        const svg = node.querySelector('svg');
        const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT, { acceptNode: (text) => (text.textContent?.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT) });
        const text = walker.nextNode();

        if (!svg || !text) return Number.POSITIVE_INFINITY;

        const range = document.createRange();
        range.selectNodeContents(text);

        const icon = svg.getBoundingClientRect();
        const label = range.getBoundingClientRect();

        return Math.abs(icon.top + icon.height / 2 - (label.top + label.height / 2));
    });
}

test.describe('1. ikonos gombok', () => {
    for (const [path, testid] of [
        ['/dashboard', 'dashboard-import'],
        ['/dashboard', 'dashboard-new-campaign'],
        ['/campaigns', 'campaign-new'],
        ['/templates', 'template-new'],
    ] as const) {
        test(`${path} · ${testid}: az ikon és a szöveg középpontja ≤ 1px-re`, async ({ page }) => {
            await page.goto(path);
            const button = page.getByTestId(testid);

            await expect(button).toBeVisible();
            expect(await iconTextOffset(button)).toBeLessThanOrEqual(1);
        });
    }
});

