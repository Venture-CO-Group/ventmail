import { describe, expect, it } from 'vitest';
import { formatDate, formatDateTime, formatDay, formatStamp, formatNumber, formatPercent, formatTime, resolvePrefs } from '../../../resources/js/lib/format';

const hu = resolvePrefs({ dateFormat: 'ymd', numberFormat: 'space_comma', timezone: 'Europe/Budapest' });

describe('formatNumber — a profil számformátuma (D11), nem a böngésző locale-ja', () => {
    it.each([
        ['space_comma', '1\u00a0234\u00a0567,89'],
        ['comma_dot', '1,234,567.89'],
        ['dot_comma', '1.234.567,89'],
        ['apostrophe_dot', '1’234’567.89'],
    ] as const)('%s', (numberFormat, expected) => {
        const prefs = resolvePrefs({ numberFormat, dateFormat: 'ymd', timezone: 'UTC' });

        expect(formatNumber(1234567.891, prefs, { maximumFractionDigits: 2 })).toBe(expected);
    });

    it('egész számot alapból tizedesek nélkül, a negatívot előjellel ír', () => {
        expect(formatNumber(10000, hu)).toBe('10\u00a0000');
        expect(formatNumber(-1500, hu)).toBe('-1\u00a0500');
        expect(formatNumber(12.5, hu)).toBe('12,5');
    });

    it('hiányzó / nem véges értékre gondolatjelet ad, nem „NaN"-t', () => {
        expect(formatNumber(null, hu)).toBe('—');
        expect(formatNumber(undefined, hu)).toBe('—');
        expect(formatNumber(Number.NaN, hu)).toBe('—');
    });

    it('százalék: a tizedesjel is a formátumé', () => {
        expect(formatPercent(41.83, hu)).toBe('41,8%');
        expect(formatPercent(0.48, hu, 2)).toBe('0,48%');
        expect(formatPercent(null, hu)).toBe('—');
    });
});

describe('formatDate / formatDateTime — dátumformátum + időzóna', () => {
    const iso = '2026-10-12T22:30:00Z'; // Budapesten már 10-13 00:30

    it.each([
        ['ymd', '2026. 10. 13.'],
        ['dmy', '13/10/2026'],
        ['mdy', '10/13/2026'],
        ['iso', '2026-10-13'],
    ] as const)('%s', (dateFormat, expected) => {
        expect(formatDate(iso, resolvePrefs({ dateFormat, numberFormat: 'space_comma', timezone: 'Europe/Budapest' }))).toBe(expected);
    });

    it('az időpont 24 órás, a felhasználó időzónájában', () => {
        expect(formatDateTime(iso, hu)).toBe('2026. 10. 13. 00:30');
        expect(formatTime(iso, hu)).toBe('00:30');
        expect(formatDateTime(iso, resolvePrefs({ dateFormat: 'iso', numberFormat: 'comma_dot', timezone: 'UTC' }))).toBe('2026-10-12 22:30');
    });

    it('üres vagy érvénytelen bemenetre gondolatjel', () => {
        expect(formatDateTime(null, hu)).toBe('—');
        expect(formatDate('nem dátum', hu)).toBe('—');
    });
});

describe('resolvePrefs — fail-safe alapértékek', () => {
    it('ismeretlen formátum és érvénytelen időzóna helyett a kanonikus alapérték', () => {
        const prefs = resolvePrefs({ dateFormat: 'xyz', numberFormat: null, timezone: 'Mars/Olympus' });

        expect(prefs).toEqual({ dateFormat: 'ymd', numberFormat: 'space_comma', timezone: 'UTC' });
    });
});

describe('formatDay — naptári nap időzóna-csúszás nélkül', () => {
    it('a nap a profil formátumában, nyugati zónában is ugyanaz a nap', () => {
        const ny = resolvePrefs({ dateFormat: 'mdy', numberFormat: 'comma_dot', timezone: 'America/New_York' });

        expect(formatDay('2026-09-14', ny)).toBe('09/14/2026');
        expect(formatDay('2026-09-14', hu)).toBe('2026. 09. 14.');
        expect(formatDay('nem nap', hu)).toBe('nem nap');
    });
});

describe('formatStamp — grafikon-kategória (nap vagy óra) időzóna-csúszás nélkül (D297)', () => {
    it('napot és órát is a profil dátumformátumában ad, egyébként változatlan', () => {
        expect(formatStamp('2026-10-07', hu)).toBe('2026. 10. 07.');
        expect(formatStamp('2026-10-07T13:00', hu)).toBe('2026. 10. 07. 13:00');
        expect(formatStamp('2026-10-07 13:00:00', resolvePrefs({ dateFormat: 'dmy', numberFormat: 'comma_dot', timezone: 'UTC' }))).toBe('07/10/2026 13:00');
        expect(formatStamp('Q3', hu)).toBe('Q3');
    });
});
