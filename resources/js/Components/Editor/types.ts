/*
 | A SZERKESZTŐ TÍPUSAI — a block-tree kontraktus (a szerver `BlockSchema`-ja) TS-alakja.
 |
 | A mezők az `attrs` alatt élnek; a gyökérben csak `section`, a szakaszban `column`, az
 | oszlopban tartalmi blokkok. A szerver a mentéskor UGYANEZT validálja — a kliens alak csak
 | kényelem, nem igazság.
 */

export type Attrs = Record<string, unknown>;

export interface Block {
    type: string;
    attrs?: Attrs;
    children?: Block[];
}

export interface TemplateStyles {
    font?: string;
    text_color?: string;
    heading_color?: string;
    link_color?: string;
    background?: string;
    content_background?: string;
    button_background?: string;
    button_color?: string;
    button_radius?: number;
    font_size?: number;
    width?: number;
}

export interface PaletteBlock {
    id: string;
    type: string;
    icon: string;
    defaults: Attrs;
}

export interface LayoutDef {
    key: string;
    columns: string[];
    padding?: string;
}

export interface PatternDef {
    key: string;
    section: Block;
}

export interface MergeTagDef {
    key: string;
    group: 'contact' | 'links' | 'workspace';
    link: boolean;
    fallback: string;
}

export interface EditorCatalog {
    blocks: PaletteBlock[];
    layouts: LayoutDef[];
    patterns: PatternDef[];
    mergeTags: MergeTagDef[];
    conditionFields: string[];
    segments: Array<{ uuid: string; name: string }>;
    fonts: string[];
    fontStacks: Record<string, string>;
    styleDefaults: Required<TemplateStyles>;
    styleRanges: Record<string, [number, number]>;
    swatches: string[];
    socialNetworks: string[];
    shareNetworks: string[];
    maxSurveyAnswers: number;
    assetBase: string;
    gmailClipBytes: number;
}

export interface SavedBlockDef {
    uuid: string;
    name: string;
    kind: 'section' | 'block';
    block: Block;
}

export interface AssetDef {
    uuid: string;
    url: string;
    name: string;
    width: number;
    height: number;
    bytes: number;
}

export interface TemplateData {
    uuid: string;
    name: string;
    subject: string;
    preheader: string | null;
    marketing: boolean;
    blocks: Block[];
    styles: TemplateStyles | null;
    updatedAt: string | null;
}

/** Egy tartalmi blokk helye: szakasz → oszlop → blokk. */
export interface BlockPath {
    s: number;
    c: number;
    b: number;
}

export type Selection = { kind: 'section'; s: number } | { kind: 'block'; s: number; c: number; b: number } | null;

/** A húzott elem leírása (natív HTML5 DnD `dataTransfer`). */
export type DragPayload =
    | { source: 'palette'; id: string }
    | { source: 'layout'; key: string }
    | { source: 'pattern'; key: string }
    | { source: 'saved'; uuid: string }
    | { source: 'canvas-block'; path: BlockPath }
    | { source: 'canvas-section'; s: number };

/** A beszúrási jelző helye. */
export type DropTarget =
    | { kind: 'block'; s: number; c: number; index: number }
    | { kind: 'section'; index: number }
    | null;

export interface OptimizeReport {
    links: { items: Array<{ url: string; path: string; issues: string[] }>; total: number; broken: number; missingUtm: number };
    images: { items: Array<{ url: string; path: string; missingAlt: boolean; bytes: number | null }>; total: number; missingAlt: number; largestBytes: number | null; heavy: number };
    weight: { bytes: number; limit: number; clipped: boolean };
    spam: { score: number; warns: boolean; threshold: number; findings: Array<{ rule: string; points: number; advice: string }> };
    compliance: { required: boolean; missing: string[] };
}

export type SaveState = 'saved' | 'dirty' | 'saving' | 'error';

export const DRAG_MIME = 'application/x-vm-editor';
