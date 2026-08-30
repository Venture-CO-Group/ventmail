# PHASE.md — ventMail (v2.0)

> A `SessionStart` hook ezt injektálja minden munkamenet elején. Packet lezárásakor frissítsd az
> „Aktív packet"-et, és pipáld a készeket.

## Cél
**Mailgun + Mailchimp egyidejű kiváltása.** Két pillér (Platform / Studio) egy közös motoron.

## Aktuális milestone: **M0 — Közös mag**
**M0 globális DoD:** hitelesített domainről a FakeDriveren küldött üzenet átmegy a PreSendGate 6 kapuján és „delivered".

## Branch-konvenció
`feat/<packet-id>-<név>` — pl. `feat/m0-1-tenancy-oidc`. `main`-re direkt push TILOS (hook). PR-only.

## M0 packetek — sorrend kötelező
- [ ] **M0-1 · Tenancy + OIDC + sub-account váz** — architect → backend
- [ ] **M0-2 · Alap séma + audit** — backend, db
- [ ] **M0-3 · SendingDriver + PreSendGate (6 kapu) + FakeDriver** — backend, security
- [ ] **M0-4 · Domain-hitelesítés + wizard + suppression** — backend, frontend, mockup

## Aktív packet
> **M0-1** — még nem indult. Prompt: `docs/ventmail-indito-promptok.md`.

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

## Halasztva
SMS/push · AI tárgysor (Clave AI) · AMP for Email · mobil SDK.
*(Az inbound routing NEM halasztott — A4, magfunkció.)*
