# PHASE.md — ventMail (v2.0)

> A `SessionStart` hook ezt injektálja minden munkamenet elején. Packet lezárásakor frissítsd az
> „Aktív packet"-et, és pipáld a készeket.

## Cél
**Mailgun + Mailchimp egyidejű kiváltása.** Két pillér (Platform / Studio) egy közös motoron.

## Aktuális milestone: **M0 — Közös mag** *(fázisterv jóváhagyva: 2026-08-31)*
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
- [x] **M0-4 · Domain-hitelesítés + wizard + suppression** — PR #7

> A packetek EGYMÁSRA ÉPÜLNEK (stacked PR-ek): a merge sorrendje kötelezően #3 → #4 → #5 → #6 → #7.

## Aktív packet
> **Nincs futó packet** — az M0 mind az öt packetje kész és PR-ben áll (#3–#7), emberi
> mergre várva. A merge után az **A sáv (A1)** és a **B sáv (B1)** indulhat párhuzamosan,
> külön worktree-ben.

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

## Halasztva
SMS/push · AI tárgysor (Clave AI) · AMP for Email · mobil SDK.
*(Az inbound routing NEM halasztott — A4, magfunkció.)*
