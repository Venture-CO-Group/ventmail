import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { type DateInput, formatDate, formatDateTime, formatNumber, formatPercent, formatTime, type FormatPrefs, type NumberOptions, resolvePrefs } from '../lib/format';

/**
 * A felhasználó formázási beállításai (D11) a megosztott Inertia-propsból (`format`), és a
 * közös formázók ezekkel előkötve. EZ az egyetlen út szám/dátum kiírására a felületen —
 * a nyers `toLocaleString()` a böngésző nyelvét követné, nem a profilt.
 */
export function useFormat() {
    const page = usePage();
    const prefs = computed<FormatPrefs>(() => resolvePrefs((page.props as { format?: Partial<Record<keyof FormatPrefs, unknown>> }).format));

    return {
        prefs,
        number: (value: number | null | undefined, options?: NumberOptions) => formatNumber(value, prefs.value, options),
        percent: (value: number | null | undefined, digits?: number) => formatPercent(value, prefs.value, digits),
        date: (value: DateInput) => formatDate(value, prefs.value),
        time: (value: DateInput) => formatTime(value, prefs.value),
        dateTime: (value: DateInput) => formatDateTime(value, prefs.value),
    };
}
