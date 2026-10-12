/** Az import-varázsló szerver-oldali állapota (lásd `ContactImportController::state`). */
export interface ImportProfile {
    format: 'csv' | 'xlsx';
    delimiter: string;
    encoding: string;
    sheets: string[];
    sheet: string | null;
    hasHeader: boolean;
    columns: string[];
    width: number;
    rows: number;
}

export interface ImportSuggestion {
    index: number;
    header: string;
    sample: string;
    target: string | null;
    confidence: number;
}

export interface ImportSample {
    line: number;
    email: string;
    name: string;
    code: string;
    detail: Record<string, unknown>;
}

export interface ImportState {
    uuid: string;
    status: 'draft' | 'previewing' | 'previewed' | 'queued' | 'processing' | 'completed' | 'failed';
    phase: string | null;
    sourceType: 'manual' | 'paste' | 'csv' | 'xlsx';
    fileName: string | null;
    fileSize: number | null;
    profile: ImportProfile;
    mapping: Record<string, string>;
    suggestions: ImportSuggestion[];
    settings: {
        listUuid: string | null;
        tags: string[];
        updateMode: 'skip' | 'fill' | 'overwrite';
        legalBasis: 'verified_consent' | 'legitimate_interest' | 'unknown' | null;
        consentSource: string | null;
        consentDate: string | null;
        rolePolicy: 'skip' | 'import';
        disposablePolicy: 'skip' | 'import';
        resultingStatus: 'subscribed' | 'pending' | null;
    };
    counts: { total: number; processed: number; new: number; updated: number; skipped: number; errors: number };
    stats: {
        invalid: number;
        duplicate: number;
        existing: number;
        suppressed: number;
        role: number;
        disposable: number;
        memoryMb: number;
        elapsed: number;
        chunks: number;
        samples: ImportSample[];
    };
    chunkSize: number;
    hasReport: boolean;
    failureReason: string | null;
    startedAt: string | null;
    completedAt: string | null;
    startedBy: string | null;
}

export interface ImportList {
    uuid: string;
    name: string;
    contacts: number;
}

export interface ImportField {
    key: string;
    label: string;
    type: 'text' | 'number' | 'date' | 'boolean';
}

export type ImportErrors = Record<string, string>;
