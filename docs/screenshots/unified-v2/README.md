# Unified UI v2 — ventMail-képernyők a Figma mellett

Figma: „Venture Apps — Unified UI v2" (`71xORCWJErMdPRuKEqCznp`), **14 · ventMail** oldal, 26 frame
(1440 széles). `figma/` = a Figma-render (MCP `get_screenshot`), `app/` = a ventMail ugyanazon
képernyője 1440 × 900-on, demó-seeddel (`e2e/unified-v2-screenshots.spec.ts`,
`SCREENSHOTS=1 npx playwright test e2e/unified-v2-screenshots.spec.ts`).

A Figma minta-adattal készült (angol felület, „Venture" workspace); az app a helyi demó-adatot és a
dev-felhasználó nyelvét (hu) mutatja — a szám- és szövegkülönbség ebből jön, nem eltérés.

## Ami minden képernyőn a közös könyvtárból jön (1–5. szabály)

- **Keret:** `VtAppShell` + `VtSidebar` (248) + `VtTopbar` (60) + glow-line `app/mail`; felső navigáció nincs.
- **Oldalfej:** `VtPageHeader` — overline (a sidebar-csoport) → Display/M cím → egysoros leírás
  (`page.<id>.lead`, a Figma-szöveg, 7 nyelven) → jobbra igazított akciók.
- **Számok:** `VtKpiCard` (Overview, Audience, Sending statistics, Billing, kampányriport).
- **Kártyák:** `VtCard` fejléccel (cím + akció / jelvény), a táblák `VtDataTable`-ben (flush, ahol a Figma is).
- **Bannerek:** `VtBanner`; **üres állapotok:** `VtEmptyState`; **szűrő-chipek:** `VtChip`.
- **Kiemelő szín:** egyetlen accent; az `app/mail` csak az AppIconon és a glow-line-on.
- **Grafikon- és nyomatszínek:** `--vt-chart-*` / `--vt-print-*` (design 2.1.0).

## Párosítás

| # | Figma frame | Útvonal |
|---|---|---|
| 01 | Mail · Overview | `/dashboard` |
| 02 | Mail · Audience | `/audience` |
| 03 | Mail · Segments | `/audience/segments` |
| 04 | Mail · Forms | `/forms` |
| 05 | Mail · Templates | `/templates` |
| 06 | Mail · Campaigns | `/campaigns` |
| 07 | Mail · Campaign composer | `/campaigns/{uuid}` |
| 08 | Mail · Journeys | `/journeys` |
| 09 | Mail · Analytics | `/analytics` |
| 10 | Mail · Domains | `/domains` |
| 11 | Mail · API keys | `/api-keys` |
| 12 | Mail · SMTP credentials | `/smtp-credentials` |
| 13 | Mail · Suppression list | `/suppressions` |
| 14 | Mail · Webhooks | `/webhooks` |
| 15 | Mail · Stored templates | `/stored-templates` |
| 16 | Mail · IP pools | `/ip-pools` |
| 17 | Mail · Deliverability | `/deliverability` |
| 18 | Mail · Log | `/logs` |
| 19 | Mail · Tag statistics | `/logs/statistics` |
| 20 | Mail · Sending statistics | `/statistics` |
| 21 | Mail · Developer docs | `/developers` |
| 22 | Mail · Billing | `/billing` |
| 23 | Mail · Inbound routes | `/inbound` |
| 24 | Mail · Validation API | `/validation` |
| 25 | Mail · Members | `/members` |
| 26 | Mail · Branding | `/settings/branding` |

## Tudatos eltérések (review-ra)

Ezek nem vizuális hibák, hanem a ventMail meglévő folyamatai vagy termékdöntései — átírásuk külön döntés:

1. **Csoportok a sidebarban:** a Billing, az Inbound routes és a Validation API az appban a
   **Platform** csoportban van (a Figma a Settingsbe teszi); az overline és a breadcrumb a sidebart követi.
2. **Segments (03):** a Figma master–detail (lista + szerkesztő egy képernyőn); az appban a
   szerkesztő külön oldal (`/audience/segments/{id}`).
3. **Audience (02):** az app a lista fölött közönség-áttekintést mutat (KPI-k, növekedés, források,
   engagement); a Figma csak a szűrt listát.
4. **Overview (01):** a Figma két kártyája (legutóbbi üzenetek + kézbesíthetőség) mellett az app
   a legutóbbi kampányokat is mutatja (teljes sor, alattuk). A kvóta-kártyán nincs „of N / mo" alcím:
   a havi keret nem jön át a ventPay-ből.
5. **Templates (05):** nincs „Gallery" gomb (az indulósablonok a chip-szűrő „Indulósablonok" fülén).
6. **Campaigns (06):** a státusz-szűrő `VtSegmented` (8 állapot), nem chip-sor; a lista egy kártyában, címmel.
7. **Analytics (09):** az időszak- és kampányszűrő az oldalfej alatti sorban (a Figma csak „Export" + időszak);
   a PDF-riport gomb az akció. A `VtPageHeader` tördelési javítása a venture-ui #15-ben (2.1.1, kiadatlan).
8. **Értesítés-csengő:** a Figma felső sávjában van, a ventMailnek nincs értesítés-funkciója — nem kerül ki.
9. **Domains (10):** a DNS-rekord tábla még saját `<table>` (másolás-gombokkal) — `VtDataTable`-re
   költöztetése a következő kör (hosszú értékek: DKIM-kulcs).
10. **Branding (26):** az élő előnézet kártyacíme még a régi, nagy stílus.
