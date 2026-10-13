import { expect, type Page } from '@playwright/test';

/*
 * v1.1 · 5. modul — a Studio böngésző-tesztjeinek közös seedje (`/dev/seed-studio`, dev-only).
 *
 * A BÖNGÉSZŐBŐL kérjük (a `page.request` a `__Host-` sütit http-n nem küldi vissza), és a
 * hívás előtt egy alkalmazás-oldalon kell állni (a munkamenet-süti miatt).
 */
export interface StudioSeed {
    run: string;
    emails: { confirmed: string; pending: string; none: string };
    list: { uuid: string; name: string };
    templates: { compliant: { uuid: string; name: string }; placeholder: { uuid: string; name: string } };
    preferenceUrl: string;
}

export async function seedStudio(page: Page, run: string): Promise<StudioSeed> {
    if (!page.url().startsWith('http')) await page.goto('/dashboard');

    const result = await page.evaluate(async (target) => {
        const response = await fetch(target, { credentials: 'same-origin', headers: { Accept: 'application/json' } });

        return { status: response.status, body: response.ok ? await response.json() : null };
    }, `/dev/seed-studio?run=${run}`);

    expect(result.status).toBe(200);

    return result.body as StudioSeed;
}
