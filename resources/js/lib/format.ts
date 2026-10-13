/*
 | KÖZÖS FORMÁZÓ (v1.1 · D11 / D284) — szám, százalék, dátum és időpont a FELHASZNÁLÓ profil-
 | beállítása szerint (`date_format`, `number_format`, `timezone`), nem a böngésző locale-ja szerint.
 |
 | A nyers `toLocaleString()` a böngésző nyelvét követi: egy angol böngészőben a magyar felület
 | `10,000`-et és `10/13/2026, 8:20 PM`-et írt. A szótár a `ProfileSettings` (PHP) kanonikus
 | listája; ismeretlen értékre az ottani alapérték jár (fail-safe, nem kivétel).
 |
 | Tiszta függvények (unit-tesztelhetők); a komponensek a `useFormat()` composable-on át érik el.
 */

export const DATE_FORMATS = ['ymd', 'dmy', 'mdy', 'iso'] as const;
export const NUMBER_FORMATS = ['space_comma', 'comma_dot', 'dot_comma', 'apostrophe_dot'] as const;

export type DateFormat = (typeof DATE_FORMATS)[number];
export type NumberFormat = (typeof NUMBER_FORMATS)[number];

export interface FormatPrefs {
    dateFormat: DateFormat;
    numberFormat: NumberFormat;
    timezone: string;
}

const DASH = '—';

/** [ezres-elválasztó, tizedesjel]. A szóköz NEM törő (a szám nem törik két sorba). */
const SEPARATORS: Record<NumberFormat, [string, string]> = {
    space_comma: [' ', ','],
    comma_dot: [',', '.'],
    dot_comma: ['.', ','],
    apostrophe_dot: ['’', '.'],
};

function validTimezone(timezone: unknown): timezone is string {
    if (typeof timezone !== 'string' || timezone === '') return false;

    try {
        new Intl.DateTimeFormat('en-US', { timeZone: timezone });

        return true;
    } catch {
        return false;
    }
}

export function resolvePrefs(raw: { dateFormat?: unknown; numberFormat?: unknown; timezone?: unknown } | null | undefined): FormatPrefs {
    const dateFormat = (DATE_FORMATS as readonly unknown[]).includes(raw?.dateFormat) ? (raw?.dateFormat as DateFormat) : 'ymd';
    const numberFormat = (NUMBER_FORMATS as readonly unknown[]).includes(raw?.numberFormat) ? (raw?.numberFormat as NumberFormat) : 'space_comma';

    return { dateFormat, numberFormat, timezone: validTimezone(raw?.timezone) ? raw.timezone : 'UTC' };
}

export interface NumberOptions {
    minimumFractionDigits?: number;
    maximumFractionDigits?: number;
}

export function formatNumber(value: number | null | undefined, prefs: FormatPrefs, options: NumberOptions = {}): string {
    if (value === null || value === undefined || !Number.isFinite(value)) return DASH;

    const [group, decimal] = SEPARATORS[prefs.numberFormat];
    const maximumFractionDigits = options.maximumFractionDigits ?? (Number.isInteger(value) ? 0 : 2);
    const minimumFractionDigits = Math.min(options.minimumFractionDigits ?? 0, maximumFractionDigits);

    // A számjegyeket egy rögzített locale adja; az elválasztókat a profil.
    return new Intl.NumberFormat('en-US', { minimumFractionDigits, maximumFractionDigits })
        .formatToParts(value)
        .map((part) => (part.type === 'group' ? group : part.type === 'decimal' ? decimal : part.type === 'minusSign' ? '-' : part.value))
        .join('');
}

export function formatPercent(value: number | null | undefined, prefs: FormatPrefs, digits = 1): string {
    const formatted = formatNumber(value, prefs, { maximumFractionDigits: digits });

    return formatted === DASH ? DASH : `${formatted}%`;
}

interface Parts {
    year: string;
    month: string;
    day: string;
    hour: string;
    minute: string;
}

function parts(value: string | number | Date | null | undefined, timezone: string): Parts | null {
    if (value === null || value === undefined || value === '') return null;

    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) return null;

    const out: Record<string, string> = {};

    for (const part of new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date)) {
        out[part.type] = part.value;
    }

    return { year: out.year, month: out.month, day: out.day, hour: out.hour === '24' ? '00' : out.hour, minute: out.minute };
}

function datePart(p: Parts, format: DateFormat): string {
    switch (format) {
        case 'dmy':
            return `${p.day}/${p.month}/${p.year}`;
        case 'mdy':
            return `${p.month}/${p.day}/${p.year}`;
        case 'iso':
            return `${p.year}-${p.month}-${p.day}`;
        default:
            return `${p.year}. ${p.month}. ${p.day}.`;
    }
}

export type DateInput = string | number | Date | null | undefined;

export function formatDate(value: DateInput, prefs: FormatPrefs): string {
    const p = parts(value, prefs.timezone);

    return p === null ? DASH : datePart(p, prefs.dateFormat);
}

export function formatTime(value: DateInput, prefs: FormatPrefs): string {
    const p = parts(value, prefs.timezone);

    return p === null ? DASH : `${p.hour}:${p.minute}`;
}

export function formatDateTime(value: DateInput, prefs: FormatPrefs): string {
    const p = parts(value, prefs.timezone);

    return p === null ? DASH : `${datePart(p, prefs.dateFormat)} ${p.hour}:${p.minute}`;
}

/**
 * NAPTÁRI NAP (`YYYY-MM-DD`, pl. egy grafikon kategóriája) a profil dátumformátumában — időzóna-
 * átváltás NÉLKÜL: a nap már a felhasználó (vagy a workspace) napja, egy UTC-éjfélként értelmezett
 * dátum nyugati zónában az előző napra csúszna. Nem nap-alakú bemenetet változatlanul ad vissza.
 */
export function formatDay(value: string, prefs: FormatPrefs): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    return match === null ? value : datePart({ year: match[1], month: match[2], day: match[3], hour: '00', minute: '00' }, prefs.dateFormat);
}
