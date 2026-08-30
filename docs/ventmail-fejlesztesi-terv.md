# ventMail — Fejlesztési Terv v2.0 (Mailgun + Mailchimp kiváltás, Mac mini 24/7)

> **Cél:** az **egész ventMail** — ESP-infrastruktúra **és** marketing suite — lefejlesztése egy Mac
> minin futó folyamatos Claude Code hurokban, a **venture-kit** kényszerítő erejével, az élő
> **ventSSO**-ra építve. A fizikai infra és az emberi tényezők külön, halasztott ops-sávban.
>
> **Miért v2:** a v1.0 terv hírlevélküldőt épített volna. A Mailgun-kiváltáshoz kellett: Messages API
> (MIME/batch/vezérlők), SMTP relay, **inbound routing**, napló + üzenet-trace, címellenőrzés,
> IP-pool + warmup, kézbesíthetőségi suite, sub-accountok, webhook-replay.

---

## 0. Előfeltételek

- ✅ **ventSSO él** → valódi OIDC (nem stub).
- ✅ **venture-kit** elkészült (pilot a `ventsso` repóban).
- ⏳ **Mockolandó:** ventPay (`FakeVentPayClient`), ventOS (`FakeVentureOsSync`), ventStudio block motor
  (közös JSON Schema a kontraktus), ventVault/ventURL (opcionális).
- ⏳ **Halasztott ops:** Postal cluster, MX/PTR/port 25, IP-warmup, seed-fiókok — az app-réteg
  `FakeDriver` mögött végig fejleszthető.

---

## 1. Repo bootstrap

```bash
gh repo create venture-co/ventmail --private
cd ~/Developer/venture && git clone git@github.com:venture-co/ventmail.git && cd ventmail
/plugin marketplace add https://github.com/venture-co/venture-kit
/plugin install venture-kit
bash ~/Developer/venture/venture-kit/install.sh     # rules a helyükre
./bootstrap.sh
```

`.claude/venture.conf`:
```
VENTURE_GATE=on
VENTURE_TEST_CMD="php artisan test --parallel"
VENTURE_GUARD_WRITE=on
VENTURE_FORMAT=on
VENTURE_REQUIRE_TESTS=on
```

---

## 2. Repo skeleton

```
ventmail/app/Modules/
  # közös motor
  Identity/  Tenancy/  Domains/  IpPools/  Sending/  Events/  Suppression/
  # PLATFORM (Mailgun-kiváltás)
  Messages/  Smtp/  Inbound/  Logs/  Validation/  Webhooks/  Deliverability/  StoredTemplates/
  # STUDIO (Mailchimp-kiváltás)
  Audience/  Consent/  Template/  Campaign/  Journey/  Forms/  Analytics/
  # zárás
  Billing/  SuperAdmin/  Api/
```
Mellette: `database/migrations/`, `resources/js/`, `tests/` (Pest + Playwright), `docker/`
(Caddy, Horizon, Reverb, Postgres+Timescale, Redis, Meili, **Postal**, **MX-fogadó**, **rspamd**, **ClamAV**).

---

## 3. Mockolás — ez teszi lehetővé a folyamatos buildet

| Külső / fizikai | Állapot | Build-idejű megoldás |
|---|---|---|
| ventSSO | él | **valódi** OIDC |
| Postal / kimenő MTA | ops | `FakeDriver` determinisztikus event-visszajátszással; `PostalDriver` kész, config-mögött |
| MX-fogadás | ops | **`.eml` fixtúra-injektor** — a route-motor és a parser fixtúrákból tesztelhető, MX nélkül |
| SMTP relay | részben | lokális submission-listener a teszthez; éles port/TLS ops |
| Mailbox-szonda (validation) | külső hálózat | `FakeProbe` determinisztikus válaszokkal; éles szonda opcionális |
| Blocklist / DMARC / seed | külső | `FakeBlocklistClient`, minta-RUA XML-ek, seed-fiók stub |
| ventPay | nincs | `FakeVentPayClient` (OpenAPI kontraktus) |
| ventOS | nincs | `FakeVentureOsSync` |
| ventStudio block motor | nincs | közös block-tree JSON Schema + minimál editor `@venture/ui`-ból |

**Elv:** minden külső gazda **interfész mögött**, a fake **teszttel fedett kontraktus**. Élesedéskor
csak a config vált — a `contract-guard` őrzi, hogy ne szivárogjon be reimplementáció.

---

## 4. Sávos build + párhuzamosítás

```
        M0 · KÖZÖS MAG (nincs párhuzam)
   tenancy+OIDC → séma+audit → SendingDriver+PreSendGate → domainek+suppression
                          │
        ┌─────────────────┴─────────────────┐
        ▼                                   ▼
   A SÁV · PLATFORM                    B SÁV · STUDIO
   A1 Messages API                     B1 Közönség + consent + űrlapok
   A2 SMTP relay + IP + sorok          B2 Block editor + kampányok
   A3 Napló + trace + webhookok        B3 Journey
   A4 Inbound routing                  B4 Marketing analitika
   A5 Címellenőrzés
   A6 Kézbesíthetőségi suite
        └─────────────────┬─────────────────┘
                          ▼
                 C SÁV · ZÁRÁS
        C1 ventPay + sub-accountok · C2 Super-admin + i18n
```

**Két runner két worktree-ben** (a fan-out csak független munkára jó):
```bash
tmux new -s vm-a './venture-nightly.sh --track A; bash'
tmux new -s vm-b './venture-nightly.sh --track B; bash'
```
Ütközés-kerülés: az A és B sáv **külön modulokat** ír. Ahol mégis közös (pl. `Events`), az A sáv a
gazda — a B sáv csak olvas. Ez a `contract-guard` ellenőrzési pontja.

---

## 5. Milestone-ok és DoD

### M0 — Közös mag
| Packet | Tartalom | DoD |
|---|---|---|
| M0-1 | Tenancy + OIDC + **sub-account váz** | valódi ventSSO login; tenant- ÉS parent/child izolációs teszt zöld |
| M0-2 | Séma + Timescale + audit | migrate zöld; `message_events` hypertable; audit-write teszt |
| M0-3 | SendingDriver + **PreSendGate 6 kapu** + FakeDriver | mind a 6 kapu külön piros→zöld; idempotencia |
| M0-4 | Domain-hitelesítés + wizard + suppression | verifikálatlan domain elutasítva; wizard Playwright + screenshot |

**M0 globális DoD:** hitelesített domainről a FakeDriveren küldött üzenet átmegy a 6 kapun és „delivered".

### A sáv — PLATFORM (Mailgun-kiváltás)
| Packet | DoD |
|---|---|
| **A1** Messages API | MIME + **batch 1000 címzett recipient-variables-szel** + `o:`/`h:`/`v:` + ütemezés + testmode + tárolt sablon API — mind teszttel; Idempotency-Key dupla-küldés-védelem zöld |
| **A2** SMTP relay + IP | submission 587/465 hitelesítve, **nyílt relay teszt: elutasít**; SMTP-n beadott levél is átmegy a PreSendGate-en; warmup-görbe + bounce-osztályozó + per-domain throttle tesztelt |
| **A3** Napló + események | üzenet-trace **SMTP-párbeszéddel**; retenció lejáratkor nyers esemény törlődik; tag-stat; Events API szűrés; webhook **replay + DLQ** működik |
| **A4** **Inbound routing** | `.eml` fixtúra → route-motor helyes akció; **stripped-text** levágja az idézetet; csatolmány ClamAV + zip-bomba limit; parser **sandboxban**; HMAC-aláírt forward |
| **A5** Címellenőrzés | 8 ellenőrzés + kockázati pontszám; bulk job; űrlap- és **PreSendGate 6. kapu** integráció |
| **A6** Kézbesíthetőség | blocklist-monitor riaszt; DMARC RUA parse + dashboard; seed/inbox placement riport; spam-score küldés előtt |

### B sáv — STUDIO (Mailchimp-kiváltás)
| Packet | DoD |
|---|---|
| **B1** Közönség + consent | form-signup → double opt-in → ventOS sync (fake); unconfirmed marketing blokk; GDPR export/törlés |
| **B2** Editor + kampány | block-tree→MJML snapshot stabil; kötelező leiratkozó-blokk; kampány end-to-end FakeDriveren; A/B győztes |
| **B3** Journey | többlépéses futás fake órán; **inbound- és API-trigger**; entitlement-gate |
| **B4** Analitika | ráták/trendek/bontások; export; a platform-naplóval konzisztens |

### C sáv — Zárás
| Packet | DoD |
|---|---|
| **C1** Billing + sub-accountok | 6 metering-dimenzió; entitlement; konszolidált számla per-child bontással |
| **C2** Super-admin + i18n | operátori flow-k; 6 nyelv kulcs-teljesség; **teljes `/pr-kapu` átmegy** |

---

## 6. Agent-orchestration

| Munka | Agent | Kötelező review |
|---|---|---|
| modulhatár / ADR | `venture-architect` (opus, read-only) | — |
| backend packet | `venture-backend` | `venture-reviewer` + `contract-guard` |
| frontend packet | `venture-frontend` | `reviewer` + `mockup` screenshot-hurok |
| teszt-előírás | `venture-test` (a fejlesztő ELŐTT) | — |
| **auth / PII / pénz / kripto / inbound-parser / SMTP** | + `venture-security` (opus) | **2. reviewer kötelező** |
| „egy képesség — egy gazda" | `contract-guard` minden PR-en | BLOCKING sértésnél |
| migráció | `venture-db` | destruktív = review-hoz kötött (hook) |
| MTA/Docker/infra | `venture-devops` | prod deploy SOSEM automatikus |

**Új security-trigger a v2-ben:** az **inbound MIME-parser** és az **SMTP relay** ugyanolyan kockázati
kategória, mint a kripto — mindkettőnél kötelező a 2. reviewer.

---

## 7. Mac mini 24/7

Mac mini = headless futtató (tmux + Tailscale + SSH), Air = pilótafülke. **Két runner** (A és B sáv)
külön worktree-ben. Éjszakai kör: következő packet → `/teszt-elobb` → `/pr-kapu` → **PR nyit** (soha
nem mergel) → reggeli összefoglaló. Modell-lépcső: opus (architect/reviewer/security), sonnet
(fejlesztők), haiku (docs-sync, összefoglaló). **Egyetlen kötelező emberi pont: a reggeli review + merge.**

---

## 8. Halasztott ops-sáv (éles indulás előtt, kézzel)

**Kimenő:** Postal cluster · dedikált MTA host · **PTR/rDNS** · **port 25** nyitás · marketing/transactional
IP-pool szeparáció · SPF/DKIM/DMARC élő DNS · **IP-warmup** indítás · Postmaster/SNDS bekötés ·
`FakeDriver → PostalDriver` config-váltás.
**Bejövő:** **MX-rekordok** · SMTP-fogadó éles · rspamd + ClamAV konténer · a `.eml` fixtúra-injektor
lecserélése valódi forgalomra.
**Relay:** 587/465 tanúsítvány + tűzfal · éles `smtp_credentials` kiosztás.
**Validation:** mailbox-szonda kimenő IP + konzervatív rate-limit (RBL-kockázat!).
**Kézbesíthetőség:** seed-fiókok létrehozása · blocklist-API kulcsok · DMARC RUA cím a DNS-be.
**Integrációk:** élő ventPay, élő ventOS, ventStudio block motor.
**Emberi:** reggeli PR-review rutin · 2. security reviewer · HU proofreading.

---

## 9. Globális mérce

**Setup kész, ha:** M0→A1 végigvihető anélkül, hogy egyetlen Venture-konvenciót kézzel elmagyaráznál.
**App v1 kész, ha:** minden sáv DoD zöld · `main` mindig zöld · `contract-guard` 0 sértés ·
a `FakeDriver` → `PostalDriver` csere **csak config** · és a kiváltás-térkép (spec 28.) minden sora
kipipálva — azaz a Mailgun és a Mailchimp előfizetés egyszerre mondható fel.
