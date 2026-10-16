# CLAUDE.md — ventMail (v2.0)

> App-specifikus tények és szabályok. A cross-cutting platform-szabályokat a **venture-kit**
> `.claude/rules/` szállítja; itt csak a ventMail-specifikus. Tartsd <200 sor alatt.

## Mi ez
ventMail = a Venture család **teljes e-mail infrastruktúrája**, kimenő ÉS bejövő irányban (`ventmail.app`).
**Cél: a Mailgun ÉS a Mailchimp egyidejű kiváltása.** Nem hírlevélküldő.

**Két pillér, egy motor:**
- **PLATFORM (Mailgun-oldal):** Messages API (+MIME, batch, ütemezés, testmode, tag-ek, `o:`/`h:`/`v:`
  vezérlők), SMTP relay, **inbound routing (MX)**, napló + üzenet-trace, címellenőrzés, IP-poolok +
  warmup, kézbesíthetőségi suite, tárolt sablonok API, sub-accountok.
- **STUDIO (Mailchimp-oldal):** közönség + szegmensek, consent/double opt-in, block editor + MJML,
  kampányok + A/B, Customer Journey, űrlapok + preference center, marketing analitika.
- **Közös motor:** domainek/hitelesítés · IP-poolok · PreSendGate · SendingDriver · események ·
  suppression · napló · API. A kampány ugyanazon a motoron megy, mint az API-hívás.

## Stack (kanonikus Venture)
Laravel 12 / PHP 8.3 modulmonolit `app/Modules/<Name>` · Vue 3 + TS + Inertia · PG17 + **TimescaleDB** ·
Redis + Horizon · Reverb · Meilisearch · Caddy · Docker Compose · Vultr EU · Wasabi EU.
MTA: self-hosted **Postal** (kimenő) + **MX-fogadó** (bejövő) + **SMTP submission** (587/465).
v1.1 óta még: ECharts (+ `vue-echarts`), `@vue-flow` (journey vászon), `openspout` (XLSX), **Gotenberg**
(PDF-renderer sidecar). Új függőség csak megengedő licenccel (MIT / BSD / Apache-2.0 / ISC / 0BSD) —
`tests/Feature/Skeleton/LicenseTest.php` őrzi; GPL/AGPL/LGPL a buildben tilos.

## Modulok
**Közös:** Identity · Tenancy(+sub-account) · Domains · IpPools · Sending · Events · Suppression
**Platform:** Messages · Smtp · Inbound · Logs · Validation · Webhooks · Deliverability · StoredTemplates
**Studio:** Audience · Consent · Template · Campaign · Journey · Forms · Analytics
**Zárás:** Billing · SuperAdmin · Api
**ventSec-kötés:** VentSec (m2m token a ventSSO-tól · ítélet-ellenőrzés · VSES események; a `VentSecScanner` az Inbound modulban)

## Felület (v1.1)
- **Felső navigáció** (nem oldalsáv): Platform / Studio / Settings csoportok (disclosure minta, mobilon
  harmonika), workspace-váltó, avatar-menü (monogram), nyelvválasztó. Töréspont media query nélkül
  (`useCompactNav`). Verzió az avatar-menü alján: `VENTMAIL_VERSION` → `config('app.version')`.
- Közös komponensek: `resources/js/Components/Form/` (`VmForm`, `VmFormSection`, `VmFormRow`, `VmTable`, …).
  Az **átfedés-kapu** `e2e/layout-overlap.spec.ts` (1440/1024/390) minden új oldalra kötelező.
- Nyelvek: hu, en, de, fr, it, es, ru (szerver: `lang/*`, kliens: `resources/js/locales/*.json`, teljesség-teszt).
  Szám/dátum/időpont **csak** `resources/js/lib/format.ts` + `useFormat` (profil-formátum); gépi kódok
  `useCodes` / `codes.*` (minden backend enum-értékhez címke — `CodeLabelsTest`).
- Hiányzó `@venture/ui` komponens esetén lokális `Vm*` pótlás + JELENTÉS (lista: `HUMAN-QUEUE.md`).

## Ecosystem-kötések
- **ventSSO (ÉL):** OIDC client `ventmail`; roles `resource_access.ventmail.roles`; tenant-tagság
  ventMail SAJÁT `workspace_members`-ében. Fiók-URL: `SSO_ACCOUNT_URL` → `OIDC_PROVIDER_URL/account`.
- **ventPay:** metering `sends_counter`, `contacts_peak`, `validations_counter`, `inbound_counter`,
  `dedicated_ips`, `log_retention_tier`. Kártyaadat SOHA. Amíg nem él → `FakeVentPayClient`.
- **ventOS:** kétirányú CRM-sync (`external_id`). Amíg nem él → `FakeVentureOsSync`.
- **ventStudio:** közös block-tree JSON Schema; MJML a ventMail oldalán. **Landing page NEM itt** — ventStudio.
  *Kivétel (tulajdonosi döntés):* a ventMail saját, STATIKUS termékoldala az apexen (`ventmail.app`, Blade, `config('app.landing_host')`) itt él — ez NEM a landing-page-builder képesség, az marad a ventStudióé.
- **ventSupport:** az inbound route `forward()`-olja neki az e-mail csatornát.
- **ventSec:** csatolmány-szkennelés a Scan API-n (`INBOUND_SCANNER=clamav|ventsec`, alap `clamav` — fail-closed, Ed25519-ellenőrzött ítélet; a ClamAV a kivezetésig tartalék) + VSES biztonsági események/szívverés (`VENTSEC_EVENTS_ENABLED`, alap KI). Lásd `docs/plans/family-onboarding.md`.
- **ventVault:** még nem él → import-feltöltés, sablon-képek (`AssetStore`), riport-PDF és logó
  (`ReportArtifactStore`) ideiglenesen a megosztott helyi volume-on (`// TODO ventVault`).

## Golden rules (BLOCKING)
1. **ventMail az e-mail egyetlen gazdája — kimenő ÉS bejövő.** Más app nem épít SMTP/MX/template
   réteget; ventMail nem épít identity/consent-banner/fájltár/számlázó/landing-page réteget.
2. **PreSendGate megkerülhetetlen — az SMTP relayen keresztül is.** 6 kapu: domain-auth · suppression ·
   consent (marketing) · entitlement/kvóta · rate-limit · címellenőrzési kockázat.
3. **Nyílt relay tilos.** Minden submission hitelesített + TLS; plaintext auth tiltva.
4. **Marketing csak double opt-in consenttel.** `transactional` ≠ `marketing`; a `campaign` sor SOHA
   nem előzi meg a `transactional`-t (prioritás: transactional > journey > campaign).
5. **Idempotency-Key kötelező** minden küldő POST-on; webhook **HMAC-SHA256 + retry + dead-letter + replay**.
6. **Tenant- ÉS sub-account-izoláció.** Leak = blocking.
7. **`/v1` stabil** — breaking change tilos; OpenAPI ↔ `@venture/sdk` drift = 0.
8. **Deliverability nem alku** — verifikálatlan domainről nincs küldés; SPF/DKIM/DMARC/PTR +
   one-click unsubscribe (RFC 8058) kötelező.
9. **Inbound MIME-parser sandboxban**, csatolmány ClamAV-szkennelve, méret- + zip-bomba limit.
10. **Napló-retenció betartva** — lejárat után nyers esemény törlődik, aggregátum marad.
11. Secret csak env; dev = noindex + Basic Auth; minden admin-művelet auditált.

## Soha (instant blokk)
Nyílt relay · PreSendGate megkerülése bármely útvonalon · marketing consent nélkül · transactional és
marketing keverése · Idempotency-Key nélküli küldő endpoint · aláíratlan webhook · MIME-parse sandbox
nélkül · hardcode-olt design token (csak `@venture/design` + `@venture/ui`) · global scope megkerülése ·
saját identity/consent/számlázó/landing-page reimplementáció · direkt push `main`-re.

## Munkamód
- **„teszt előbb"** mindig. **PR-only, auto-merge SOHA.** Az író agent nem review-zza magát.
- Kripto/auth/PII/pénzügy **és inbound-parser** érintésekor `venture-security` = 2. reviewer
  (import, XLSX-olvasó, deploy-migráció is ide tartozik).
- Külső gazda mindig **interfész mögött**; a fake = teszttel fedett kontraktus (pl. `PdfRenderer`:
  éles/staging `fake` = boot-kivétel, D263).
- Sávok: **M0 közös mag** → **A sáv (Platform)** és **B sáv (Studio)** párhuzamosítható → **C sáv (zárás)**.
- **Séma-változás expand-only** (nullable / alapértékes oszlop, új tábla); kiadott migrációt nem szerkesztünk.
  Hypertable-on nincs `CONCURRENTLY` index. Egy teszt-DB (`ventmail_test`) → egyszerre egy fejlesztő agent.
- **Reserved Idempotency-Key előtagok** (`campaign:`, `journey:`, `template-test:`, `invite:`, `optin:`,
  `smtp:`, `inbound-fwd-`, `dev-seed-`) belső használatra; a `/v1` 400 `IDEMPOTENCY_KEY_RESERVED`-del utasítja el.
- PII-minimum: hibaüzenet/audit/`failed_jobs` nem hordoz címet (`RedactQueryException`); nyers UA nem perzisztálódik.

## Tesztek és eszközök
| Parancs | Mit |
|---|---|
| `php artisan test --parallel` (Pest) | backend; `heavy` csoport (300k import) és `smtp-socket` / `timescale` külön CI-lépés |
| `npm run test:unit` | frontend unit (vitest 4.1.11 + happy-dom 20.14.5, `tests/js/`) |
| `npm run e2e` (Playwright) | flow-k + `layout-overlap`; `E2E_IMPORT_300K=1` a 300k importra (CI-ban mindig, lokálisan opt-in) |
| `npm run screenshots` | `docs/screenshots/v1.1/` (app + mockup, desktop + mobil); **csak tiszta demó-DB-n**: `php artisan migrate:fresh --seed` CSAK a helyi dev DB-n |
| `npm run typecheck` · `npm run build` | `vue-tsc`; build = app + külön riport-IIFE (`vite.report.config.ts`) |
| `deploy/tests/deploy_test.sh` · shellcheck | a `deploy.sh` viselkedése (D227-migráció, guardok) |
A commit-kapu hook maga futtatja a tesztparancsot. Release: `composer.json` `process-timeout` 900.
CI: pint, vue-tsc, build, `composer audit`, `npm audit` (high), `gotenberg-contract` (valódi Gotenberg).

## Kiadás / verziózás
- Tag: `vMAJOR.MINOR.PATCH`; a kiadott tag **soha nem íródik át**. A „v1.1" feature-csomag kiadási tagje
  `v1.2.0` (a `v1.1.0` már élesben futott — D1). A `release.yml` a `VERSION` build-arg-ot adja át; GHCR image tag = git tag.
- `CHANGELOG.md` (Keep a Changelog, hu) minden kiadáskor; döntési nyomvonal: `docs/plans/<verzió>.md`.

## Éles (mail-ovh-01, deploy-ovh — részletek: `docs/plans/deploy-ovh.md`)
- Box: OVH `141.95.64.117` (tx pool, `mta-tx1`) + `51.38.111.172` (mk pool, `mta-mk1`); infra: `venture-infra/boxes/mail`.
- **Két Postal-példány** (tx/mk), külön SNAT-egress, HELO, Return-Path (`rp-tx`/`rp-mk.ventmail.app`).
  A driver a `Channel::poolClass()` szerint választ; keveredés/visszaesés nincs (`PoolSeparationTest`).
- **Küldési mód** (alapból zárva): `SENDING_LIVE`, `SEND_ALLOWLIST(_ENFORCED)`, `SENDING_MARKETING_ENABLED` —
  csak a tulajdonos kapcsolja (RUNBOOK-mail §13). Kapu + dispatcher-ellenőrzés.
- Postal → ventMail: aláírt webhook `/internal/mta/postal/{pool}` (az edge nem engedi kívülről).
- Edge-bizalom: `config/proxy.php` (`TRUSTED_PROXIES` = edge /32, `TRUSTED_HOSTS`), `__Host-` süti,
  rate-limit kulcs = `ClientAddress`. Deploy: `deploy/deploy.sh <tag>` (blue/green, csak GHCR tag).
- **Megosztott fájltár (D227/D242):** külső, nevesített volume-ok — `ventmail-storage` (`/app/storage/app`,
  privát: import, riport-PDF, logó) és `ventmail-storage-public` (`/app/storage/app/public`, sablon-képek).
  App/worker/worker-imports/scheduler/ops mindkettőt írja; a `web` csak a public-ot, csak olvasva
  (Caddy `/storage/*`). A `deploy.sh` hozza létre, semmi nem törli.
- **D227-migráció:** a pre-D227 szín fájljait a `deploy.sh` a régi szín drain + `stop` után másolja a
  volume-okra, `cmp`-pel ellenőrzi, és csak utána `down`. Jelölők: `.d227-migrated`, `.d227-pending`,
  `.d227-conflicts/`. Hibánál a régi szín leállítva megmarad, minden deploy tilt, amíg
  `./deploy.sh --migrate-storage` (a kiszolgáló színt nem érinti) le nem fut. Rollback: a mostani
  `compose.prod.yml` / `compose.data.yml` / `compose.render.yml` maradjon a boxon.
- **Render sidecar:** `deploy/compose.render.yml` (projekt `ventmail-render`, színfüggetlen Gotenberg
  `-chromium`, tag + digest pin, `internal` háló = nincs egress, read-only, `mem_limit 1g`); csak
  app + worker éri el. Env: `ANALYTICS_PDF_RENDERER=gotenberg`, `GOTENBERG_URL`, `ANALYTICS_PDF_TIMEOUT`.
  Deploy után: `php artisan analytics:render-smoke`.
- **Worker-konténerek:** `worker` (küldő, 2g) és külön **`worker-imports`** (3g, `horizon --environment=imports`;
  az XLSX-olvasás izolált gyerekfolyamatban: AS 448 MB, falióra-timeout). Egy szín 7,75g, blue/green alatt
  +7,75g, + Gotenberg 1g (D252, jóváhagyva).
- **Horizon (D121):** a `defaults` üres, környezetenként explicit supervisor-lista — production: `sending`,
  `background`, `campaign-start`, `reports`; imports: `imports`, `imports-inspect`; local/testing: mind a hat.
  Új supervisor csak a `ProvisioningPlan`-tesztet követve (teszt a valódi kimeneten).
- Body-limit: Caddy `@import` 140 MB, máshol 16 MB; app-szinten `LimitRequestBody` (`APP_MAX_BODY_MB`).
- Nyitott üzemeltetési tételek: `HUMAN-QUEUE.md`.
