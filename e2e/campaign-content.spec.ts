import { expect, test, type Page } from '@playwright/test';
import { seedStudio } from './support/studio';

/*
 * UI-POLÍROZÁS · 6. pont — A KAMPÁNY TARTALMA HELYBEN, ÜRES SABLONKÖNYVTÁRRAL.
 *
 * Saját, friss workspace (a dev-tenant könyvtára nem üres): lista + hitelesített domain seed,
 * de EGYETLEN sablon nélkül. A kampány végigvihető: a tartalom-választó alapból az „Új template
 * készítése" ágon áll, a tartalom a (kampány-kontextusú) szerkesztőben készül, és „Mentés
 * sablonként" önálló könyvtári sablont is ad. A küldési mód zárt (SENDING_LIVE / marketing ki):
 * a PreSendGate a küldéskor okkóddal dönt — a spec azt méri, nem azt, hogy „kiment".
 *
 * A `finally` mindig visszavált a Dev workspace-re (a suite többi része azt várja).
 */
const run = `cc${Date.now().toString(36)}`;
const workspace = `E2E üres könyvtár ${run}`;

async function save(page: Page, section: string): Promise<void> {
    await page.getByTestId(`section-save-${section}`).click();
    const status = page.getByTestId('campaign-status');

    await expect(status).toHaveAttribute('data-saving', '0', { timeout: 15_000 });
    await expect(status).toHaveAttribute('data-dirty', '0');
}

async function backToDevWorkspace(page: Page): Promise<void> {
    await page.goto('/dashboard');

    if ((await page.getByTestId('workspace-current').textContent())?.trim() !== 'Dev workspace') {
        await page.getByTestId('workspace-switch').click();
        await page.getByTestId('workspace-list').locator('button', { hasText: 'Dev workspace' }).click();
        await expect(page.getByTestId('workspace-current')).toHaveText('Dev workspace');
    }
}

test('üres sablonkönyvtárral a kampány végigvihető: új tartalom helyben, mentés sablonként', async ({ page }) => {
    test.setTimeout(120_000);

    await page.goto('/dashboard');
    await page.getByTestId('workspace-switch').click();
    await page.getByTestId('workspace-new').click();
    await page.getByTestId('workspace-new-name').fill(workspace);
    await page.getByTestId('workspace-new-submit').click();

    try {
        await expect(page.getByTestId('workspace-current')).toHaveText(workspace);

        const seed = await seedStudio(page, run, { templates: false });

        // A könyvtár ÜRES.
        await page.goto('/templates');
        await expect(page.getByTestId('templates-empty')).toBeVisible();

        // Új kampány.
        await page.goto('/campaigns');
        await page.getByTestId('campaign-new').click();
        await page.getByTestId('campaign-name').fill(`E2E helyben ${run}`);
        await page.getByTestId('campaign-submit').click();
        await expect(page).toHaveURL(/\/campaigns\/[0-9a-f-]{36}$/);
        const campaignUrl = new URL(page.url()).pathname;

        // 1–3. címzettek, feladó, tárgy.
        await page.getByTestId('target-list-search').fill(seed.list.name);
        await page.getByTestId(`target-list-${seed.list.uuid}`).click();
        await save(page, 'recipients');

        await page.getByTestId('section-toggle-sender').click();
        await page.getByTestId('edit-from-name').fill('E2E Helyben');
        await page.getByTestId('edit-from').fill('hirlevel');
        await page.getByTestId('edit-from-domain').locator('select').selectOption('pelda.hu');
        await save(page, 'sender');

        await page.getByTestId('section-toggle-subject').click();
        await page.getByTestId('edit-subject').fill('Helyben készült levél');
        await save(page, 'subject');

        // 4. TARTALOM — az „Új template készítése" az alapértelmezett, előre kiválasztva.
        await page.getByTestId('section-toggle-content').click();
        await expect(page.getByTestId('content-mode-new').locator('input')).toBeChecked();
        await expect(page.getByTestId('content-mode-existing').locator('input')).not.toBeChecked();

        // A másik ág: a könyvtár üres — ezt meg is mondja.
        await page.getByTestId('content-mode-existing').click();
        await expect(page.getByTestId('content-library-empty')).toBeVisible();
        await page.getByTestId('content-mode-new').click();

        await page.getByTestId('content-create').click();
        await expect(page).toHaveURL(new RegExp(`${campaignUrl}/content$`));
        await expect(page.getByTestId('editor-campaign-context')).toContainText(`E2E helyben ${run}`);

        // Az új tartalom láblécre végződik (marketing: kötelező leiratkozó-blokk) — a helyőrző
        // címet valódira cseréljük a láblécre kattintva nyíló beállítás-panelen.
        const footer = page.locator('[data-testid="editor-canvas"] [data-type="footer"]');

        await expect(footer).toHaveCount(1);
        await footer.click();
        await expect(page.getByTestId('editor-props')).toBeVisible();
        await page.getByTestId('prop-address').fill('Nádor utca 1., 1051 Budapest');
        await expect(page.getByTestId('save-status')).toHaveAttribute('data-state', 'saved', { timeout: 15_000 });

        // Mentés ÖNÁLLÓ sablonként (a könyvtárba).
        await page.getByTestId('editor-save-menu').click();
        await page.getByTestId('menu-save-template').click();
        await page.getByTestId('name-modal-input').fill(`E2E könyvtári ${run}`);
        await page.getByTestId('name-modal-submit').click();
        await expect(page.getByTestId('editor-notice')).toBeVisible();

        // Mentés és kilépés → vissza a KAMPÁNYBA.
        await page.getByTestId('editor-save-exit').click();
        await expect(page).toHaveURL(new RegExp(`${campaignUrl}$`));
        await expect(page.getByTestId('section-content')).toHaveAttribute('data-done', 'true');
        await expect(page.getByTestId('check-compliance')).toHaveAttribute('data-ok', 'true');

        // 5. ütemezés → minden kész, küldhető.
        // (Újratöltés után az első hiányos szakasz — az ütemezés — magától nyitva van.)
        const schedule = page.getByTestId('section-toggle-schedule');

        if ((await schedule.getAttribute('aria-expanded')) !== 'true') await schedule.click();
        await page.getByTestId('schedule-now').click();
        await save(page, 'schedule');
        await expect(page.getByTestId('campaign-send')).toBeEnabled();

        await page.getByTestId('campaign-send').click();
        await expect(page.getByTestId('campaign-outcomes')).toBeVisible();
        expect(await page.getByTestId('outcome-skipped').first().getAttribute('data-reason')).toMatch(/^(CHANNEL_DISABLED|SENDING_RESTRICTED)$/);
        await expect(page.getByTestId('outcome-sent')).toHaveCount(0);

        // A könyvtárban CSAK az önállóként mentett sablon van (a kampány saját tartalma nem).
        await page.goto('/templates');
        await expect(page.getByTestId('library-own').locator('[data-testid^="template-card-"]')).toHaveCount(1);
        await expect(page.getByTestId('library-own')).toContainText(`E2E könyvtári ${run}`);
    } finally {
        await backToDevWorkspace(page);
    }
});
