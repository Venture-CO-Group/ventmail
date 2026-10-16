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

## A Figmához igazítva (2026-10-16, tulajdonosi döntés)

1. **Sidebar:** a Beállítások csoport = Számlázás, Bejövő útvonalak, Címellenőrzés (API), Tagok, Branding;
   „hamarosan" jelvény nincs; a profil a fiókmenüben.
2. **Segments (03):** master–detail — balra a szegmenslista, jobbra a kiválasztott (alapból az első)
   szegmens szerkesztője (név + becsült méret + Mentés a kártyafejben, ÉS/VAGY csoportok).
3. **Audience (02):** fejléc: Címkék · CSV importálása · Kontakt felvétele; alatta állapot-chipek
   (Mind / Feliratkozott / Megerősítésre vár / Leiratkozott / Visszapattant, számokkal) és kereső,
   majd a kontakt-tábla. Az áttekintés, a listák, az egyéni mezők és a szegmensek a tábla alatt.
   Az állapot-szűrő a szerveren fut, és a „minden találat" tömeges címkézés is követi.
4. **Overview (01):** a kampánylista kikerült (a Figmán nincs; a `/campaigns` képernyőn van).
5. **Templates (05):** „Galéria" gomb (az indulósablonokra szűr).
6. **Campaigns (06):** állapot-chipek és kereső a kártya fölött, a lista cím nélküli flush kártyában.
7. **Analytics (09):** fejléc: Export (PDF-riport) + időszak-választó; alatta a kampány-szűrő és a
   kampány KPI-sora (kézbesítve, megnyitás, kattintás, bounce, leiratkozás — arányokkal).
8. **Domains (10):** a DNS-rekord tábla a közös `VtDataTable`.
9. **Branding (26):** az élő előnézet a kártyafejben, Heading/H3.

## Ami még eltér (döntés vagy új funkció kell)

- **Értesítés-csengő:** a ventMailnek nincs értesítés-funkciója — a csengő nem kerül ki.
- **Audience „Export":** kontakt-export (PII-CSV) nincs a ventMailben — új funkció, GDPR-döntés kell.
- **Overview kvóta „of N / mo":** a havi keret nem jön át a ventPay-ből.
- **Deliverability (17):** a Figma KPI-sora (reputáció, beérkezés) — a ventMail ezeket az adatokat még nem méri.
