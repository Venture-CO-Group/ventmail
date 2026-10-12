import { ref } from 'vue';
import type { DragPayload } from './types';

/*
 | A FOLYAMATBAN LÉVŐ HÚZÁS (natív HTML5 DnD, D3).
 |
 | A `dragover` alatt a böngésző a `dataTransfer` TARTALMÁT nem adja ki (csak a típusát),
 | a beszúrási jelzőhöz viszont tudni kell, MIT húznak (szakaszt vagy blokkot). A húzás
 | leírása ezért itt is él; a `dataTransfer` a böngészőn belüli átvitelért és a
 | `drop` engedélyezéséért kell.
 */
export const dragging = ref<DragPayload | null>(null);

export function isSectionPayload(payload: DragPayload | null): boolean {
    return payload !== null && ['layout', 'pattern', 'canvas-section'].includes(payload.source);
}
