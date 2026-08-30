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

## Modulok
**Közös:** Identity · Tenancy(+sub-account) · Domains · IpPools · Sending · Events · Suppression
**Platform:** Messages · Smtp · Inbound · Logs · Validation · Webhooks · Deliverability · StoredTemplates
**Studio:** Audience · Consent · Template · Campaign · Journey · Forms · Analytics
**Zárás:** Billing · SuperAdmin · Api

## Ecosystem-kötések
- **ventSSO (ÉL):** OIDC client `ventmail`; roles `resource_access.ventmail.roles`; tenant-tagság
  ventMail SAJÁT `workspace_members`-ében.
- **ventPay:** metering `sends_counter`, `contacts_peak`, `validations_counter`, `inbound_counter`,
  `dedicated_ips`, `log_retention_tier`. Kártyaadat SOHA. Amíg nem él → `FakeVentPayClient`.
- **ventOS:** kétirányú CRM-sync (`external_id`). Amíg nem él → `FakeVentureOsSync`.
- **ventStudio:** közös block-tree JSON Schema; MJML a ventMail oldalán. **Landing page NEM itt** — ventStudio.
- **ventSupport:** az inbound route `forward()`-olja neki az e-mail csatornát.
- **ventSec:** csatolmány-szkennelés (ClamAV), később SIEM.

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
- Kripto/auth/PII/pénzügy **és inbound-parser** érintésekor `venture-security` = 2. reviewer.
- Külső gazda mindig **interfész mögött**; a fake = teszttel fedett kontraktus.
- Sávok: **M0 közös mag** → **A sáv (Platform)** és **B sáv (Studio)** párhuzamosítható → **C sáv (zárás)**.

## Tesztparancs
`php artisan test --parallel` (Pest) + Playwright a flow-kra. A commit-kapu hook maga futtatja.
