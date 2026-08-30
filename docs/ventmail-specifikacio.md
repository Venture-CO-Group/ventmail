# ventMail — Master Specifikáció (`ventmail.app`) — v2.0

> **Réteg:** Service — a Venture család **összes e-mail forgalmának gazdája**, bejövő és kimenő irányban.
> **Cél (ÚJ, v2.0):** a **Mailgun ÉS a Mailchimp egyidejű kiváltása**. Nem hírlevélküldő: egy **e-mail
> infrastruktúra** (ESP), amire ráépül egy marketing suite.
> **Nyelv:** magyar (technikai kifejezések angolul) · **Státusz:** build előtt.
>
> **Változás a v1.0-hoz képest:** a v1.0 a Mailchimp-oldalt fedte le, az ESP-oldal hiányzott. A v2.0
> **két pillérre** szervezi az appot egy közös motoron. **Megváltozott döntés:** az inbound routing a
> handoffban „v1.x-re halasztva" volt — Mailgun-kiváltás mellett ez **magfunkció**, előrehozva (A4).
> A ⚠️ jelöli a rekonstruált/javasolt döntéseket.

---

## 0. A termék két arca, egy motorral

```
                    ┌─────────────────────────────────────────┐
                    │           KÖZÖS MOTOR (spine)            │
                    │  domainek · hitelesítés · IP-poolok      │
                    │  PreSendGate · SendingDriver · MTA       │
                    │  események · suppression · napló · API   │
                    └───────────┬─────────────────┬───────────┘
                                │                 │
              ┌─────────────────▼──────┐   ┌──────▼──────────────────┐
              │  PLATFORM (Mailgun)     │   │  STUDIO (Mailchimp)      │
              │  fejlesztőknek          │   │  marketingeseknek        │
              │  ─────────────────      │   │  ──────────────────      │
              │  Messages API + MIME    │   │  Közönség + szegmensek   │
              │  batch + ütemezés       │   │  Consent / double opt-in │
              │  SMTP relay             │   │  Block editor + MJML     │
              │  Inbound routes (MX)    │   │  Kampányok + A/B         │
              │  Napló + üzenet-trace   │   │  Customer Journey        │
              │  Címellenőrzés          │   │  Űrlapok + pref. center  │
              │  IP-pool + warmup       │   │  Marketing analitika     │
              │  Kézbesíthetőségi suite │   │  Send-time optimalizálás │
              │  Sub-accountok          │   │                          │
              └─────────────────────────┘   └──────────────────────────┘
```

**A tézis:** a két termék azért váltható ki egyszerre, mert ugyanaz az infrastruktúra hajtja mindkettőt.
A Mailchimpnek nincs saját MTA-ja a te kezedben, a Mailgunnak nincs kampány-UX-e. Itt a kampány
ugyanazon a `SendingDriver`-en megy ki, mint a tranzakciós API-hívás, ugyanabba a `message_events`
táblába naplóz, és ugyanaz a suppression védi.

**Amit szándékosan NEM csinálunk** („egy képesség — egy gazda"): landing page → **ventStudio** ·
social/ads → **ventPost** · fizetés → **ventPay** · azonosítás → **ventSSO** · consent banner →
**ventCookie**. A Mailchimp ezen funkciói a családban máshol laknak.

---

## 1. Tartalom

**Közös:** 2 architektúra · 3 tenancy/RBAC/sub-accountok · 4 domain & hitelesítés · 5 IP-poolok & warmup ·
6 SendingDriver & PreSendGate · 7 sorkezelés, retry, bounce-osztályozás
**Platform:** 8 Messages API · 9 SMTP relay · 10 Inbound routing · 11 Napló & üzenet-trace ·
12 Címellenőrzés · 13 Események & webhookok · 14 Kézbesíthetőségi suite · 15 Tárolt sablonok API
**Studio:** 16 Közönség · 17 Consent & GDPR · 18 Block editor · 19 Kampányok · 20 Journey · 21 Űrlapok ·
22 Marketing analitika
**Zárás:** 23 Számlázás · 24 Biztonság · 25 Adatmodell · 26 Golden rules · 27 Roadmap · 28 Kiváltás-térkép

---

## 2. Architektúra

### 2.1 Stack (LOCKED — kanonikus Venture)
Laravel 12 (PHP 8.3) modulmonolit · Vue 3 + TS + Inertia · PostgreSQL 17 + **TimescaleDB** ·
Redis + Horizon · Reverb · Meilisearch · Caddy · Docker Compose · Vultr EU · Wasabi EU.

### 2.2 Az MTA-réteg
- **Kimenő:** self-hosted **Postal** cluster, dedikált IP-poolok, marketing/transactional szeparáció.
- **Bejövő:** **MX-fogadó** (Postal inbound vagy Haraka/Postfix ⚠️) → MIME-parser → route-motor.
- **SMTP relay:** ügyfél-facing submission (587 STARTTLS / 465 implicit TLS), per-domain hitelesítés.
- A motor-konténereknek nincs kimenő internet-hozzáférése a whitelistelt SMTP/DNS/RBL célokon kívül.

### 2.3 Modultérkép (`app/Modules/`)
**Közös:** `Identity` · `Tenancy` (+ sub-accountok) · `Domains` · `IpPools` · `Sending` · `Events` · `Suppression`
**Platform:** `Messages` · `Smtp` · `Inbound` · `Logs` · `Validation` · `Webhooks` · `Deliverability` · `StoredTemplates`
**Studio:** `Audience` · `Consent` · `Template` · `Campaign` · `Journey` · `Forms` · `Analytics`
**Zárás:** `Billing` · `SuperAdmin` · `Api`

---

## 3. Tenancy, RBAC, sub-accountok

### 3.1 Tenancy (LOCKED)
Identitás ventSSO-tól; a **tenant-tagság ventMail sajátja** (`workspace_members`). Minden entitás
`workspace_id`-hez kötött, global scope-pal.

### 3.2 Sub-accountok (ÚJ — Mailgun-paritás)
Ügynökségi/reseller minta: egy **parent workspace** alá **child workspace-ek** tartoznak.
- A parent lát összesített használatot, kezelhet domaineket és kulcsokat a childok nevében.
- A child izolált: saját domain, saját suppression, saját napló. **Adat sosem szivárog** a childok
  között (teszt-fedett).
- Számlázás: konszolidált a parentnél, per-child bontással (ventPay metering `parent_id` dimenzióval).

### 3.3 Szerepkörök
`owner` · `admin` · `developer` (ÚJ — API-kulcs, SMTP, webhook, napló; kampányhoz nem nyúl) ·
`editor` · `author` · `analyst`. Leképezés a `resource_access.ventmail.roles`-ból.

---

## 4. Domain & hitelesítés

- Per-domain: **SPF, DKIM** (selector + kulcspár generálás, rotáció), **DMARC**, **PTR/rDNS**,
  opcionálisan **BIMI** (VMC) ⚠️, **MTA-STS** policy + **TLS-RPT** fogadás ⚠️.
- **Tracking domain** (CNAME) a klikk-átirányításhoz és a nyitás-pixelhez, domainenként külön.
- **Bejövő domain:** MX-rekord ellenőrzés az inbound routinghoz.
- Wizard: rekord-generálás → élő DNS-check → állapot (pending/verified/failed). **Verifikálatlan
  domainről nincs küldés** (PreSendGate).
- Domain-szintű beállítás: tracking be/ki, TLS-kényszerítés, DKIM-aláírás, sending-window.

---

## 5. IP-poolok & warmup

- **Dedikált IP-k** poolokba szervezve; pool→domain és pool→csatorna hozzárendelés.
- **Automatizált warmup:** napi volumen-görbe új IP-n, automatikus visszafogás reputáció-romlásnál,
  görbe-sablonok (konzervatív / normál / agresszív).
- **Rotáció** a poolon belül; beteg IP automatikus kivonása (`quarantined`).
- **Shared pool** kisebb tenanteknek; dedikált pool magasabb planen (entitlement).
- Per-pool reputáció-pontszám a bounce/complaint/deferral aggregátumból.

---

## 6. SendingDriver & PreSendGate

### 6.1 SendingDriver (LOCKED)
```php
interface SendingDriver {
    public function send(OutboundMessage $m): DispatchResult;   // idempotens message.id-re
    public function capabilities(): DriverCapabilities;
    public function health(): DriverHealth;
}
```
`PostalDriver` (éles) · `SmtpRelayDriver` ⚠️ · `LogDriver` · `FakeDriver` (determinisztikus
event-visszajátszás). Driver-csere = config.

### 6.2 PreSendGate (BLOCKING) — 6 kapu
1. domain hitelesített · 2. címzett nincs suppression-listán · 3. marketing csatornán érvényes consent ·
4. entitlement/kvóta · 5. rate-limit · 6. **címellenőrzési kockázat** a küszöb alatt (opcionális,
per-domain kapcsolható — a Validation modul pontszáma).
Minden elutasítás strukturált `reason` kóddal, auditálva. **A gate az SMTP relayre is érvényes.**

---

## 7. Sorkezelés, retry, bounce-osztályozás

- **Prioritásos sorok:** `transactional` > `journey` > `campaign` (Horizon queue-k), külön throttle.
  A kampány sosem szoríthatja ki a tranzakciós forgalmat.
- **Deferral-kezelés (4xx):** exponenciális backoff (pl. 5p → 15p → 1ó → 4ó → 24ó, max 72ó),
  **per-fogadó-domain throttle** (Gmail/Yahoo/Microsoft külön bucket).
- **Bounce-osztályozó motor:** SMTP válaszkód + szöveg → `hard` / `soft` / `blocked` / `spam` /
  `mailbox_full` / `policy`. Hard → azonnali suppression; soft → N próba után suppression.
- **FBL/ARF feldolgozás:** panasz-visszacsatolás automatikus suppressionnel.
- **Sending window:** per-domain időablak (pl. csak 8–20 között).

---

## 8. Messages API (Mailgun-paritás) — PLATFORM

### 8.1 Küldési módok
| Mód | Endpoint | Mire |
|---|---|---|
| Strukturált | `POST /v1/messages` | mezőkből épített levél |
| **Nyers MIME** | `POST /v1/messages.mime` | kész MIME továbbítása |
| **Batch** | `POST /v1/messages` + `recipient-variables` | **max 1000 címzett / hívás**, egyedi behelyettesítéssel |
| Tárolt sablonból | `template=<név>` + `vars[...]` | szerver-oldali sablon (15. fejezet) |

### 8.2 Vezérlő paraméterek
`o:deliverytime` (ütemezett küldés, max 7 nap ⚠️) · `o:testmode` (nem megy ki, de naplóz) ·
`o:tag` (max 3 tag/üzenet, tag-szintű statisztika) · `o:tracking`, `o:tracking-clicks`,
`o:tracking-opens` · `o:dkim` · `o:require-tls` · `o:skip-verification` · `o:sending-ip-pool` ·
`h:X-*` egyedi fejlécek · `v:*` egyedi változók (visszajönnek az eseményekben és a webhookban).

### 8.3 Példa — batch, egyedi behelyettesítéssel
```bash
curl https://api.ventmail.app/v1/messages \
  -H "Authorization: Bearer $VENTMAIL_API_KEY" \
  -H "Idempotency-Key: batch-2026-08-31-a" \
  -F channel=transactional \
  -F from='Venture Co <hello@mailer.venture.co>' \
  -F 'to=anna@example.com' -F 'to=bela@example.hu' \
  -F template=order_confirmation \
  -F 'recipient-variables={"anna@example.com":{"order":9183},"bela@example.hu":{"order":9184}}' \
  -F 'v:campaign_ref=aug-orders' \
  -F 'o:tag=orders' -F 'o:require-tls=true'
```

### 8.4 Garanciák
**Idempotency-Key kötelező** minden POST-on. Válasz: `message_id` + `queued`. A további sors az
Események API-n és a webhookon követhető. **`/v1` stabil**, breaking change tilos.

---

## 9. SMTP relay — PLATFORM

- **Submission végpont:** `smtp.ventmail.app` — **587 (STARTTLS)**, **465 (implicit TLS)**; a port 25
  csak MX-fogadásra, nem submissionre.
- **Per-domain SMTP hitelesítő** (felhasználónév + jelszó, hash-elve tárolva, rotálható, domainenként
  több). Ez az útvonal a legacy appoknak kell, amik nem tudnak REST-et.
- Az SMTP-n beadott levél **ugyanazon a PreSendGate-en** megy át, mint a REST — nincs kiskapu.
- Vezérlők SMTP-fejlécen: `X-VentMail-Tag`, `X-VentMail-Variables`, `X-VentMail-Deliverytime`,
  `X-VentMail-Testmode`.
- Rate-limit + hitelesítő-alapú kvóta; brute-force védelem; TLS kötelező (plaintext auth tiltva).

---

## 10. Inbound routing (MX) — PLATFORM ⚠️ *(handoffban halasztott volt, most magfunkció)*

### 10.1 Fogadás
MX-rekord a tenant domainjére (vagy aldomainre) → SMTP-fogadó → **spam/vírus-szűrés** (rspamd/SpamAssassin
+ ClamAV, később **ventSec** felé kiszervezhető) → **MIME-parser**.

### 10.2 Route-motor
Prioritás szerint rendezett szabálylista, kifejezésekkel:
```
match_recipient("^support@venture\.co$")      → forward("https://ventsupport.app/hooks/mail"), stop()
match_recipient("^(.*)@reply\.venture\.co$")  → store(notify: "https://app…/hooks/reply")
match_header("subject", ".*számla.*")         → forward("ops@venture.co")
catch_all()                                   → store()
```
**Akciók:** `forward(url|email)` · `store(notify)` · `stop()`.
**Match:** `match_recipient`, `match_header`, `catch_all`.

### 10.3 Parsed payload
A `forward(url)` **JSON-t** POSTol: feladó, címzett, tárgy, `body-plain`, `body-html`,
**`stripped-text`** (idézett rész és aláírás levágva — reply-kezeléshez), fejlécek, csatolmány-lista
(Wasabi presigned URL-lel), spam-pontszám, DKIM/SPF eredmény. **HMAC-aláírt**, retryzett.

### 10.4 Tárolás
`store()` esetén a nyers MIME a Wasabin, **konfigurálható retencióval** (alap 3 nap ⚠️), API-n
lekérhető. Csatolmány-méret limit planenként.

### 10.5 Miért mag
Enélkül nincs válasz-kezelés (ticketing, reply-to-comment), nincs `catch_all` teszt-környezet, és a
**ventSupport e-mail csatornája** sem építhető meg. Ez a Mailgun egyik fő értéke.

---

## 11. Napló & üzenet-trace — PLATFORM

- **Kereshető napló** minden üzenetre: címzett, tárgy, tag, csatorna, domain, IP-pool, állapot, időablak.
  Meilisearch + Timescale mögött.
- **Üzenet-trace:** egyetlen `message_id` teljes életútja — sorbaállítás → PreSendGate döntés →
  kiválasztott IP → **SMTP-párbeszéd naplója** (a fogadó szerver válaszkódjai és üzenetei) → kézbesítés
  vagy visszapattanás okkal → megnyitás/klikk. Ez a „miért nem érkezett meg?" képernyő.
- **Retenció planenként** (pl. 1 / 7 / 30 nap; hosszabb tárolás add-on). Lejárat után aggregátum marad,
  nyers esemény törlődik (GDPR-adatminimalizálás).
- **Tag-szintű statisztika:** per-tag kézbesítés/nyitás/klikk/bounce (Mailgun tag-stats paritás).
- Napló-export API + CSV.

---

## 12. Címellenőrzés (Email Validation) — PLATFORM

Önálló, **külön is értékesíthető** szolgáltatás — a Mailgun egyik fizetős pillére.

| Ellenőrzés | Mit ad |
|---|---|
| Szintaxis (RFC 5322) | formai hiba |
| Domain + **MX** | létezik-e fogadó |
| **Szerepcím** detektálás | `info@`, `sales@`, `admin@` |
| **Eldobható** domain | temp-mail szolgáltatók |
| **Mailbox-szonda** (SMTP RCPT) | létezik-e a postafiók |
| Catch-all felismerés | „mindent elfogad" domain |
| Elgépelés-javaslat | `gmial.com → gmail.com` |
| **Kockázati pontszám** | `deliverable` / `risky` / `undeliverable` / `unknown` |

- **Egyedi API:** `POST /v1/validate` (szinkron) · **Bulk:** feltöltött lista aszinkron feldolgozása,
  eredmény-export.
- **Beépülés:** az űrlapokba (Studio) valós idejű ellenőrzés, és a **PreSendGate 6. kapujába**.
- Korlát: a mailbox-szonda csak konzervatív rate-limittel, saját IP-ről, RBL-kockázat minimalizálásával;
  eredmény cache-elve.

---

## 13. Események & webhookok — PLATFORM

- **Eseménytípusok:** `accepted`, `rejected`, `delivered`, `deferred`, `bounced`, `complained`,
  `unsubscribed`, `opened`, `clicked`, `stored` (inbound), `list_member_*` (Studio).
- **Events API:** `GET /v1/events` — szűrés típusra, címzettre, tagre, `v:*` változóra, időablakra;
  kurzoros lapozás.
- **Webhook:** endpoint **eseménytípusonként** konfigurálható; **HMAC-SHA256** + timestamp
  (replay-védelem); **exponenciális retry** + **dead-letter**; **UI-ból újrajátszás (replay)** — ez
  hiányzott a v1.0-ból, a Mailgun-paritáshoz kell.
- Webhook-teszt gomb + utolsó 100 kézbesítés naplója státuszkóddal.

---

## 14. Kézbesíthetőségi suite — PLATFORM

Ez az, amiért saját infrán mersz maradni.

- **2025/2026 bulk-sender megfelelés:** SPF+DKIM+DMARC, **one-click unsubscribe (RFC 8058)**,
  spam-ráta <0.3%, PTR, TLS, ARC.
- **Blocklist-monitor:** Spamhaus/SORBS/Barracuda stb. IP-re és domainre, riasztással.
- **DMARC aggregate (RUA) fogadás + dashboard:** ki küld a nevedben, mennyi megy át.
- **TLS-RPT** fogadás ⚠️.
- **Seed-lista / inbox placement teszt:** próbaküldés seed-fiókokba (Gmail/Outlook/Yahoo), inbox vs
  spam vs promóciók arány kampány előtt.
- **Spam-pontszám** küldés előtt (rspamd/SpamAssassin) + tartalmi javaslatok.
- **Link-ellenőrzés:** shortener-blokk, blocklistás domain a levélben.
- **Postmaster/SNDS** adatbekötés ⚠️, reputáció-trend.

---

## 15. Tárolt sablonok API — PLATFORM

Szerver-oldali, **verziózott** sablonok a tranzakciós küldéshez (a Studio block-editorától külön útvonal):
- `POST /v1/templates`, verziók, `active` verzió.
- Változó-behelyettesítés (handlebars-szerű ⚠️), auto-escape, fallback.
- A fejlesztő nem hardcode-ol HTML-t az appjába: `template=order_confirmation` + `vars[...]`.
- A Studio block-editorából publikálható tárolt sablonná (közös block-tree → MJML pipeline).

---

## 16. Közönség — STUDIO

Listák · dinamikus **szegmensek** (`SegmentEngine` DSL: attribútum + esemény) · tag-ek/group-ok ·
**kontakt-profil aktivitás-idővonallal** · **RFM/aktivitás-alapú** szegmensek ⚠️ · import/export ·
**ventOS CRM kétirányú sync** (`external_id`, last-write-wins, audit). Amíg ventOS nem él →
`FakeVentureOsSync` teszt-fedett kontraktussal.

---

## 17. Consent & GDPR — STUDIO/KÖZÖS

**Double opt-in** kötelező marketingre (`ConsentService`): jogalap, forrás, ip_hash, verzió, timestamp.
Megerősítetlen kontakt marketing csatornán **PreSendGate-blokk**. ROPA-export; érintetti export/törlés
(**suppression megmarad**, PII anonimizál); raw IP nem tárolt; konfigurálható retenció.
**Preference center** minden marketing levélben; egykattintásos leiratkozással konzisztens.

---

## 18. Block editor — STUDIO

**ventStudio block motor + MJML** (LOCKED). Közös **flat node-map block-tree JSON Schema** (ugyanaz,
mint a Studio oldal-editor és a ventURL bio-builder). Pipeline: block-tree → MJML → reszponzív HTML +
plaintext, inline CSS. Merge-tag motor auto-escape + fallback; feltételes blokk ⚠️; kötelező
leiratkozó-blokk marketing sablonban. Kliens-kompatibilitás (Outlook/Gmail/Apple Mail) MJML szinten.

---

## 19. Kampányok — STUDIO

Életciklus `draft → review → scheduled → sending → sent` (+`paused`/`canceled`). Célzás lista+szegmens+tag,
kizárásokkal. **A/B teszt** (tárgysor/feladó/tartalom/idő) győztes-kihirdetéssel ⚠️. Időzóna-tudatos
ütemezés; **send-time optimalizálás** a nyitási előzményből ⚠️. Küldés előtt: becsült címzettszám,
kvóta-ellenőrzés, **spam-pontszám** és opcionális **inbox placement teszt** (14. fejezet).

---

## 20. Customer Journey — STUDIO

`trigger → condition → action → wait → branch`, perzisztens `journey_runs` kontaktonként, idempotens
lépés-végrehajtás. Triggerek: form-signup, tag, szegmensbe lépés, **API-esemény**, **inbound e-mail**
(ÚJ — a route-motorból), dátum, ventOS CRM-esemény. Akciók: e-mail, tag/attribútum, webhook, wait,
elágazás, journey-váltás. Entitlement: `EnsureEntitlement:journeys`.

---

## 21. Űrlapok & preference center — STUDIO

Embed + hosted űrlap, honeypot + time-trap, GDPR consent-checkbox, **valós idejű címellenőrzés**
(12. fejezet), double opt-in bekötés. Preference center: lista/gyakoriság/teljes leiratkozás.

---

## 22. Marketing analitika — STUDIO

Timescale-alapú ráták és trendek kampány/journey/lista/tag bontásban; klikk-térkép ⚠️; összevetés a
platform-naplóval (ugyanaz az esemény-forrás). Export CSV/API. Privacy-tudatos nyitásmérés (pixel
consent-tudatosan, per-domain kikapcsolható).

---

## 23. Számlázás — ventPay

Metering-dimenziók (bővítve): `sends_counter` · `contacts_peak` · **`validations_counter`** ·
**`inbound_counter`** · **`dedicated_ips`** · **`log_retention_tier`**. Konszolidált számlázás
sub-accountoknál, per-child bontással. Entitlement-gate: `journeys`, `custom_domain`, `dedicated_ip`,
`inbound_routes`, `validation_bulk`. Kártyaadat sosem ér ventMailt.

---

## 24. Biztonság

Auth = ventSSO. API-kulcs hash-elve, scope + workspace-kötés, rotálható. SMTP-hitelesítő külön,
TLS-kötelezettséggel. Webhook HMAC + replay-védelem. Tenant- és **sub-account-izoláció** (teszt-fedett).
Inbound: MIME-parser **sandboxban** (parser-sérülékenységek!), csatolmány ClamAV-szkennelés (ventSec),
méretlimit, zip-bomba védelem. Secret csak env; dev = noindex + Basic Auth. Minden admin-művelet auditált.
**Nyílt relay tilos** — a submission mindig hitelesített.

---

## 25. Adatmodell (a v1.0-hoz képest **új/bővült** félkövéren)

`workspaces` · **`workspace_parents`** · `workspace_members` · `api_keys` · **`smtp_credentials`** ·
`domains` (+bimi, mta_sts, tracking_domain) · `sending_identities` · `ip_pools` · **`ip_addresses`**
(warmup-állapot, reputáció) · `contacts` · `lists` · `segments` · `tags` · `consents` · `suppressions` ·
`templates` (Studio) · **`stored_templates`** + **`stored_template_versions`** · `campaigns` ·
`campaign_variants` · `journeys` · `journey_steps` · `journey_runs` · `messages` (+`tags[]`,
`variables jsonb`, `deliverytime`, `testmode`, `ip_pool_id`) · `message_events` *(Timescale)* ·
**`smtp_transactions`** (trace) · **`inbound_routes`** · **`inbound_messages`** ·
**`validation_results`** · **`validation_jobs`** · `webhooks` · `webhook_deliveries` ·
**`blocklist_checks`** · **`dmarc_reports`** · **`seed_tests`** · `forms` · `preference_centers` ·
`subscriptions` · `usage_records` · `audit_logs` · `translations`

---

## 26. Golden rules (BLOCKING)

1. **ventMail az e-mail egyetlen gazdája** — kimenő ÉS bejövő. Más app nem épít saját SMTP/MX/template
   réteget; ventMail viszont nem épít saját identity/consent-banner/fájltár/számlázó/landing-page réteget.
2. **PreSendGate megkerülhetetlen — az SMTP relayen keresztül is.** Nincs kiskapu.
3. **Nyílt relay tilos.** Minden submission hitelesített és TLS-en.
4. **Marketing csak double opt-in consenttel.** `transactional` ≠ `marketing`; a kampány sosem előzi
   meg a tranzakciós forgalmat a sorban.
5. **Idempotency-Key kötelező** minden küldő POST-on; **webhook HMAC + retry + dead-letter + replay**.
6. **Tenant- ÉS sub-account-izoláció.** Leak = blocking.
7. **`/v1` stabil** — breaking change tilos; OpenAPI ↔ `@venture/sdk` drift = 0.
8. **Deliverability nem alku** — verifikálatlan domainről nincs küldés; SPF/DKIM/DMARC/PTR +
   one-click unsubscribe kötelező.
9. **Inbound MIME-parser sandboxban**, csatolmány szkennelve, méret- és zip-bomba limit.
10. **Napló-retenció betartva** — lejárat után nyers esemény törlődik, csak aggregátum marad.
11. Secret csak env; dev = noindex + Basic Auth; minden admin-művelet auditált.

---

## 27. Roadmap — két sáv egy közös magra

**M0 — Közös mag.** Tenancy+OIDC+sub-account váz · séma+audit · SendingDriver+PreSendGate+FakeDriver ·
domain-hitelesítés+wizard · suppression.
*DoD: hitelesített domainről a FakeDriveren küldött üzenet átmegy a gate-en és „delivered".*

### A sáv — PLATFORM (Mailgun-kiváltás)
- **A1 Messages API:** strukturált + MIME + **batch/recipient-variables** + `o:`/`h:`/`v:` vezérlők + ütemezés + testmode + tárolt sablonok API.
- **A2 SMTP relay + IP-kezelés:** submission 587/465, per-domain hitelesítők, IP-poolok, **warmup-automatika**, rotáció, prioritásos sorok + retry/deferral + **bounce-osztályozó**.
- **A3 Napló & események:** kereshető napló, **üzenet-trace SMTP-párbeszéddel**, retenció, tag-statisztika, Events API, **webhook replay + dead-letter**.
- **A4 Inbound routing:** MX-fogadás, route-motor, MIME-parse + `stripped-text`, csatolmányok, `store()` retencióval, spam/vírus-szűrés, FBL/ARF.
- **A5 Címellenőrzés:** szintaxis/MX/szerep/eldobható/catch-all/szonda/kockázat, egyedi + bulk API, űrlap- és gate-integráció.
- **A6 Kézbesíthetőségi suite:** blocklist-monitor, DMARC RUA ingest + dashboard, seed/inbox placement, spam-pontszám, link-ellenőrzés, MTA-STS/TLS-RPT/BIMI.

### B sáv — STUDIO (Mailchimp-kiváltás)
- **B1 Közönség + consent + űrlapok:** listák/szegmensek/tag-ek, ConsentService double opt-in, űrlapok + preference center, ventOS sync (fake).
- **B2 Block editor + kampányok:** block-tree + MJML, editor UI, kampány-életciklus, célzás, A/B, ütemezés.
- **B3 Journey:** motor + builder UI, inbound- és API-trigger.
- **B4 Marketing analitika:** ráták/trendek/bontások, klikk-térkép, export.

### C sáv — Zárás
- **C1 Számlázás + sub-accountok:** ventPay metering (6 dimenzió), entitlement-gate, konszolidált számlázás.
- **C2 Super-admin + i18n + végső gate:** operátori konzol, HU/EN/DE/IT/ES/RU, teljes gate-sor.

**Halasztva v1.x-re:** SMS/push · AI tárgysor-generálás (Clave AI réteg) · AMP for Email ⚠️ · mobil SDK.
*(Az inbound routing NEM halasztott többé — A4.)*

---

## 28. Kiváltás-térkép

| Mailgun-képesség | Nálunk | Mailchimp-képesség | Nálunk |
|---|---|---|---|
| Sending API + batch + MIME | A1 | Közönség + szegmensek | B1 |
| SMTP relay | A2 | Double opt-in + űrlapok | B1 |
| Dedikált IP + warmup | A2 | Block editor | B2 |
| Logs + üzenet-trace | A3 | Kampány + A/B | B2 |
| Webhookok (replay, DLQ) | A3 | Journey/automatizálás | B3 |
| **Inbound routes** | A4 | Marketing analitika | B4 |
| Email validation | A5 | Landing page | **ventStudio** |
| Deliverability tooling | A6 | Social/ads | **ventPost** |
| Sub-accountok | C1 | E-commerce ajánlók | ⚠️ nyitott |
