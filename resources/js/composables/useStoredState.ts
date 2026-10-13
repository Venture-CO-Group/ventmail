/**
 * localStorage-olvasás/írás, MINDIG try/catch-csel.
 *
 * A tároló nem megbízható: privát módban, tiltott sütik mellett vagy betelt kvótánál a
 * `getItem`/`setItem` kivételt dob — a navigáció ettől nem eshet szét. Hiba esetén a
 * kezdőértéket kapjuk, az írás pedig csendben elmarad (az állapot kényelmi, nem adat).
 */
export function readStoredList(key: string): string[] {
    try {
        const raw = window.localStorage.getItem(key);

        if (raw === null) {
            return [];
        }

        const parsed: unknown = JSON.parse(raw);

        return Array.isArray(parsed) ? parsed.filter((item): item is string => typeof item === 'string') : [];
    } catch {
        return [];
    }
}

export function writeStoredList(key: string, value: string[]): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Kényelmi állapot: az írás elmaradása nem hiba.
    }
}
