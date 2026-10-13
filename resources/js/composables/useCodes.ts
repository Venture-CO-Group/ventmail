import { useI18n } from 'vue-i18n';

/**
 * GÉPI KÓD → FELÜLETI CÍMKE (D292). Egy enum-érték (kapu, elutasítási ok, eseménytípus, csatorna,
 * állapot…) a felhasználó nyelvén jelenik meg; ismeretlen kódnál BIZTONSÁGOS visszaesés: maga a kód
 * (nem üres cella és nem kivétel). A teljességet a `CodeLabelsTest` (Pest) őrzi.
 */
const PREFIX = {
    event: 'codes.event',
    gate: 'codes.gate',
    reason: 'codes.reason',
    dkim: 'codes.dkim',
    bounce: 'codes.bounce',
    channel: 'codes.channel',
    suppressionReason: 'codes.suppressionReason',
    scope: 'codes.scope',
    source: 'codes.source',
    status: 'codes.status',
    poolType: 'codes.poolType',
    fieldType: 'codes.fieldType',
    trigger: 'journeyCanvas.nodes',
    contactStatus: 'segments.value.status',
    consent: 'segments.value.consent',
} as const;

export type CodeGroup = keyof typeof PREFIX;

export function useCodes() {
    const { t, te } = useI18n({ useScope: 'global' });

    return (group: CodeGroup, value: string | null | undefined, empty = '—'): string => {
        if (value === null || value === undefined || value === '') return empty;

        const key = `${PREFIX[group]}.${value}`;

        return te(key) ? t(key) : value;
    };
}
