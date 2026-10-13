/* v1.1 · 8. modul — az /analytics szekció-propok alakja (AnalyticsSections). */

export interface TopLink {
    url: string;
    key: string;
    clicks: number;
    unique: number;
    share: number;
}

export interface CampaignSection {
    available: boolean;
    campaign?: { uuid: string; name: string; status: string; startedAt: string | null };
    summary?: Record<string, number | string>;
    variants?: Array<Record<string, number | string | boolean | null>>;
    winner?: string | null;
    timeline?: { bucket: 'hour' | 'day'; points: Array<{ at: string; opens: number; clicks: number }> };
    devices?: { hasData: boolean; kind: string; devices: Array<{ key: string; total: number }>; clients: Array<{ key: string; total: number }> };
    links?: TopLink[];
}

export interface AudienceSection {
    available: boolean;
    growth?: Array<{ date: string; new: number; unsubscribed: number; net: number }>;
    totals?: Record<string, number>;
    churn?: { unsubscribed: number; new: number; subscribedAtStart: number; rate: number };
    engagement?: Array<{ key: string; count: number }>;
    activeWindowDays?: number;
}

export interface JourneySection {
    available: boolean;
    journey?: { uuid: string; name: string; status: string };
    nodesAvailable?: boolean;
    rows?: Array<{ id: string; type: string; label: string; depth: number; branch: string | null; entered: number; movedOn: number; conversion: number }>;
    goal?: { reached: number; entered: number; rate: number };
}

export interface PlatformSection {
    available: boolean;
    series?: Array<{ day: string; sent: number; deliveredRate: number; bounceRate: number; complaintRate: number }>;
    sum?: Record<string, number>;
    bounces?: { total: number; classes: Record<string, number>; unclassified: number };
    complaintThreshold?: number;
}

export interface ReportRow {
    uuid: string;
    kind: 'campaign' | 'period';
    campaignUuid: string | null;
    from: string | null;
    to: string | null;
    sections: string[];
    status: 'queued' | 'rendering' | 'ready' | 'failed' | 'expired';
    failureCode: string | null;
    size: number | null;
    pages: number | null;
    createdAt: string | null;
    expiresAt: string | null;
    downloadUrl: string | null;
}
