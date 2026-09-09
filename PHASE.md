# PHASE.md — ventMail (v2.0)

> A `SessionStart` hook ezt injektálja minden munkamenet elején. Packet lezárásakor frissítsd az
> „Aktív packet"-et, és pipáld a készeket.

## Cél
**Mailgun + Mailchimp egyidejű kiváltása.** Két pillér (Platform / Studio) egy közös motoron.

## Aktuális milestone: **M2 — B sáv (STUDIO)** · aktív packet: **B3** *(M0 lezárva: 2026-09-02 · M1 lezárva: 2026-09-08)*

> **Fázis-névhasználat.** A roadmap sávokban gondolkodik, a napi munka fázisokban. A kettő
> leképezése: **M1 = A sáv** (Platform, a Mailgun kiváltása) · **M2 = B sáv** (Studio, a
> Mailchimp kiváltása) · **M3 = C sáv** (zárás). Az M1 és az M2 párhuzamosítható, mert külön
> modulokat ír; ahol közös (`Events`), ott **az M1 a gazda**, az M2 csak olvas.

## Lezárt milestone: **M0 — Közös mag** *(2026-08-31 → 2026-09-02)*
**M0 globális DoD:** hitelesített domainről a FakeDriveren küldött üzenet átmegy a PreSendGate 6 kapuján
és „delivered" — **belső `SendMessage` action feature-tesztjével** bizonyítva. A publikus
`POST /v1/messages` endpoint az **A1** gazdája marad (golden rule 7).

## Branch-konvenció
`feat/<packet-id>-<név>` — pl. `feat/m0-1-tenancy-oidc`. `main`-re direkt push TILOS (hook). PR-only.

---

## M0 fázisterv — jóváhagyott döntések
1. **Külön M0-0 packet** hozza a Laravel-skeletont + infrát + CI-t (a repóban ma nincs `composer.json`).
2. **ventSSO:** a `ventmail` OIDC kliens regisztrációja emberi lépés (Tamás), az M0-1 futása előtt.
   Addig a fejlesztés a **lokális `~/Developer/venture/ventsso`** példány ellen megy — valódi OIDC, valódi JWKS.
3. **TimescaleDB** `timescale/timescaledb-ha:pg17` image **dev-ben ÉS CI-ban** — a hypertable-teszt valódi
   Timescale ellen fut.
4. **Precedens:** ventVault `config/oidc.php` + `app/Modules/` elrendezés a minta (`league/oauth2-client`
   + `firebase/php-jwt`), nem tervezünk újat.

## M0 packetek — sorrend kötelező
- [x] **M0-0 · Skeleton + infra + CI** — PR #3 (reviewer + security átment, javítva)
- [x] **M0-1 · Tenancy + OIDC + sub-account váz** — PR #4 (reviewer + security átment, javítva)
- [x] **M0-2 · Alap séma + audit** — PR #5 (reviewer + security átment, javítva)
- [x] **M0-3 · SendingDriver + PreSendGate (6 kapu) + FakeDriver** — PR #6 (reviewer + security átment, javítva)
- [x] **M0-4 · Domain-hitelesítés + wizard + suppression** — PR #11

> Mind az öt packet **mergelve** a `main`-be (#3, #8, #9, #10, #11). Mindegyik átment a
> `venture-reviewer` és — ahol a szabály kérte — a `venture-security` kettős review-ján;
> a talált blokkolók javítva. Összesen **357 teszt / 1189 assertion** zöld.

## Aktív packet
> **M1-A1 · Messages API** — branch: `feat/a1-messages-api`.

---

## Elfogadási kritériumok packetenként

**M0-0 · Skeleton + infra + CI**
1. `docker compose up -d` után mind a 7 service healthy; `php artisan test` zöld a konténerben (PHP 8.3).
2. `@venture/ui` komponenst renderelő Inertia-oldal betölt, `@venture/design` tokenek CSS-változóként
   jelen; `vue-tsc` hibátlan. A csomagok `file:../venture-*` linkkel (ventSSO/ventVault mintája).
3. A CI minden kapuja zöld egy üres PR-en (teszt · pint · vue-tsc · composer/npm audit · gitleaks).
4. Mind a 25 modul váza létezik `app/Modules/<Name>/` alatt README-vel.

**M0-1 · Tenancy + OIDC + sub-account**
5. Valódi OIDC authorization-code + PKCE; ID-token JWKS ellen ellenőrizve; ismeretlen `kid` → egyszeri
   JWKS-cache-ürítés + újrapróba (teszttel fedve).
6. `resource_access.ventmail.roles` → 6 szerep (`owner`/`admin`/`developer`/`editor`/`author`/`analyst`);
   ismeretlen szerep nem ad jogot.
7. „B" workspace usere **0 sort** lát „A" adatából — global scope, nem kézi `where`.
8. `child-1` usere nem ér el `child-2` sort; a parent összesített használatot lát, a child nyers adatait
   csak explicit jogosultsággal.
9. Policy: `developer` API-kulcs/SMTP/webhook igen, kampány NEM; `author` csak draft; `analyst` írásra 403.
10. Tenant-tagság KIZÁRÓLAG a saját `workspace_members`-ből — a ventSSO tokenből nem vezethető le.

**M0-2 · Séma + audit**
11. `migrate` zöld dev+CI; schema-assert: workspaces · workspace_parents · workspace_members · api_keys ·
    smtp_credentials · domains · sending_identities · ip_pools · ip_addresses · suppressions · messages ·
    message_events · audit_logs.
12. `message_events` valódi hypertable (`timescaledb_information.hypertables` sor) és fogad insertet.
13. `messages`: `tags[]`, `variables jsonb`, `deliverytime`, `testmode`, `ip_pool_id`, `channel`,
    `idempotency_key` **unique (workspace_id, idempotency_key)**.
14. `domains`: spf/dkim/dmarc/ptr/mta_sts/bimi/tracking_domain státuszmezők; `ip_addresses`:
    `warmup_state` + reputáció.
15. Minden admin-művelet `audit_logs` sort ír (aktor, workspace, művelet, cél, előtte/utána, IP-hash).
16. Publikus entitáson UUID; GDPR-releváns táblákon soft delete. Destruktív migráció review nélkül TILOS.

**M0-3 · SendingDriver + PreSendGate**
17. Mind a 6 kapu KÜLÖN blokkol strukturált kóddal: `DOMAIN_UNVERIFIED` · `SUPPRESSED` · `NO_CONSENT` ·
    `QUOTA_EXCEEDED` · `RATE_LIMITED` · `VALIDATION_RISK`.
18. `transactional`-t marketing-leiratkozás NEM blokkolja; `marketing`-et igen.
19. Kétszeri dispatch ugyanarra a `message.id`-re = EGY küldés.
20. `FakeDriver` determinisztikus `accepted` → `delivered` esemény a `message_events`-be; driver-csere
    kizárólag `config/sending.php` + env (`SENDING_DRIVER=fake`).
21. Minden gate-döntés (elfogadás ÉS elutasítás) auditált, a 6 kapu eredményével visszakereshető.
22. Architektúra-teszt: nincs kódút, amely a gate megkerülésével hívja a drivert.

**M0-4 · Domainek + wizard + suppression**
23. Wizard generál: SPF include, DKIM selector + kulcspár (privát kulcs titkosítva, sosem kerül válaszba),
    DMARC, tracking-domain CNAME.
24. DNS-check állapotgép mock resolverrel: `pending→verified`, `pending→failed`, `verified→failed`.
25. Verifikálatlan domainről a PreSendGate `DOMAIN_UNVERIFIED`-del elutasít.
26. Suppression add/list globális ÉS workspace szinten; `suppressions:write` scope nélkül 403.
27. DKIM-rotáció: új selector aktiválása után az előző átfedéses ideig érvényes marad.
28. A wizard KIZÁRÓLAG `@venture/ui`-ból; hiányzó komponens → **JELENTSD**, ne gyárts lokálisat.

---

## M0 Definition of Done
- [ ] Mind a 28 kritériumhoz tartozik néven nevezhető zöld teszt
- [ ] `php artisan test --parallel` zöld; Playwright zöld az M0-4 wizard-flow-ra
- [ ] aikido + nightvision tiszta; gitleaks + composer/npm audit tiszta
- [ ] coderabbit átment mind az 5 PR-en
- [ ] Nincs beégetett titok — DKIM privát kulcs és OIDC secret csak env / titkosított oszlop
- [ ] `contract-guard` 0 sértés — minden külső gazda interfész mögött, nincs saját identity/consent/
      számlázó/landing-page réteg
- [ ] A spec 3–6. és 25. fejezete teljesül; a docs-trió (spec · mockup · dev guide) szinkronban
- [ ] `main` zöld; minden packet PR-only, embertől mergelve

## M0 kockázatok
- ventSSO kliens-regisztráció csúszik → lokális ventsso ellen megy tovább, nem áll meg a hurok.
- Timescale `create_hypertable` ütközik a Laravel PK-val → külön nyers SQL migrációs lépés, a
  partícionáló oszlop a PK része.
- A 6. kapu (`VALIDATION_RISK`) az A5 előtt üres kontraktus → a küszöb + interfész ADR-ben rögzül,
  az A5 csak az implementációt cseréli.
- Hiányzó `@venture/ui` komponens → jelentés a ui-repóba, tilos lokális komponens.
- Lokális PHP 8.5 vs. spec 8.3 → a mérvadó tesztfutás a konténerben (8.3), a CI is azt használja.

## M0 becslés
M0-0: 1–2 nap · M0-1: 3–4 · M0-2: 2–3 · M0-3: 3–4 · M0-4: 3–4 → **~12–17 nap (~17–22 nightly kör)**.
M0 alatt **EGY** runner fut; az A/B sáv csak az M0-4 merge után indul, külön worktree-ben.

---

---

## M1 fázisterv — A sáv (PLATFORM, a Mailgun kiváltása)

**M1 globális DoD:** a Mailgun Messages API-ra írt integráció **kódmódosítás nélkül** átállítható
a ventMailre (bázis-URL + kulcs csere), és a „miért nem érkezett meg?" kérdésre az üzenet-trace
válaszol — a bejövő levél pedig route-motoron át kezelhető.

### M1 packetek — sorrend kötelező (A4 kivételével, ami A3 után bárhol)
- [x] **A1 · Messages API** — PR #13 (3 review-kör: reviewer + security, minden blokkoló javítva)
- [x] **A2 · SMTP relay + IP-poolok + sorok** — PR #14 (ADR 0001; 3 review-kör: reviewer + security ×2, minden blokkoló javítva)
- [x] **A3 · Napló + üzenet-trace + Events API + webhookok** — PR #16 MERGE-ELVE (3 review-kör: reviewer + security ×2, 6 blokkoló javítva)
- [x] **A4 · Inbound routing (MX)** — PR #17 MERGE-ELVE (reviewer + security, minden blokkoló javítva)
- [x] **A5 · Címellenőrzés** — PR #18 MERGE-ELVE (reviewer + security, minden blokkoló javítva)
- [ ] **A6 · Kézbesíthetőségi suite** — PR #19 (reviewer: 6 blokkoló javítva; **security fut**) *(FOLYAMATBAN)*

### A1 · Messages API — elfogadási kritériumok
1. `POST /v1/messages` strukturált küldés; **Idempotency-Key kötelező** (hiánya 400), ismételt kulcs
   ugyanazt a választ adja és NEM küld újra.
2. `POST /v1/messages.mime` nyers MIME továbbítása multipart fájlként; a fejlécek megőrizve.
3. **Batch**: egy hívás max 1000 címzettel + `recipient-variables` JSON; minden címzett a SAJÁT
   behelyettesítését kapja; 1001 címzett = 400.
4. Vezérlők: `o:deliverytime` (múltbeli = 400, >7 nap = 400), `o:testmode`, `o:tag` (max 3, a 4. = 400),
   `o:tracking[-clicks|-opens]`, `o:dkim`, `o:require-tls`, `o:sending-ip-pool`.
5. `h:X-*` fejlécek átmennek a levélbe; `v:*` változók visszajönnek az eseményben ÉS a webhookban.
6. Tárolt sablonok: `POST /v1/templates`, verziók, aktív verzió; `template=` + `vars[]` küldés;
   auto-escape (XSS-kísérlet escape-elve); hiányzó változó → fallback, nem hiba.
7. Minden küldés a **PreSendGate**-en megy át (M0-3 integráció) — az API nem nyithat kiskaput.
8. API-kulcs hitelesítés scope-okkal (`messages:send`, `templates:write`); hiányzó scope = 403.
9. OpenAPI-leírás + `@venture/sdk` generálás, **drift = 0** (contract-guard).
10. `/v1` hibaformátum egységes és stabil: kód + üzenet + `reason` (a gate strukturált kódjai).

### A2 · SMTP relay + IP-poolok + sorok — elfogadási kritériumok
11. Submission 587 (STARTTLS) és 465 (implicit TLS): hitelesített felhasználó küldhet.
12. **NYÍLT RELAY TESZT: hitelesítés nélküli küldés ELUTASÍTVA** (blocking).
13. Plaintext auth TLS nélkül elutasítva.
14. Az SMTP-n beadott levél **átmegy a PreSendGate-en** — verifikálatlan domain innen is elutasítva.
15. `X-VentMail-Tag` / `-Variables` / `-Deliverytime` / `-Testmode` fejlécek értelmezve.
16. IP-pool: pool→csatorna és pool→domain hozzárendelés; marketing IP nem használható transactionalre.
17. Warmup: napi limit betartva, túllépés sorba tesz; reputáció-romlásnál automatikus visszafogás;
    beteg IP → `quarantined`, nem kap forgalmat.
18. **Sor-prioritás**: telített campaign sor mellett a transactional ELŐBB megy ki (fake órán).
19. Deferral 4xx: exponenciális backoff; **per-fogadó-domain throttle** külön bucketben.
20. Bounce-osztályozó: 550 → hard → azonnali suppression; 452 → soft → N próba után suppression;
    `blocked`/`spam`/`mailbox_full`/`policy` külön kategória.
21. DKIM-aláírás a küldésnél: az M0-4 `signingSelector()`-át használja (rotáció alatt a régi kulcs).

### A3 · Napló + trace + Events API + webhookok — elfogadási kritériumok
22. Kereshető napló: szűrés címzettre, tagre, csatornára, domainre, állapotra, időablakra (Meili+Timescale).
23. **ÜZENET-TRACE**: egy `message_id`-re a teljes lánc — accepted → gate-döntés (mind a kapu
    eredménye) → kiválasztott IP/pool → **SMTP-párbeszéd sorai** (válaszkód + szöveg) →
    delivered/bounced okkal → opened/clicked.
24. `smtp_transactions` tábla rögzíti a párbeszédet; bounce esetén a válaszkód és a szöveg is látszik.
25. **RETENCIÓ**: a plan lejárati ideje után a nyers esemény TÖRLŐDIK, az aggregátum megmarad
    (idő-ugrás után nyers sor 0, aggregátum változatlan).
26. Tag-statisztika: per-tag delivered/open/click/bounce arány helyes.
27. `GET /v1/events`: szűrés típusra, címzettre, tagre, `v:*` változóra; **kurzoros lapozás stabil**.
28. Webhook: eseménytípusonkénti endpoint; **HMAC-SHA256 + timestamp**; hibás aláírás elutasítva;
    exponenciális retry; N hiba után **dead-letter**; DLQ-ból **replay** újraküld és sikeres esetben kivezet.
29. A napló-UI és a trace-képernyő `@venture/ui`-ból; Playwright a napló-flow-ra.

### A4 · Inbound routing (MX) — elfogadási kritériumok
30. Route-motor prioritás szerint: `match_recipient` / `match_header` / `catch_all`; az első `stop()`
    lezárja a láncot.
31. `forward(url)`: **HMAC-SHA256 aláírt** JSON POST a spec 10.3 mezőivel; retry + dead-letter.
32. `forward(email)`: továbbítás a kimenő motoron — **a PreSendGate-en át**.
33. `store(notify)`: nyers MIME Wasabira; retenció lejáratakor törlődik; API-n lekérhető.
34. **STRIPPED-TEXT**: az idézett előzmény és az aláírás levágva (magyar ÉS angol minták fixtúrával).
35. Csatolmány: presigned URL; méretlimit túllépés elutasítva; **ZIP-BOMBA elutasítva**;
    ClamAV-fertőzött csatolmány karanténba (fake scanner a teszthez).
36. A parser **SANDBOXBAN** fut időkorláttal; beágyazott MIME rekurzió-mélység limitálva;
    malformált MIME nem dönti el a workert.
37. Tenant-izoláció: a route mindig egy workspace-hez tartozik; idegen workspace levele nem route-olható.
38. Spam-score és SPF/DKIM eredmény a payloadban.

### A5 · Címellenőrzés — elfogadási kritériumok
39. Nyolc ellenőrzés: szintaxis (RFC 5322) · domain+MX · szerepcím · eldobható domain · catch-all ·
    elgépelés-javaslat (`gmial→gmail`) · kockázati pontszám · minősítés
    (`deliverable`/`risky`/`undeliverable`/`unknown`).
40. Mailbox-szonda `FakeProbe`-bal determinisztikus; **rate-limitelve** fut és **cache-el**
    (100 kérés ugyanarra a domainre → korlátozott számú valódi szonda).
41. `POST /v1/validate` szinkron válasz; bulk job: CSV feltöltés → aszinkron feldolgozás → export.
42. Integráció: a PreSendGate 6. kapuja a küszöb felett blokkol (a FakeValidator lecserélve).

### A6 · Kézbesíthetőségi suite — elfogadási kritériumok
43. Blocklist-monitor: `FakeBlocklistClient` találatra riasztást ír és értesít; tiszta állapot nem riaszt.
44. DMARC RUA: minta XML-ek parse-olása (**gzip is**), forrásonkénti aggregáció, ismeretlen forrás kiemelve.
45. Seed / inbox placement: seed-stub válaszaiból inbox/spam/promóciók arány riport.
46. Küldés előtti spam-score: szabály-alapú pontszám + tartalmi javaslatok; link-ellenőrzés jelzi a
    shortenert és a blocklistás domaint.
47. **One-click unsubscribe (RFC 8058)** fejléc jelenléte kikényszerítve marketing levélben.
48. MTA-STS policy + TLS-RPT fogadás + BIMI mezők kitöltése (az M0-2 sémára).

### M1 Definition of Done
- [ ] Mind a 48 kritériumhoz tartozik néven nevezhető zöld teszt
- [ ] `php artisan test --parallel` zöld; Playwright zöld a napló-, trace- és inbound-flow-ra
- [ ] `contract-guard`: OpenAPI ↔ `@venture/sdk` **drift = 0**; `/v1` breaking change nincs
- [ ] `venture-security` átment az A1, A2 és A4 packeten (SMTP relay + MIME-parser = kripto-szintű kockázat)
- [ ] a `FakeDriver → PostalDriver` csere továbbra is **csak config**
- [ ] a spec 8–14. fejezete teljesül; a docs-trió szinkronban
- [ ] `main` zöld; minden packet PR-only

### M1 kockázatok
- **Az SMTP submission listener PHP-ben:** a Laravel nem SMTP-szerver. Döntés: külön, kis
  felületű listener (ReactPHP/Swoole) VAGY Postal submission + ventMail-hook. A kontraktus
  (`PreSendGate`) mindkettőnél ugyanaz — a döntés az A2 ADR-jébe kerül.
- **MIME-parser támadási felület:** a sandbox (külön process, időkorlát, mélységlimit) nem opció.
- **Timescale retenció + aggregátum:** a `drop_chunks` chunk-granularitású — az aggregátumot
  continuous aggregate-ként kell megőrizni, különben a retenció a statisztikát is elvágja.
- **A `/v1` stabilitás:** ami egyszer kimegy, az szerződés. Az A1-nél minden mező-döntés végleges.

### M1 becslés
A1: 4–5 nap · A2: 5–6 · A3: 4–5 · A4: 5–6 · A5: 3–4 · A6: 3–4 → **~24–30 nap**

---

## M2 fázisterv — B sáv (STUDIO, a Mailchimp kiváltása)

**M2 globális DoD:** egy űrlap-beiratkozásból double opt-innal keletkezett kontakt kampányt és
journey-t kap, a marketing analitika pedig **ugyanazt a delivered-számot** mutatja, mint a
platform-napló — mert ugyanabból az esemény-forrásból dolgozik.

### M2 packetek — sorrend kötelező
- [x] **B1 · Közönség + consent + űrlapok + preference center** — PR #21 MERGE-ELVE (reviewer + security, minden blokkoló javítva)
- [ ] **B2 · Block editor + kampányok** — backend kész, reviewer + security ÁTMENT, minden
      blokkoló javítva mérő teszttel *(merge-re vár)*

  **B2 backlogba tett tételek** (a review SHOULD-jai, amikhez külön packet kell):
  - **Policy/authorizáció a Studio-modulokra** (M0-1 9. kritérium: `developer` nem indíthat
    kampányt, `author` csak vázlatot szerkeszt). Route ma nincs, tehát nem kihasználható — de
    a `CampaignServiceProvider` már deklarál `$apiMiddleware`/`$apiPrefix`-et route nélkül.
    A **B4 vagy C1** hozza a route-tal együtt: policy route nélkül halott konfiguráció, és
    fordítva is igaz.
  - **A/B győztes VISSZATÁPLÁLÁSA a küldésbe** (holdback). A `campaigns:announce-winners`
    ma KIHIRDET (ez a 64. kritérium hiányzó fele volt), de a győztesnek nincs hatása a még
    ki nem küldött levelekre — a jelenlegi modellben az összes címzett egyszerre
    materializálódik, tehát nincs mit visszatáplálni. A holdback (a közönség X%-a a
    teszt, a maradék a győztest kapja) az életciklust és a köteg-indítást is érinti,
    tehát önálló packet.
- [ ] **B3 · Journey motor + builder** — architect → test → backend → frontend → mockup
- [ ] **B4 · Marketing analitika** — test → backend → frontend

### B1 · Közönség + consent — elfogadási kritériumok
49. Lista / tag / group CRUD; **SegmentEngine DSL** determinisztikus (attribútum + esemény +
    validation-eredmény); a Meilisearch contact-index frissül.
50. **Double opt-in E2E**: signup → megerősítő tranzakciós levél (FakeDriver) → `confirmed` consent
    (jogalap, forrás, `ip_hash`, verzió, ts) — az M0-2 `consents` táblájára.
51. Megerősítetlen kontakt marketing csatornán **PreSendGate-blokk** (a 3. kapu valódi adatból dönt).
52. GDPR: az export tartalmazza a kontakt + consent + aktivitást; a törlés anonimizálja a PII-t,
    **DE a suppression MEGMARAD**.
53. Nyers IP nincs tárolva (csak `ip_hash`, a KeyedHash `email`/`ip` domainjével).
54. Űrlap: honeypot + time-trap kiszűri a botot; valós idejű címellenőrzés (A5, ha kész — különben fake).
55. Preference center: lista / gyakoriság / leiratkozás; a one-click leiratkozás konzisztens a
    suppressionnel (`applies_to=marketing`).
56. `FakeVentureOsSync`: kétirányú idempotens upsert `external_id`-re, last-write-wins + audit.
57. **Consent-bizonyíték append-only**: `consent_events` tábla (a security-review kérése az M0-2-ből) —
    a `consents` sor csak a materializált aktuális állapot.

### B2 · Block editor + kampányok — elfogadási kritériumok
58. A template a közös **block-tree JSON Schema** ellen validál (pozitív ÉS negatív esetek).
59. block-tree → **MJML** → reszponzív HTML + plaintext; a snapshot stabil.
60. Merge-tag auto-escape (XSS escape-elve) + fallback hiányzó változóra.
61. Marketing sablonban a **leiratkozó-blokk KÖTELEZŐ** (mentés blokkolva nélküle).
62. Kampány-életciklus átmenetek (draft → scheduled → sending → sent → paused/canceled) érvényesítve.
63. Célzás: lista + szegmens ∪ mínusz kizárás; a becsült címzettszám helyes.
64. A/B (`campaign_variants`): győztes-kritérium és automata kihirdetés.
65. Időzóna-tudatos ütemezés; Horizon batch + Reverb progress.
66. A kampány a **`campaign` sorba** megy (legalacsonyabb prioritás) — a transactional megelőzi (A2).
67. Publikálás tárolt sablonná (az A1 StoredTemplates felé) működik.

### B3 · Journey — elfogadási kritériumok
68. trigger → condition → action → wait → branch determinisztikus **fake órán**; `journey_runs`
    perzisztens; **idempotens lépés** (kétszeri tick = egy hatás).
69. Trigger-források: form-signup, tag, szegmensbe lépés, API-esemény, **ÉS INBOUND E-MAIL** (A4-ből).
70. Exit: cél elérve / leiratkozás / manuális; `EnsureEntitlement:journeys` gate (FakeVentPay).
71. Inbound-trigger **csak megerősített kontaktnál** indíthat marketing ágat.

### B4 · Marketing analitika — elfogadási kritériumok
72. Timescale aggregátumok kampány / journey / lista / tag bontásban; a ráták helyesek.
73. **KONZISZTENCIA**: a marketing analitika és a platform-napló ugyanarra az esemény-forrásra épül —
    ugyanaz a kampány ugyanazt a delivered-számot adja mindkét felületen.
74. Export CSV/API; klikk-térkép adatszerkezet.

### M2 Definition of Done
- [ ] Mind a 26 kritériumhoz (49–74) tartozik néven nevezhető zöld teszt
- [ ] `venture-security` átment a B1-en (PII + consent-bizonyíték)
- [ ] a block-tree JSON Schema **kontraktus** a ventStudio felé (contract-guard őrzi)
- [ ] a konzisztencia-teszt zöld: egyetlen esemény-forrás
- [ ] Playwright + mockup screenshot-verify az editor és a journey-builder flow-ra
- [ ] `main` zöld; minden packet PR-only

### M2 kockázatok
- **A block-tree séma a ventStudio közös kontraktusa** — ha itt eltérünk tőle, a landing-page
  oldal nem tudja majd újrahasznosítani. A sémát külön fájlban, verziózva tartjuk.
- **MJML PHP-ből:** a hivatalos MJML Node-alapú. Döntés a B2 ADR-jében: node-hívás konténerből
  VAGY PHP-s MJML-implementáció; a snapshot-teszt mindkettőnél ugyanaz.
- **Journey fake óra:** a determinizmus a tesztben csak akkor tartható, ha a motor SOHA nem
  hív `now()`-t közvetlenül — csak az injektált órát.
- **A consent bizonyíthatósága** (GDPR Art. 7(1)): a `consent_events` append-only tábla nélkül a
  visszavonás felülírná az eredeti bizonyítékot.

### M2 becslés
B1: 5–6 nap · B2: 6–7 · B3: 4–5 · B4: 3–4 → **~18–22 nap**

## A sáv — PLATFORM (Mailgun-kiváltás), M0 után
- [ ] A1 Messages API (MIME, batch/recipient-variables, `o:`/`h:`/`v:`, ütemezés, testmode, tárolt sablonok)
- [ ] A2 SMTP relay + IP-poolok + warmup + prioritásos sorok + retry/deferral + bounce-osztályozó
- [ ] A3 Napló + üzenet-trace (SMTP-párbeszéd) + retenció + tag-stat + Events API + webhook replay/DLQ
- [ ] A4 **Inbound routing** (MX, route-motor, MIME-parse, stripped-text, csatolmány, store/forward/stop, FBL)
- [ ] A5 Címellenőrzés (szintaxis/MX/szerep/eldobható/catch-all/szonda/kockázat, egyedi + bulk)
- [ ] A6 Kézbesíthetőségi suite (blocklist, DMARC RUA, seed/inbox placement, spam-score, MTA-STS/TLS-RPT/BIMI)

## B sáv — STUDIO (Mailchimp-kiváltás), M0 után, A-val párhuzamosítható
- [ ] B1 Közönség + consent (double opt-in) + űrlapok + preference center + ventOS sync (fake)
- [ ] B2 Block editor (block-tree + MJML) + kampány-életciklus + célzás + A/B + ütemezés
- [ ] B3 Journey motor + builder UI (API- és inbound-trigger)
- [ ] B4 Marketing analitika

## C sáv — Zárás
- [ ] C1 ventPay számlázás + metering (6 dimenzió) + sub-accountok konszolidált számlázása
- [ ] C2 Super-admin + i18n (HU/EN/DE/IT/ES/RU) + teljes gate-sor

## Párhuzamosítás
M0 után az **A és B sáv külön worktree-ben, külön runnerrel** futtatható:
`./venture-nightly.sh --track A` és `--track B`. A C sáv csak A1–A6 és B1–B4 után indul.

## Emlékeztető
`/fazis-terv <milestone>` → packetenként `/teszt-elobb` → `venture-reviewer` + `venture-contract-guard`
(+ `venture-security` ha kripto/auth/PII/pénz/**inbound-parser**) → `/pr-kapu` → **PR nyit** (ember mergel).

## M0 backlog (jóváhagyáskor kivett, NEM ebbe a fázisba)
- `POST /v1/messages` + OpenAPI + `@venture/sdk` generálás → A1
- `SmtpRelayDriver` implementáció (a kontraktus M0-3-ban rögzül, a kód A2)
- `smtp_transactions` + üzenet-trace, naplóretenciós törlő job → A3
- MTA-STS / TLS-RPT / BIMI mezők KITÖLTÉSE (M0-2 csak a sémát hozza) → A6
- `docs/ventmail-latvanytervek.html` bővítése a hiányzó M0-képernyőkkel (ma 10 képernyő, domain-wizard nincs)

## A6 backlog (nevesített gazdával, NEM ebbe a packetbe)
- **A seed-lista külső gazdája** (postafiók-kezelés + olvasás szolgáltatás) még nem él: a fake áll a
  helyén, teszttel fedett kontraktusként, és **élesben kivételt dob** — kitalált 0% inbox-arány
  rosszabb, mint a hiányzó funkció. → külső integráció, dátum nélkül.
- **A DMARC-jelentések automatikus betöltése** az A4 inbound route-ján keresztül. A parse ÉS a
  perzisztálás megvan (`DmarcReportIngestor`), a betöltés ma a `deliverability:ingest-dmarc`
  paranccsal megy. → C2/devops.
- **A DMARC-jelentés TENANT-HOZZÁRENDELÉSE a szállító levélből.** Ma a `policy_published >
  domain` mező dönti el, kié a jelentés — az viszont a FELADÓ SZAVA, és az egyetlen ellenőrzés,
  hogy a domain nálunk verifikált. A hamis jelentés így nem tud törölni (a `body_hash` a kulcs
  része), de a forrásonkénti listát tudja szennyezni. A helyes hozzárendelés a FOGADÓ
  postafiókból/route-ból jön, a szállító levél DKIM-igazodásával a rekordban. → **C2: ez a
  bekötés ELŐFELTÉTELE** az inbound-alapú automatikus betöltésnek.
- **`TRUSTED_PROXIES=*` leszűkítése a valódi proxy-hálóra.** MÉRVE: `REMOTE_ADDR=172.18.0.9` +
  `X-Forwarded-For: 9.9.9.9` mellett a `request->ip()` `9.9.9.9`, és a `getHost()` is az
  `X-Forwarded-Host`-ot veszi — a beállítás tehát NEM no-op. MINDEN rate-limiter átkötve a
  valódi `REMOTE_ADDR`-ra (`RateLimitKeyingTest` őrzi), tehát a fékek már nem a kérő szavára
  kulcsolnak — de a `request->ip()` továbbra is az AUDIT-forrás és a naplósorok IP-je, és
  ott hamisítható. → **devops, élesben KÖTELEZŐ.**
- **`APP_KEY`-rotáció útja a leiratkozó tokenhez.** A tokennek nincs lejárata (szándékosan: régi
  levélből is legitim leiratkozni), viszont nincs kulcs-azonosító (`kid`) sem, tehát a rotáció
  EGYSZERRE némítaná el minden kimenő levélben a leiratkozó linket. → C2, átfedéses
  kulcsellenőrzéssel.
- **A blocklist-riasztás ügyeleti csatornája.** A `BlocklistStateChanged` esemény a csatlakozási
  pont; e-mailben nem küldhető (épp a küldő IP-nk van listán). → C2 (super-admin) / devops.
- **BIMI** — a `bimi_status` mező megvan, a kitöltés nem: VMC-tanúsítványt igényel, ami pénzbe és
  emberi ügyintézésbe kerül. → külön döntés.
- **TLS-RPT jelentések FOGADÁSA** (a rekord kiadása megvan). → C2/devops, a DMARC-ingest mintájára.

## Halasztva
SMS/push · AI tárgysor (Clave AI) · AMP for Email · mobil SDK.
*(Az inbound routing NEM halasztott — A4, magfunkció.)*
