import { computed, ref, type ComputedRef } from 'vue';

/**
 * EGYSZERRE EGY nyitott legördülő a héjban (nav-csoportok, workspace-váltó, nyelv, avatar-menü,
 * export-menük): egy új megnyitás bezárja az előzőt. Modul-szintű állapot, mert a legördülők
 * egymás testvérei, nem szülő-gyerek.
 *
 * A legördülő maga a @venture/ui `VtDropdown` (0.3.0, D315): VEZÉRELT módban kapja a nyitott
 * állapotot (`:open` + `@update:open`), a hover / kattintás-rögzítés / Esc / ütközés-figyelés
 * a könyvtáré — itt csak a „egyszerre egy" koordináció él.
 */
export const activeMenuId = ref<string | null>(null);

/** Egy legördülő nyitása / zárása a koordinációban — az EGYETLEN író (a `useShellMenu` is ezt hívja). */
export function setMenuOpen(id: string, value: boolean): void {
    if (value) {
        activeMenuId.value = id;
    } else if (activeMenuId.value === id) {
        activeMenuId.value = null;
    }
}

export function useShellMenu(id: string): { open: ComputedRef<boolean>; setOpen: (value: boolean) => void } {
    return { open: computed(() => activeMenuId.value === id), setOpen: (value: boolean) => setMenuOpen(id, value) };
}

/** Navigáció után minden legördülő zár (a következő oldalon egy sem maradhat nyitva). */
export function closeAllMenus(): void {
    activeMenuId.value = null;
}
