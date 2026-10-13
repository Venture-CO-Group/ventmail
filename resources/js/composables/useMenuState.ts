import { ref } from 'vue';

/**
 * EGYSZERRE EGY nyitott legördülő a héjban (nav-csoportok, workspace-váltó, nyelv,
 * avatar-menü): egy új megnyitás bezárja az előzőt. Modul-szintű állapot, mert a
 * legördülők egymás testvérei, nem szülő-gyerek.
 */
export const activeMenuId = ref<string | null>(null);
export const activeMenuPinned = ref(false);
