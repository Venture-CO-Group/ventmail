# Analytics modul

**Sáv:** STUDIO (Mailchimp-kiváltás)

Marketing analitika Timescale aggregátumokból. UGYANARRA az eseményforrásra épül, mint a napló.

## Határok
- Minden entitás `workspace_id`-hez kötött, global scope-pal (Tenancy).
- Külső gazda csak interfész mögött; a fake teszttel fedett kontraktus.
- A modul nem épít saját identity / consent-banner / fájltár / számlázó / landing-page réteget.

## Állapot (v1.1 · 8. modul)
- **Kampány-riport** (B4): `CampaignAnalytics` (ténytábla `campaign_engagement`), `ClickMap` (`link_clicks`),
  `/v1/analytics/campaigns/{uuid}` (JSON + CSV).
- **Grafikonok** (`AnalyticsSections` — a képernyő ÉS a PDF közös forrása, 2 s keret, 300 s cache):
  kampány (nyitás/kattintás időben, eszköz/kliens, hőtérkép, top linkek), közönség (növekedés,
  lemorzsolódás, engagement), journey (csomópontonkénti konverzió), platform (kézbesíthetőség, bounce-típus).
- **Eszköz/kliens** (D229): `MailClientClassifier` + `ClientStatsRecorder` a tracking-végponton,
  `engagement_client_daily`; a nyers UA soha nem perzisztálódik.
- **Bounce-típusok** (D231): `domain_daily_stats.bounced_<osztály>` (Logs modul írja).
- **PNG-export**: kliens-oldali (`VmChart.toPng`, 2×, átlátszó / sötét).
- **PDF-riport**: `POST /analytics/reports` → `RenderAnalyticsReport` (`reports` sor) →
  `ReportDocumentBuilder` (Blade `analytics::report.document` + hálózat nélküli `public/build/report`
  csomag) → `PdfRenderer` (élesben `GotenbergPdfRenderer`, tesztben `FakePdfRenderer`) →
  `ReportArtifactStore` (privát diszk, 7 nap) → aláírt, 10 perces letöltő-link. `analytics:purge-reports`
  óránként, `analytics:render-smoke` éles ellenőrzéshez.
- **Workspace-branding** (`/settings/branding`): logó (közös `ImageInspector`), szín (`BrandColor`, WCAG),
  lábléc, cégnév. Sub-account nem örököl.
- Külső gazda: ventVault (riport + logó tár) — HUMAN-QUEUE.
