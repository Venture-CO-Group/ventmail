import { baseCompile } from '@intlify/message-compiler';
import { describe, expect, it } from 'vitest';

/*
 | MINDEN FELIRAT LEFORDÍTHATÓ vue-i18n ÜZENET.
 |
 | A vue-i18n a `@`-ot (linked message), a `{`/`}`-t (interpoláció) és a `|`-t (többes szám)
 | szintaxisként olvassa. Egy literál e-mail-cím (`a***@example.com`) ezért fordítási hibát
 | dob — és a hiba NEM a kulcsnál, hanem az egész oldal renderelésénél jelentkezik: a
 | `/webhooks` képernyő üresen maradt (PR #67, CI). Literál `@` csak `{'@'}` alakban.
 */
const bundles = import.meta.glob<Record<string, unknown>>('../../../resources/js/locales/*.json', { eager: true, import: 'default' });

function* strings(node: unknown, path: string): Generator<[string, string]> {
    if (typeof node === 'string') {
        yield [path, node];
    } else if (node && typeof node === 'object') {
        for (const [key, value] of Object.entries(node)) {
            yield* strings(value, path ? `${path}.${key}` : key);
        }
    }
}

describe('locale-üzenetek szintaxisa', () => {
    it.each(Object.entries(bundles).map(([file, messages]) => [file.replace(/^.*\//, ''), messages] as const))(
        '%s: minden üzenet hiba nélkül fordul',
        (_file, messages) => {
            const broken: string[] = [];

            for (const [key, message] of strings(messages, '')) {
                const errors: string[] = [];
                baseCompile(message, { onError: (error) => errors.push(error.message) });
                if (errors.length > 0) broken.push(`${key}: ${errors.join('; ')}`);
            }

            expect(broken).toEqual([]);
        },
    );
});
