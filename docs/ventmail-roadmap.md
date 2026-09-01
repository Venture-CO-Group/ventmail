# ventMail — Teljes roadmap áttekintés (v2.0)

> **Cél: a Mailgun ÉS a Mailchimp egyidejű kiváltása.** Nem hírlevélküldő — e-mail infrastruktúra
> (ESP), amire ráépül egy marketing suite. Két pillér, egy motor.
>
> Ez a fájl a *térkép*. A napi munkát a `PHASE.md` (aktív fázis + DoD) és a
> `.claude/work/backlog.md` (packet-sorrend) vezeti; a packet-promptok a
> `docs/ventmail-indito-promptok.md`-ben vannak.

---

## 1. A szerkezet egy képen

```
                    M0 · KÖZÖS MAG   (nincs párhuzam, egy runner)
        M0-0 skeleton → M0-1 tenancy+OIDC → M0-2 séma+audit
                      → M0-3 SendingDriver+PreSendGate → M0-4 domainek+suppression
                                     │
                 ┌───────────────────┴───────────────────┐
                 ▼                                       ▼
        A SÁV · PLATFORM                          B SÁV · STUDIO
        (Mailgun-kiváltás)                        (Mailchimp-kiváltás)
        A1 Messages API                           B1 Közönség + consent + űrlapok
        A2 SMTP relay + IP-poolok + sorok         B2 Block editor + kampányok
        A3 Napló + trace + webhookok              B3 Journey
        A4 Inbound routing (MX)                   B4 Marketing analitika
        A5 Címellenőrzés
        A6 Kézbesíthetőségi suite
                 └───────────────────┬───────────────────┘
                                     ▼
                            C SÁV · ZÁRÁS
                 C1 ventPay + sub-accountok · C2 Super-admin + i18n
```

**Miért ez a sorrend:** az M0 adja a *közös motort* (tenancy, séma, küldés-kapu, domainek). Utána az A
és B sáv **külön modulokat** ír, ezért két worktree-ben párhuzamosítható. Ahol mégis közös a terület
(pl. `Events`), ott **az A sáv a gazda**, a B csak olvas — ezt a `contract-guard` ellenőrzi.

---

## 2. M0 — Közös mag

**Globális DoD:** hitelesített domainről a FakeDriveren küldött üzenet átmegy a PreSendGate 6 kapuján
és `delivered` — belső `SendMessage` action feature-tesztjével bizonyítva. *(A publikus
`POST /v1/messages` az A1 gazdája marad — golden rule 7: a `/v1` stabil.)*

| Packet | Tartalom | Kulcs-DoD | Becslés |
|---|---|---|---|
| **M0-0** Skeleton + infra + CI | Laravel 12 / PHP 8.3 modulmonolit, 24 modulváz, Docker Compose (Caddy · app · timescaledb-ha:pg17 · Redis · Horizon · Reverb · Meili), Vite+Inertia+Vue3+TS, `@venture/design\|ui\|sdk`, Pest + Playwright, GitHub Actions CI | konténerben zöld teszt · zöld CI · token-tiszta smoke oldal | 1–2 nap |
| **M0-1** Tenancy + OIDC + sub-account | Valódi ventSSO OIDC (auth-code + PKCE, JWKS, `kid`-rotáció), 6 szerep a `resource_access.ventmail.roles`-ból, `workspaces`/`workspace_parents`/`workspace_members`, global scope, Policy-k | tenant- ÉS sub-account izoláció zöld · 6 szerep fedve | 3–4 nap |
| **M0-2** Séma + audit | A spec 25. fejezetének M0-táblái, `message_events` Timescale hypertable, `messages` (tags[], variables, deliverytime, testmode, ip_pool_id, idempotency_key), `audit_logs` | migrate zöld dev+CI · hypertable insert · audit-write | 2–3 nap |
| **M0-3** SendingDriver + PreSendGate | `SendingDriver` interfész + Fake/Log/Postal driver, **PreSendGate 6 kapu**, `channel` szeparáció, idempotencia, strukturált `reason` kódok, gate-audit | mind a 6 kapu külön piros→zöld · idempotencia · gate megkerülhetetlen | 3–4 nap |
| **M0-4** Domainek + wizard + suppression | SPF/DKIM/DMARC/tracking-CNAME generálás, DNS-check állapotgép, DKIM-rotáció átfedéssel, suppression store, wizard UI | verifikálatlan domain elutasítva · wizard flow zöld · token-tiszta UI | 3–4 nap |

**M0 összesen: ~12–17 nap (~17–22 nightly kör), EGY runnerrel.**

### Az M0 négy jóváhagyott döntése
1. **Külön M0-0 packet** hozza a skeletont — a repóban nem volt `composer.json`.
2. **ventSSO:** a `ventmail` kliens regisztrációja emberi lépés; addig a fejlesztés a **lokális ventsso
   példány** ellen megy (valódi OIDC, valódi JWKS — nem stub).
3. **TimescaleDB** `timescale/timescaledb-ha:pg17` **dev-ben ÉS CI-ban** — a hypertable-teszt valódi
   Timescale ellen fut, nincs „CI-ban más, mint dev" kockázat.
4. Az **M0 globális DoD-t belső action feature-tesztje** bizonyítja, nem publikus endpoint.

---

## 3. A sáv — PLATFORM (a Mailgun kiváltása)

| Packet | Tartalom | Kulcs-DoD |
|---|---|---|
| **A1** Messages API | `POST /v1/messages` + `/v1/messages.mime` · batch **1000 címzett** recipient-variables-szel · `o:` vezérlők (deliverytime, testmode, tag max 3, tracking/-clicks/-opens, dkim, require-tls, sending-ip-pool) · `h:X-*` fejlécek · `v:*` változók (visszajönnek eseményben és webhookban) · tárolt sablonok API verziózással | Idempotency-Key dupla-küldés-védelem · SDK-drift 0 |
| **A2** SMTP relay + IP-poolok + sorok | Submission 587 STARTTLS / 465 TLS · **nyílt relay elutasítva (BLOCKING teszt)** · a PreSendGate az SMTP-útvonalon is · IP-poolok, warmup-görbe, rotáció, karantén · sor-prioritás `transactional > journey > campaign` · deferral-backoff + per-domain throttle · bounce-osztályozó | nyílt relay teszt zöld · warmup + prioritás + bounce tesztelt |
| **A3** Napló, trace, események, webhookok | Kereshető napló (Meili + Timescale) · **üzenet-trace az SMTP-párbeszéddel** (a „miért nem érkezett meg?" funkció) · `smtp_transactions` · retenció (lejáratkor nyers esemény törlődik, aggregátum marad) · tag-statisztika · `GET /v1/events` kurzoros lapozással · webhook HMAC-SHA256 + retry + **DLQ + replay** | trace SMTP-párbeszéddel · retenciós törlés · replay+DLQ |
| **A4** **Inbound routing (MX)** | Route-motor (match_recipient / match_header / catch_all, `stop()`) · **MIME-parser sandboxban**, rekurzió- és időlimittel · **stripped-text** (idézet + aláírás levágása, HU és EN minták) · csatolmány presigned URL, méretlimit, **zip-bomba**, ClamAV-karantén · store / forward(url, HMAC) / forward(email) / stop · FBL/ARF · `.eml` fixtúra-injektor MX nélkül | parser stabil malformált inputon · tenant-izolált route-ok |
| **A5** Címellenőrzés | Szintaxis (RFC 5322) · domain+MX · szerepcím · eldobható domain · catch-all · elgépelés-javaslat · kockázati pontszám és minősítés · **mailbox-szonda** rate-limittel és cache-sel · `POST /v1/validate` + bulk CSV job | 8 ellenőrzés + pontszám · bulk export · gate- és űrlap-integráció |
| **A6** Kézbesíthetőségi suite | Blocklist-monitor riasztással · **DMARC RUA** XML (gzip is) parse + forrásonkénti aggregáció · seed / inbox placement riport · küldés előtti spam-score + link-ellenőrzés · MTA-STS / TLS-RPT / BIMI · **one-click unsubscribe (RFC 8058)** kikényszerítve | dashboard flow zöld · riasztás működik |

---

## 4. B sáv — STUDIO (a Mailchimp kiváltása)

| Packet | Tartalom | Kulcs-DoD |
|---|---|---|
| **B1** Közönség + consent + űrlapok | Lista / tag / group CRUD · **SegmentEngine DSL** (attribútum + esemény + validation-eredmény) · Meili contact-index · **double opt-in E2E** (jogalap, forrás, ip_hash, verzió, ts) · unconfirmed kontakt marketingen blokkolva · GDPR export/törlés (**a suppression marad**) · raw IP nem tárolódik · űrlap honeypot + time-trap · preference center · `FakeVentureOsSync` kétirányú idempotens upsert | form→opt-in→CRM-sync zöld · leiratkozás mindkét irányban azonnal |
| **B2** Block editor + kampányok | Közös **block-tree JSON Schema** (ventStudio-kontraktus) → **MJML** → reszponzív HTML + plaintext · merge-tag auto-escape + fallback · **marketing sablonban kötelező leiratkozó-blokk** · kampány-életciklus · célzás (lista+szegmens ∪ mínusz kizárás) · **A/B + automata győztes-kihirdetés** · időzóna-tudatos ütemezés · Horizon batch + Reverb progress · publikálás tárolt sablonná | snapshot stabil · escape · A/B · sor-prioritás |
| **B3** Journey | trigger → condition → action → wait → branch determinisztikus fake órán · `journey_runs` perzisztens · **idempotens lépés** · triggerek: form-signup, tag, szegmensbe lépés, API-esemény és **inbound e-mail (A4)** · exit: cél / leiratkozás / manuális · entitlement-gate | többlépéses futás + inbound-trigger + entitlement |
| **B4** Marketing analitika | Timescale aggregátumok kampány / journey / lista / tag bontásban · **konzisztencia: a marketing analitika és a platform-napló ugyanarra az eseményforrásra épül** · CSV/API export · klikk-térkép | konzisztencia-teszt zöld |

---

## 5. C sáv — Zárás

| Packet | Tartalom | Kulcs-DoD |
|---|---|---|
| **C1** ventPay + sub-accountok | Metering **6 dimenzióval**: `sends_counter`, `contacts_peak`, `validations_counter`, `inbound_counter`, `dedicated_ips`, `log_retention_tier` · entitlement-gate (journeys, custom_domain, dedicated_ip, inbound_routes, validation_bulk) a **PreSendGate 4. kapujába** kötve · sub-account **konszolidált számla per-child bontással** · havi zárás + dunning-szimuláció | metering + entitlement + per-child + dunning zöld |
| **C2** Super-admin + i18n | Workspace/plan-override · globális suppression · Postal + MX cluster health · IP-pool és warmup felügyelet · deliverability-riasztások · audit-kereső · **auditált impersonation** · i18n **HU/EN/DE/IT/ES/RU** kulcs-teljességgel (hiányzó kulcs = teszt-bukás) | operátori flow-k + i18n teljesség · a **teljes `/pr-kapu`** átmegy |

---

## 6. Mockolási stratégia — ez teszi lehetővé a folyamatos buildet

| Külső / fizikai függés | Állapot | Build-idejű megoldás |
|---|---|---|
| ventSSO | **él** | valódi OIDC (lokális példány, amíg a `ventmail` kliens regisztrálatlan) |
| Postal / kimenő MTA | ops | `FakeDriver` determinisztikus event-visszajátszással; `PostalDriver` kész, config mögött |
| MX-fogadás | ops | **`.eml` fixtúra-injektor** — a route-motor és a parser MX nélkül is tesztelhető |
| SMTP relay | részben | lokális submission-listener a teszthez; éles port/TLS ops |
| Mailbox-szonda | külső háló | `FakeProbe` determinisztikus válaszokkal |
| Blocklist / DMARC / seed | külső | `FakeBlocklistClient`, minta-RUA XML-ek, seed-stub |
| ventPay | nincs | `FakeVentPayClient` (OpenAPI kontraktus) |
| ventOS | nincs | `FakeVentureOsSync` |
| ventStudio block motor | nincs | közös block-tree JSON Schema + minimál editor `@venture/ui`-ból |

**Elv:** minden külső gazda **interfész mögött**, a fake **teszttel fedett kontraktus**. Élesedéskor
csak a config vált — a `contract-guard` őrzi, hogy ne szivárogjon be reimplementáció.

---

## 7. Agent-orchestration

| Munka | Agent | Kötelező review |
|---|---|---|
| modulhatár / ADR | `venture-architect` (read-only, kódot nem ír) | — |
| teszt-előírás | `venture-test` — **a fejlesztő ELŐTT** | — |
| backend packet | `venture-backend` | `venture-reviewer` + `contract-guard` |
| frontend packet | `venture-frontend` | `reviewer` + `mockup` screenshot-hurok |
| migráció | `venture-db` | destruktív = review-hoz kötött (hook) |
| MTA / Docker / infra | `venture-devops` | prod deploy SOSEM automatikus |
| **auth · PII · pénz · kripto · inbound-parser · SMTP** | + `venture-security` | **2. reviewer KÖTELEZŐ** |
| „egy képesség — egy gazda" | `contract-guard` minden PR-en | BLOCKING sértésnél |

**Vasszabály:** az író agent **soha** nem review-zza magát; a review mindig más kontextusban fut.
**PR igen, auto-merge soha** — a merge az egyetlen kötelező emberi pont.

---

## 8. Halasztott ops-sáv (éles indulás előtt, kézzel)

**Kimenő:** Postal cluster · dedikált MTA host · PTR/rDNS · port 25 nyitás · marketing/transactional
IP-szeparáció · élő SPF/DKIM/DMARC DNS · IP-warmup indítás · Postmaster/SNDS · `FakeDriver → PostalDriver`.
**Bejövő:** MX-rekordok · SMTP-fogadó éles · rspamd + ClamAV · a fixtúra-injektor lecserélése.
**Relay:** 587/465 tanúsítvány + tűzfal · éles `smtp_credentials`.
**Validation:** szonda kimenő IP + konzervatív rate-limit (RBL-kockázat).
**Kézbesíthetőség:** seed-fiókok · blocklist API-kulcsok · DMARC RUA cím a DNS-be.
**Integrációk:** élő ventPay · élő ventOS · ventStudio block motor.
**Emberi:** reggeli PR-review rutin · 2. security reviewer · HU proofreading.

---

## 9. Mikor mondható fel a két előfizetés

- Minden sáv DoD zöld (M0 · A1–A6 · B1–B4 · C1–C2)
- `main` mindig zöld · `contract-guard` **0 sértés**
- A `FakeDriver → PostalDriver` csere **csak config**
- A spec **28. fejezetének kiváltás-térképe** minden sora kipipálva

Ekkor a **Mailgun és a Mailchimp előfizetés egyszerre mondható fel.**

---

## 10. Amit szándékosan NEM itt építünk

landing page → **ventStudio** · social/ads → **ventPost** · fizetés → **ventPay** · azonosítás →
**ventSSO** · consent banner → **ventCookie** · fájltár → **ventVault** · ticket → **ventSupport** ·
csatolmány-szkennelés → **ventSec**.

Ha a gazda még nem él: **log + `TODO ventX`**, soha nem saját implementáció.
