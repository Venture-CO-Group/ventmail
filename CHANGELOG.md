# Changelog — ventMail

Formátum: [Keep a Changelog](https://keepachangelog.com/hu/1.1.0/). Verziózás: a kiadás tagje `vMAJOR.MINOR.PATCH`.
A részletes döntési nyomvonal: `docs/plans/v1.1.md` (D1–D300).

## [Unreleased]

## [1.4.7] — 2026-10-22 (PHP 8.3.36 biztonsági javítás)

### Security
- **PHP 8.3.33 → 8.3.36** (`php:8.3.36-fpm-alpine3.24`, index digest `sha256:aaf1ad25fc6484613be770743d1038e98e3e7e07409d78b97410eccdf3628f75`): a PHP2Shell (CVE-2026-109618, CVE-2026-109619, CVSS 9.2, hitelesítés nélküli RCE) javítása. Más változás nincs.

### Telepítés
- `./deploy.sh v1.4.7`; konténerenként `php -v` / `php-fpm -v` = 8.3.36.

## [1.4.6] — 2026-10-21 (Vemoa előleg-link)

### Added
- **Vemoa sablonkészlet:** `deposit-payment-request` (BL-34, előleg-link a kézzel rögzített foglaláshoz; az időpontot a befizetésig tartjuk, utána a foglalás elmarad), hu + en. `docs/integration/manifests/vemoa.json` frissítve.

### Changed
- **Vemoa sablonok lábléce:** az üzemeltető minden Vemoa-levélben a Venture CO Group Kft. (címmel, adószámmal) — a Vemoa #170 export-szinkronja.

### Telepítés
- `ops ventmail:templates:import venture vemoa`, utána a Vemoa `VEMOA_MAIL_PENDING_TEMPLATES` listájából a `deposit-payment-request` törölhető.

## [1.4.5] — 2026-10-19 (Vemoa fennmaradó összeg)

### Added
- **Vemoa sablonkészlet:** `balance-payment-request` (BL-60, fizetési link a látogatás fennmaradó összegére, 24 óráig él) és `balance-receipt` (BL-60, nyugta a mentett kártya terheléséről), hu + en. `docs/integration/manifests/vemoa.json` frissítve.

### Telepítés
- `ops ventmail:templates:import venture vemoa`, a Vemoa BL-60 kiadása előtt.

## [1.4.4] — 2026-10-18 (Vemoa szolgáltatói értesítések)

### Added
- **Vemoa sablonkészlet:** `provider-new-booking` (P2, új foglalás) és `provider-daily-summary` (P4, esti összefoglaló), hu + en. `docs/integration/manifests/vemoa.json` frissítve.

### Telepítés
- `ops ventmail:templates:import venture vemoa`, a Vemoa v0.8.4 előtt.

## [1.4.3] — 2026-10-18 (Vemoa kereskedői adatmódosítás)

### Added
- **Vemoa sablonkészlet:** `provider-trader-change-accepted` (S2) és `provider-trader-change-declined` (S3), hu + en — a szuperadmin elbírálta a szolgáltató kereskedői adatmódosítási kérelmét. `docs/integration/manifests/vemoa.json` frissítve.

### Telepítés
- `ops ventmail:templates:import venture vemoa`, a Vemoa v0.8.3 előtt.

## [1.4.2] — 2026-10-17 (Vemoa áthelyezés és szolgáltatói lemondás)

### Added
- **Vemoa sablonkészlet:** négy új kulcs, hu + en — `reschedule-declined` (a szolgáltató elutasította a vendég áthelyezési kérését), `provider-booking-rescheduled` (a vendég áthelyezte a foglalását), `provider-reschedule-requested` (a vendég áthelyezést kér), `booking-cancelled-by-provider` (a szolgáltató lemondta, indoklással). `docs/integration/manifests/vemoa.json` frissítve a Vemoa szerződéséből.

### Telepítés
- `ops ventmail:templates:import venture vemoa`, utána a Vemoa `VEMOA_MAIL_PENDING_TEMPLATES` listája üríthető.

## [1.4.1] — 2026-10-16 (Vemoa redesign e-mailek)

### Changed
- **Vemoa sablonkészlet** (`resources/mail-templates/vemoa/`): a Vemoa 2026-os design-sablonjai — új verzió a `booking-confirmation-request`, `booking_confirmation`, `reminder_24h`, `review_request`, `provider-approved`, `provider-suspended` kulcsra, új kulcs a `booking-rescheduled`, `booking-cancelled`, `provider-booking-cancelled`, `provider-new-review`; hu + en. Lapos, aláhúzásos merge-tagek (`booking_time`), szöveges logó, beégetett link nélkül. A felfüggesztési levél a kifogást a `support@vemoa.hu` címre kéri (P2B 11. cikk). `docs/integration/manifests/vemoa.json` a Vemoa szerződésének másolata.

### Telepítés
- Előbb a Vemoa ≥ v0.7.0 (mindkét változónév-készletet küldi), utána `ops ventmail:templates:import venture vemoa`; a négy új kulcsot a Vemoa `VEMOA_MAIL_REDESIGN_LIVE` kapcsolója az import után engedi.

## [1.4.0] — 2026-10-16 (családi bekötés)

### Added
- **Családi sablonkészletek** (`resources/mail-templates/{ventsso,ventsec,ventcookie,vemoa}`, hu + en, `.html`/`.txt`/`.subject`), manifesztek `docs/integration/manifests/`, `FamilyTemplateSetsTest` (foglalt változónév-tilalommal).
- **`unsubscribed` esemény + v: változók az eseményekben (FO18).** Az egykattintásos leiratkozás `unsubscribed` eseményt ír (üzenet + címzett párosonként egyszer, webhookra megy); a Postal `delivered`/`deferred` és a `bounced` esemény a hívó `v:` változóit is viszi (Mailgun-paritás, a kliens ebből korrelál).
- **Family onboarding (FO1–FO13, `docs/plans/family-onboarding.md`).**
  - Tárolt sablonverzió **tárgya** (nullable `subject`): `ventmail:templates:import` olvassa a `<név>.subject` fájlt; ha a küldési kérés nem ad tárgyat, a sablon tárgya megy, `{{ var }}` helyőrzőkkel (sima szöveg, CR/LF nélkül, a törzsével azonos változó-szemantika); a kérés tárgya mindig nyer. A felületen a verziók tárgya látszik, és új verzió hozható létre tárggyal (7 nyelv). `/v1` változatlan.
  - **ventSec**: `VentSecScanner` (`INBOUND_SCANNER=clamav|ventsec`, alap `clamav`) — Ed25519-ellenőrzött, fail-closed ítélet (a ventSec független vektorán mérve), m2m token a ventSSO-tól, kulcs-cache + rotáció; VSES eseménykibocsátó (`VENTSEC_EVENTS_ENABLED=false` alapból), `ventsec:heartbeat` percenként, `inbound.attachment_verdict` és `smtp.auth_failed` események. Éles boot-guard hiányos konfigurációra.
  - **ventCookie**: e-mail-consent főkönyv kliens (`CONSENT_LEDGER_DRIVER=fake|ventcookie`, alap `fake`) — a double opt-in kérése, megerősítése és a visszavonás/leiratkozás bizonyítéka commit után sorba állított jobbal; HMAC-elt alany-hivatkozás, nyers cím/IP/UA nélkül; a nyugta a `consents.ledger_receipt_id` oszlopra kerül. A helyi consent marad a hivatalos, a főkönyv-hiba nem blokkol.
  - **Idempotency (kliens-szerződés):** egy még feldolgozás alatt álló kérés ismétlése 409 `IN_PROGRESS` (+ `Retry-After`) — ugyanazzal a kulccsal újra kell próbálni; ez nem az `IDEMPOTENCY_KEY_REUSED`. Deploy után egyszer: `php artisan sending:release-transient-keys`.
  - **Idempotency:** a kapu átmeneti elutasítása (SENDING_RESTRICTED, RATE_LIMITED, QUOTA_EXCEEDED, DOMAIN_UNVERIFIED, CHANNEL_DISABLED, GATE_ERROR) nem köti le az `Idempotency-Key`-t: az ismételt kérés újra átmegy a kapun (a végleges elutasítás továbbra is visszajátszódik).

### Fixed
- **GDPR: a Google Fonts CDN kivezetve.** A látogatók IP-je a hozzájárulás előtt a Google-hez ment (az app-, landing- és hosztolt oldalakról). A betűk (Inter, Space Grotesk, JetBrains Mono) mostantól a saját originről jönnek (`@venture/design/fonts.css`, 0.4.0, OFL-licencekkel, a szövegek a `public/fonts/LICENSE-*.txt` alól szolgálódnak); a két fő vágás preload (`App\Support\FontPreload`). A CSP-ből a `fonts.googleapis.com` és a `fonts.gstatic.com` törölve (`font-src`/`style-src` csak `'self'`). Őr-tesztek: renderelt HTML, CSP és a `resources/` + `docker/` forrásai; Playwright: a betűk betöltődnek, Google-kérés nincs.

## [1.2.0] — 2026-10-14 (ventMail v1.1)

> A kiadás tagje `v1.2.0`, a terv/prompt neve „v1.1": a `v1.1.0` tag már élesben fut, kiadott tag nem írható át (D1).

### Added

**Felület és profil**
- Felső navigáció (Platform / Studio / Settings csoportok), workspace-váltó, avatar-menü (monogram), nyelvválasztó; mobilon harmonika. Új workspace létrehozása (`POST /workspaces`, plafon: 20 birtokolt workspace).
- Profil-beállítások: időzóna, dátum- és számformátum, értesítések, alapértelmezett workspace. A nyelv első belépéskor a ventSSO `locale` claimből jön. Személyes adat, jelszó, 2FA: a ventSSO fiókoldalán.
- Francia nyelv (hu, en, de, fr, it, es, ru). Minden szám, dátum és gépi kód (trace-esemény, kapunév, állapot) a profil formátumában és nyelvén jelenik meg.
- Közös űrlap-komponensek (`VmForm` család) és átfedés-kapu (`e2e/layout-overlap.spec.ts`, 1440/1024/390 px).
- Áttekintő oldal: KPI-sor sparkline-nal, legutóbbi kampányok, kézbesíthetőség-kártya, teendők; a listák és szegmensek szerveroldali lapozással és kereséssel.

**Studio**
- Kontakt-import: kézi, CSV és XLSX (300 000 soros fájlig), elválasztó- és kódolás-felismerés (UTF-8, Windows-1250), előnézet, hibariport, jogalap + forrás + dátum kötelező; suppression-találat sosem éled fel.
- Sablonszerkesztő: drag-and-drop (egér, érintés, billentyűzet), új blokkok és layoutok, feltételes blokk, stílusok, linkellenőrzés, optimalizáló, verziótörténet (visszaállítással), sablonkönyvtár, teszt-küldés, webes változat (`/w/{token}`), képfeltöltés (újrakódolt, workspace-kvótával).
- Szegmensépítő (csoportok, 3 szint, 50 szabály), címkék, tömeges címkézés, engagement-szűrők, közönség-dashboard.
- Kampány: checklist, időzóna-tudatos ütemezés, A/B (tárgy, tartalom, feladó), preheader, célzás kereséssel és lapozással, címzett-választó chipekkel.
- Űrlapok és preference center (név és egyéni mezők), hosztolt oldalak (`/f`, `/c`, `/p`) süti és script nélkül.
- Journey vászon (`@vue-flow`): verziózott gráf, elágazás, várakozás, cél, webhook-csomópont, évfordulós trigger, teszt-futtatás, statisztika, új `journey.publish` képesség.

**Platform**
- Domainek: Return-Path ellenőrzés, részletpanel, DKIM-rotáció.
- API-kulcsok: IP-allowlist, helyes `validate:run` scope; `GET/POST/DELETE /v1/suppressions` (additív).
- Webhookok: eseményszerkesztő, titok-rotáció 24 órás türelmi ablakkal (`X-VentMail-Signature-Previous`), teszt-esemény, kézbesítési napló szűrőkkel.
- Napló: Message-ID, idővonal, CSV-export (alapból maszkolt címzett; teljes címhez `logs.export_pii` képesség).
- Suppression: külön listák, kötelező indoklás törléskor; panasz/leiratkozás visszavonhatatlan.
- Domain-statisztika (`domain_daily_stats`), infrastruktúra-nézet platform-adminoknak, appon belüli fejlesztői dokumentáció az OpenAPI-ból.

**Analitika**
- ECharts grafikonok, PNG-export minden grafikonon, „Top linkek CSV", levelezőkliens-bontás (saját osztályozó, nyers user-agent nem tárolódik).
- PDF-riport (Gotenberg) kampányra és időszakra, workspace-branding (logó PNG/JPEG/WebP ≤ 1 MB, színek), aláírt, 10 percig érvényes letöltőlink, a fájl 7 napig él.

**Fejlesztői eszközök**
- `npm run test:unit` (vitest 4.1.11 + happy-dom 20.14.5), `npm run screenshots` (app és mockup képernyőképek: `docs/screenshots/v1.1/`).

### Changed
- A kampány-indítás külön sorra és külön Horizon supervisorra került (`campaign-start`); a PDF-riport is (`reports`). A Horizon környezetenként explicit supervisor-listát kap (production: sending, background, campaign-start, reports; imports: imports, imports-inspect).
- Az import külön `worker-imports` konténerben fut (3 GB), a felmérés külön `imports-inspect` sorban; az XLSX-olvasás izolált gyerekfolyamatban, kemény memória- és időkorláttal.
- Idempotency-Key fenntartott előtagok a `/v1`-en (`campaign:`, `journey:`, `template-test:`, `invite:`, `optin:`, `smtp:`, `inbound-fwd-`, `dev-seed-`) → `400 IDEMPOTENCY_KEY_RESERVED` (OpenAPI dokumentálja).
- Aktív kampányhoz vagy journey-hez kötött sablon módosítása `template.publish` jogot kér.
- A bounce-ok a suppression listában egységesen `bounce` okkal (hard/soft a `detail` mezőben; a régi `hard_bounce`/`soft_bounce` olvasáskor normalizálódik; OpenAPI: additív `detail`).
- Composer `process-timeout` 900 s (a soros tesztsorozat meghaladta a 300 s-ot).
- A kontakt-import kérés-limitje: 140 MB az import-útvonalakon, 16 MB máshol.

### Fixed
- A kampány indítása a felületről nem hozott létre címzetteket (`StartCampaign`).
- A preference center mentése nem iratkoztatta le a lista nélküli kontaktot.
- API-kulcs scope: a felület `validate`-et adott, a route `validate:run`-t várt.
- A kampány-oldal variáns-hibája (`variant.variant` → `label`).
- Duplikált listanév 500-as hiba helyett validációs hibát ad.
- `Accept-Language` feldolgozás (a Symfony egyezés híján az első elemet adta).
- Mobil import-lépésjelző, DNS-tábla csonkolása, journey-paletta feliratok, tördelő sávok mobilon.

### Security
- Import: zip-bomba és XML-bomba védelem, DTD/entitás tiltás, kódolás- és bináris-hely ellenőrzés; hibaüzenet és `failed_jobs` nem szivárogtat PII-t (`RedactQueryException`, redaktáló failed-job tároló); CSV-képletinjekció elleni védelem.
- Verified-consent import után a `consents` sor `pending` marad (marketing consent nélkül nincs marketing-küldés).
- Hosztolt oldalak és a webes változat szigorú CSP-vel, sütik nélkül; PreSendGate a teszt-küldésen is.
- SMTP submission újra-ellenőrzi a hitelesítőt (visszavont/rotált → `421`); API-kulcs IP-allowlist ugyanazt a `401`-et adja, mint a rossz kulcs (nincs orákulum).
- Platform-szintű tiltás sosem látszik a `/v1/suppressions` végpontokon; napló-export alapból maszkolt.
- PDF-renderer hálózat nélküli, read-only, `cap_drop ALL` konténerben; éles környezetben a `fake` renderer az indulást állítja meg (fail-closed).
- Kampány-életciklus és sablon-zárak (`FOR UPDATE`/`FOR SHARE`, CAS) a versenyhelyzetek ellen; köteg-számlálás címzettenként legfeljebb egyszer.

### Deploy
- **Megosztott fájltár:** két külső volume (`ventmail-storage`, `ventmail-storage-public`) az app/worker/worker-imports/scheduler/ops konténerekben; a `web` csak a public részt látja, csak olvasva. Enélkül az import, a sablon-képek és a PDF élesben nem működne (D227).
- **Első deploy átállása:** a `deploy.sh` a régi szín fájljait (sablon-képek, privát fájlok) átmásolja a volume-okra, bájtra ellenőrzi, és csak utána távolítja el a régi színt. Hibánál a régi szín leállítva megmarad, minden további deploy tilos, amíg `./deploy.sh --migrate-storage` le nem fut (D254, D277–D300).
- **Gotenberg render sidecar:** `deploy/compose.render.yml` (`ventmail-render` projekt, `internal` háló, tag + digest pin, 1 GB). Új env: `ANALYTICS_PDF_RENDERER=gotenberg`, `GOTENBERG_URL`, `ANALYTICS_PDF_TIMEOUT`. Deploy után: `php artisan analytics:render-smoke`.
- **Memória:** egy szín 7,75 GB, blue/green átfedéssel +7,75 GB, + Gotenberg 1 GB ≈ 16,5 GB a 31 GB-os boxon (jóváhagyva, D252).
- **Rollback:** régebbi tagre visszalépéskor is a mostani `compose.prod.yml`/`compose.data.yml`/`compose.render.yml` maradjon a boxon; visszaállítás előtt a gráf-alapú journey-ket szüneteltetni kell (lásd `HUMAN-QUEUE.md`).
- Migrációk expand-only (új, nullable vagy alapértékes oszlopok és táblák); a `2026_10_13_140000` migráció a kiadás előtt, helyben bővült (sosem futott éles DB-n).

### Known limitations
- Natív dátummezők (import hozzájárulás-dátuma, napló szűrők) a böngésző formátumát követik, nem a profilét.
- Mobilon a táblák vízszintesen görgetnek (kártyanézet nincs); a kontakttábla egyszerűbb a mockupnál.
- A fejlesztői dokumentációból hiányzik a paramétertábla és a nyelvi kódminta-fülek; az API-referencia szövege csak magyar.
- A PDF-riport rendszerbetűket használ (OFL betűk beágyazása nyitott); a hőtérkép nem kerül a PDF-be.
- Bekötetlen journey-triggerek: `tag_added`, `tag_removed`, `field_changed` („hamarosan", nem publikálható).
- A sablon-képek és a riport-fájlok helyi megosztott volume-on élnek (ventVault-integráció nyitott); nincs avatar-kép.
- Az `@venture/sdk`-ban a suppressions metódusok és a `detail` mező még nincsenek meg.
- A teljes nyitott lista: `HUMAN-QUEUE.md`.

## [1.1.0] — 2026-10-12
- ventSSO-stílusú bejelentkezés, app-shell, statikus termékoldal az apexen (`ventmail.app`).

## [1.0.2] — 2026-10-12
- A szerepkörök az ellenőrzött ventSSO access tokenből jönnek; megszemélyesített (impersonated) munkamenet elutasítva.

## [1.0.1] — 2026-10-12
- Kijelentkezés gomb az app-shellben (Inertia-barát RP-initiated logout).

## [1.0.0] — 2026-10-12
- Első éles kiadás (`mail-ovh-01`).
