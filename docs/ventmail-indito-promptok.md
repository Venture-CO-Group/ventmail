# ventMail — Claude Code indító promptok v2.0 (packetenként, beilleszthető)

> Minden packet **teljes, beilleszthető** prompt. „Teszt előbb": előbb a **piros tesztek**, aztán az
> implementáció. A gate-eket a venture-kit hookjai kényszerítik; a PR-t ember mergeli.
> Branch: `feat/<packet-id>-<név>` · main-re push tilos · **auto-merge soha**.
>
> A runner a packet-fejléc alatti ``` blokkot olvassa ki — a fejléc formátumát ne írd át.

---

## M0 — Közös mag

### M0-0 · Skeleton + infra + CI → `venture-devops` → `venture-backend`

```
Kontextus: ventMail v2 — a repóban MÉG NINCS Laravel-skeleton (nincs composer.json). Ez a packet
hozza létre a vázat, hogy az M0-1..M0-4 tiszta domain-munka lehessen. Kanonikus Venture stack.
Precedens: ~/Developer/venture/ventvault (Laravel 12 modulmonolit, app/Modules/) és
~/Developer/venture/ventsso (Vite + Inertia + @venture csomagok). NE tervezz újat — kövesd a mintát.
A CLAUDE.md és a .claude/rules/ kötelező. Domain-logikát ebben a packetben NEM írunk.

Feladat (teszt előbb):
1) venture-devops:
   - docker-compose.yml: caddy · app (PHP 8.3-FPM) · db (timescale/timescaledb-ha:pg17) · redis ·
     horizon · reverb · meilisearch. Minden service healthcheckkel, named volume-okkal.
   - docker/Caddyfile (dev: noindex X-Robots-Tag + Basic Auth), .env.example (SECRET NÉLKÜL).
   - .github/workflows/ci.yml — blokkoló kapuk: php artisan test --parallel · pint · vue-tsc ·
     composer audit · npm audit · gitleaks. A CI ugyanazt a timescaledb-ha:pg17 image-et használja.
2) venture-backend:
   - Laravel 12 skeleton (PHP ^8.3), Inertia + Vue 3 + TS + Vite, Pest, Playwright váz.
   - composer: inertiajs/inertia-laravel, laravel/horizon, laravel/reverb, laravel/scout,
     meilisearch/meilisearch-php, league/oauth2-client, firebase/php-jwt (az M0-1 ezekre épít).
   - npm: @venture/design, @venture/ui, @venture/sdk — 'file:../venture-*' linkkel (ventSSO mintája).
   - app/Modules/<Name>/ váz mind a 24 modulra (Identity Tenancy Domains IpPools Sending Events
     Suppression Messages Smtp Inbound Logs Validation Webhooks Deliverability StoredTemplates
     Audience Consent Template Campaign Journey Forms Analytics Billing SuperAdmin Api) — minden
     modulban README.md + ServiceProvider + a szokásos alkönyvtárak.
   - Egy smoke Inertia-oldal, amely @venture/ui komponenst renderel @venture/design tokenekkel.

Elfogadási kritériumok (tesztekkel):
- docker compose up -d után minden service healthy; php artisan test zöld a KONTÉNERBEN (PHP 8.3).
- a smoke oldal betölt, @venture/design tokenek CSS-változóként jelen vannak; vue-tsc hibátlan.
- a CI minden kapuja zöld; gitleaks nem talál titkot; hardcode szín/spacing/font SEHOL.
- mind a 24 modul váza létezik README-vel.

DoD: konténerben zöld teszt · zöld CI · token-tiszta smoke oldal · 24 modulváz · nincs beégetett titok.
Zárás: reviewer + contract-guard → /pr-kapu → PR.
```

### M0-1 · Tenancy + OIDC + sub-account váz → `venture-architect` → `venture-backend`

```
Kontextus: ventMail v2 — cél a Mailgun ÉS a Mailchimp kiváltása. Kanonikus Venture stack.
ventSSO ÉL: valódi OIDC, nem stub. A CLAUDE.md és a .claude/rules/ kötelező.

Feladat (teszt előbb):
1) venture-architect: modulhatárok a Tenancy + Identity modulra, ADR: docs/adr/0001-tenancy-oidc.md.
   Tartalmazza a sub-account modellt (parent workspace ↔ child workspace-ek) és a global scope
   stratégiát. Kódot ne írj.
2) venture-backend, PIROS tesztek először:
   - OIDC login-callback leképezi a resource_access.ventmail.roles-t 6 szerepre
     (owner/admin/developer/editor/author/analyst).
   - global scope: 'B' workspace usere 0 sort lát 'A' adatából.
   - SUB-ACCOUNT izoláció: child-1 usere NEM lát child-2 adatot; a parent lát összesített használatot,
     de a child nyers adatait csak explicit jogosultsággal.
   - policy: developer kezelhet API-kulcsot/SMTP-hitelesítőt/webhookot, de kampányt NEM;
     author csak draftot; analyst csak olvas.
3) Implementáció: Identity (OIDC client 'ventmail'), Tenancy (workspaces, workspace_parents,
   workspace_members, global scope trait, Policy osztályok). Secret csak .env-ben.
4) Tesztek zöldre.

DoD: valódi ventSSO login; tenant- ÉS sub-account izolációs teszt zöld; 6 szerep policy fedve.
Zárás: reviewer + contract-guard → /pr-kapu → PR.
```

### M0-2 · Alap séma + audit → `venture-backend` (+ `venture-db`)

```
Kontextus: adatmodell a spec 25. fejezete szerint (v2 — bővült ESP-táblákkal).

Teszt előbb:
- schema-assert: workspaces, workspace_parents, workspace_members, api_keys, smtp_credentials,
  domains (spf/dkim/dmarc/ptr/mta_sts/bimi/tracking_domain), sending_identities, ip_pools,
  ip_addresses (warmup_state, reputation), suppressions, audit_logs.
- message_events Timescale hypertable létrejön és fogad insertet.
- messages tábla tartalmazza: tags[], variables jsonb, deliverytime, testmode, ip_pool_id,
  idempotency_key (unique/workspace).
- minden admin-művelet audit_logs sort ír.
Implementáció: migrációk (uuid PK publikus entitáson, workspace_id + global scope, soft delete ahol
GDPR-releváns), Timescale hypertable, zero-downtime minta. Destruktív migráció TILOS review nélkül.
DoD: migrate zöld dev+CI; hypertable insert; audit-write teszt zöld.
```

### M0-3 · SendingDriver + PreSendGate (6 kapu) + FakeDriver → `venture-backend` (+ `venture-security`)

```
Kontextus: a küldés szíve. A gate MINDEN útvonalra érvényes lesz (REST és SMTP). Security 2. reviewer.

Teszt előbb — a PreSendGate MIND a 6 kapuja külön:
- DOMAIN_UNVERIFIED · SUPPRESSED · NO_CONSENT (marketing) · QUOTA_EXCEEDED · RATE_LIMITED ·
  VALIDATION_RISK (kockázati küszöb felett; a Validation modul még fake).
- transactional NEM blokkolható marketing-leiratkozással.
- idempotens küldés: kétszeri dispatch ugyanarra a message.id-re = EGY küldés.
- FakeDriver determinisztikus 'delivered' eventet ír a message_events-be.
- a gate döntése MINDIG auditált, strukturált reason kóddal.
Implementáció: SendingDriver interfész (send/capabilities/health); LogDriver, FakeDriver,
PostalDriver (config-mögött, inaktív); PreSendGate 6 kapuval; channel mező kötelező
(transactional|marketing); config/sending.php: SENDING_DRIVER=fake.
DoD: FakeDriveren 'delivered'; mind a 6 kapu piros→zöld; idempotencia tesztelt.
Zárás: reviewer + contract-guard + venture-security → /pr-kapu → PR.
```

### M0-4 · Domain-hitelesítés + wizard + suppression → `venture-backend` → `venture-frontend`

```
Teszt előbb:
- wizard generál: SPF include, DKIM selector+kulcspár, DMARC, tracking-domain CNAME.
- DNS-check állapotváltás pending→verified→failed (mock resolver).
- verifikálatlan domainről a PreSendGate elutasít.
- suppression add/list globális + workspace szinten; suppressions:write scope kell.
- DKIM kulcsrotáció régi selector megtartásával (átfedéses időszak).
Implementáció: Domains modul (auth-státusz, selector, tracking domain, MTA-STS/BIMI mezők),
DNS-check job, suppression store. Frontend: wizard KIZÁRÓLAG @venture/ui-ból; ha komponens hiányzik,
JELENTSD. Playwright + mockup screenshot-verify.
DoD: verifikálatlan domain elutasítva; wizard flow zöld; token-tiszta UI.

>>> M0 globális DoD: hitelesített domainről a FakeDriveren küldött üzenet átmegy a 6 kapun és 'delivered'. <<<
```

---

## A sáv — PLATFORM (Mailgun-kiváltás)

### A1 · Messages API → `venture-backend` (+ `venture-security`)

```
Kontextus: ez a Mailgun magja. Teljes paritás a küldési felületen. Spec 8. és 15. fejezet.

Teszt előbb:
- POST /v1/messages strukturált küldés; Idempotency-Key KÖTELEZŐ (hiánya 400); ismételt kulcs =
  ugyanaz a válasz, NINCS dupla küldés.
- POST /v1/messages.mime nyers MIME továbbítása (multipart fájlként), fejlécek megőrizve.
- BATCH: egy hívás max 1000 címzettel + recipient-variables JSON; minden címzett a SAJÁT
  behelyettesítését kapja; 1001 címzett = 400.
- Vezérlők: o:deliverytime (ütemezés, múltbeli idő = 400, >7 nap = 400), o:testmode (naplóz, nem küld),
  o:tag (max 3, 4. = 400), o:tracking / -clicks / -opens, o:dkim, o:require-tls, o:sending-ip-pool.
- h:X-* fejlécek átmennek a levélbe; v:* változók visszajönnek a message_events sorban ÉS a webhookban.
- Tárolt sablon: POST /v1/templates, verziók, active verzió; template= + vars[] küldés; auto-escape
  (XSS-kísérlet escape-elve); hiányzó változó → fallback, nem hiba.
- Minden küldés a PreSendGate-en megy át (integráció M0-3-mal).
Implementáció: Messages + StoredTemplates modul, OpenAPI + @venture/sdk generálás (drift = 0).
DoD: a fenti tesztek zöldek; SDK-drift 0 (contract-guard).
Zárás: reviewer + contract-guard + venture-security → /pr-kapu → PR.
```

### A2 · SMTP relay + IP-poolok + sorok → `venture-backend` (+ `venture-security`, `venture-devops`)

```
Kontextus: a legacy útvonal és a küldési minőség. Security 2. reviewer KÖTELEZŐ (nyílt relay kockázat).

Teszt előbb:
- SMTP submission 587 (STARTTLS) és 465 (TLS): hitelesített felhasználó küldhet.
- NYÍLT RELAY TESZT: hitelesítés nélküli küldési kísérlet ELUTASÍTVA (ez BLOCKING teszt).
- plaintext auth TLS nélkül elutasítva.
- SMTP-n beadott levél ÁTMEGY a PreSendGate 6 kapuján — verifikálatlan domain innen is elutasítva.
- X-VentMail-Tag / -Variables / -Deliverytime / -Testmode fejlécek értelmezve.
- IP-pool: pool→csatorna és pool→domain hozzárendelés; marketing IP nem használható transactionalre.
- Warmup: napi limit betartva, túllépés sorba tesz; reputáció-romlásnál automatikus visszafogás;
  beteg IP → quarantined, nem kap forgalmat.
- Sor-prioritás: telített campaign sor mellett a transactional üzenet ELŐBB megy ki (teszt fake órán).
- Deferral 4xx: exponenciális backoff ütemterv; per-fogadó-domain throttle külön bucketben.
- Bounce-osztályozó: 550 → hard → azonnali suppression; 452 → soft → N próba után suppression;
  blocked/spam/mailbox_full/policy külön kategória.
Implementáció: Smtp modul (submission listener + credentials), IpPools modul (warmup-motor, rotáció,
karantén), Sending sor-prioritás + retry ütemező + BounceClassifier. devops: docker service-ek.
DoD: a nyílt relay teszt és a gate-teszt zöld; warmup + prioritás + bounce-osztályozás tesztelt.
Zárás: reviewer + contract-guard + venture-security → /pr-kapu → PR.
```

### A3 · Napló, üzenet-trace, események, webhookok → `venture-backend` → `venture-frontend`

```
Kontextus: a „miért nem érkezett meg?" funkció. Spec 11. és 13. fejezet.

Teszt előbb:
- kereshető napló: szűrés címzettre, tagre, csatornára, domainre, állapotra, időablakra (Meili+Timescale).
- ÜZENET-TRACE: egy message_id-re visszaadja a teljes láncot — accepted → gate-döntés (mind a 6 kapu
  eredménye) → kiválasztott IP/pool → SMTP-párbeszéd sorai (válaszkódok+szöveg) → delivered/bounced
  okkal → opened/clicked.
- smtp_transactions tábla rögzíti a párbeszédet; bounce esetén a válaszkód és a szöveg is látszik.
- RETENCIÓ: a plan lejárati ideje után a nyers esemény TÖRLŐDIK, de az aggregátum megmarad (teszt:
  idő-ugrás után nyers sor 0, aggregátum változatlan).
- tag-statisztika: per-tag delivered/open/click/bounce arány helyes.
- GET /v1/events: szűrés típusra, címzettre, tagre, v:* változóra; kurzoros lapozás stabil.
- Webhook: eseménytípusonkénti endpoint; HMAC-SHA256 + timestamp; hibás aláírás elutasítva;
  exponenciális retry; N hiba után DEAD-LETTER; DLQ-ból REPLAY újraküld és sikeres esetben kivezet.
Implementáció: Logs + Events + Webhooks modul; frontend napló + trace képernyő @venture/ui-ból.
DoD: trace SMTP-párbeszéddel; retenciós törlés; webhook replay+DLQ; Playwright a napló-flow-ra.
```

### A4 · Inbound routing → `venture-backend` (+ `venture-security` KÖTELEZŐ) → `venture-frontend`

```
Kontextus: MAGFUNKCIÓ (a handoffban korábban halasztott volt — a Mailgun-kiváltás miatt előrehozva).
A MIME-parser támadási felület: venture-security 2. reviewer KÖTELEZŐ. Spec 10. fejezet.
MX még nincs éles → .eml FIXTÚRA-INJEKTOR a teszthez.

Teszt előbb (.eml fixtúrákkal):
- route-motor prioritás szerint: match_recipient / match_header / catch_all; az első stop() lezárja a láncot.
- forward(url): HMAC-SHA256 aláírt JSON POST a spec 10.3 mezőivel; retry + dead-letter.
- forward(email): továbbítás a kimenő motoron (a PreSendGate-en át).
- store(notify): nyers MIME Wasabira, retenció lejáratakor törlődik; API-n lekérhető.
- STRIPPED-TEXT: az idézett előzmény és az aláírás levágva (magyar és angol idézet-minták fixtúrával).
- csatolmány: presigned URL; méretlimit túllépés elutasítva; ZIP-BOMBA elutasítva; ClamAV-fertőzött
  csatolmány karanténba (fake scanner a teszthez).
- parser SANDBOXBAN fut, időkorláttal; beágyazott MIME rekurzió-mélység limitálva; malformált MIME
  nem dönti el a workert.
- tenant-izoláció: a route mindig egy workspace-hez tartozik; idegen workspace levele nem route-olható.
- spam-score és SPF/DKIM eredmény a payloadban.
Implementáció: Inbound modul (SmtpReceiver interfész + FixtureInjector + éles MX-adapter, MimeParser
sandbox, RouteEngine, akciók), frontend útvonal-szerkesztő képernyő.
DoD: fenti tesztek zöldek; a parser sandbox-teszt malformált inputtal is stabil.
Zárás: reviewer + contract-guard + venture-security → /pr-kapu → PR.
```

### A5 · Címellenőrzés → `venture-backend` → `venture-frontend`

```
Kontextus: külön is eladható szolgáltatás. Spec 12. fejezet.

Teszt előbb:
- szintaxis (RFC 5322) · domain+MX · szerepcím (info@/sales@/admin@) · eldobható domain ·
  catch-all felismerés · elgépelés-javaslat (gmial→gmail) · kockázati pontszám és minősítés
  (deliverable/risky/undeliverable/unknown).
- mailbox-szonda FakeProbe-bal determinisztikus; a szonda RATE-LIMITELVE fut és cache-el
  (teszt: 100 kérés ugyanarra a domainre → korlátozott számú valódi szonda).
- POST /v1/validate szinkron válasz; bulk job: CSV feltöltés → aszinkron feldolgozás → export.
- integráció: az űrlap beküldés valós időben ellenőriz; a PreSendGate 6. kapuja a küszöb felett blokkol.
Implementáció: Validation modul (ellenőrző lánc, FakeProbe + éles szonda config-mögött, cache),
bulk job Horizonon, frontend képernyő.
DoD: a 8 ellenőrzés + pontszám tesztelt; bulk export; gate- és űrlap-integráció zöld.
```

### A6 · Kézbesíthetőségi suite → `venture-backend` → `venture-frontend`

```
Kontextus: spec 14. fejezet. Külső szolgáltatások fake-elve.

Teszt előbb:
- blocklist-monitor: FakeBlocklistClient találatra riasztást ír és értesít; tiszta állapot nem riaszt.
- DMARC RUA: minta XML-ek parse-olása (gzip is), forrásonkénti aggregáció, ismeretlen forrás kiemelve.
- seed / inbox placement: seed-stub válaszaiból inbox/spam/promóciók arány riport.
- spam-score küldés előtt: szabály-alapú pontszám + tartalmi javaslatok; link-ellenőrzés jelzi a
  shortenert és a blocklistás domaint.
- one-click unsubscribe (RFC 8058) fejléc jelenléte kikényszerítve marketing levélben.
Implementáció: Deliverability modul + dashboard @venture/ui-ból.
DoD: a fenti tesztek zöldek; a dashboard Playwright-flow zöld.
```

---

## B sáv — STUDIO (Mailchimp-kiváltás)

### B1 · Közönség + consent + űrlapok → `venture-backend` (+ `venture-security` — PII) → `venture-frontend`

```
Teszt előbb:
- lista/tag/group CRUD; SegmentEngine DSL determinisztikus (attribútum + esemény + validation-eredmény);
  Meilisearch contact-index frissül.
- double opt-in E2E: signup → megerősítő transzakciós levél (FakeDriver) → confirmed consent
  (jogalap, forrás, ip_hash, verzió, ts).
- unconfirmed kontakt marketing csatornán PreSendGate-blokk.
- GDPR: export tartalmazza a kontakt+consent+aktivitást; törlés anonimizálja a PII-t, DE a suppression MARAD.
- raw IP nincs tárolva.
- űrlap: honeypot + time-trap kiszűri a botot; valós idejű címellenőrzés (A5, ha kész — különben fake).
- preference center: lista/gyakoriság/leiratkozás; one-click leiratkozás konzisztens a suppressionnel.
- FakeVentureOsSync: kétirányú idempotens upsert external_id-re, last-write-wins + audit.
Implementáció: Audience + Consent + Forms modul; frontend képernyők.
DoD: form→opt-in→CRM-sync zöld; leiratkozás azonnal tükröződik mindkét irányban.
Zárás: reviewer + contract-guard + venture-security → /pr-kapu → PR.
```

### B2 · Block editor + kampányok → `venture-backend` → `venture-frontend` (+ `mockup`)

```
Teszt előbb:
- template a közös block-tree JSON Schema ellen validál (pozitív + negatív esetek).
- block-tree → MJML → reszponzív HTML + plaintext; snapshot stabil.
- merge-tag auto-escape (XSS escape-elve) + fallback.
- marketing sablonban a leiratkozó-blokk KÖTELEZŐ (mentés blokkolva nélküle).
- kampány-életciklus átmenetek; célzás lista+szegmens ∪ mínusz kizárás; becsült címzett helyes.
- A/B (campaign_variants) győztes-kritérium és automata kihirdetés.
- időzóna-tudatos ütemezés; Horizon batch + Reverb progress.
- a kampány a 'campaign' sorba megy (legalacsonyabb prioritás) — a transactional megelőzi.
- publikálás tárolt sablonná (A1 StoredTemplates felé) működik.
Implementáció: Template + Campaign modul; block-editor UI KIZÁRÓLAG @venture/ui-ból; mockup
screenshot-verify hurok.
DoD: snapshot + escape + életciklus + A/B + sor-prioritás zöld; editor Playwright + screenshot zöld.
```

### B3 · Journey → `venture-backend` → `venture-frontend` (+ `mockup`)

```
Teszt előbb:
- trigger→condition→action→wait→branch determinisztikus fake órán; journey_runs perzisztens;
  idempotens lépés (kétszeri tick = egy hatás).
- trigger források: form-signup, tag, szegmensbe lépés, API-esemény, ÉS INBOUND E-MAIL (A4-ből,
  fake eseménnyel ha A4 még nem kész).
- exit: cél elérve / leiratkozás / manuális; EnsureEntitlement:journeys gate (FakeVentPay).
- inbound-trigger csak megerősített kontaktnál indíthat marketing ágat.
Implementáció: Journey modul + builder UI; mockup screenshot-verify.
DoD: többlépéses futás + inbound-trigger + entitlement zöld; builder flow zöld.
```

### B4 · Marketing analitika → `venture-backend` → `venture-frontend`

```
Teszt előbb:
- Timescale aggregátumok kampány/journey/lista/tag bontásban; ráták helyesek.
- a marketing analitika és a platform-napló UGYANARRA az esemény-forrásra épül (konzisztencia-teszt:
  ugyanaz a kampány ugyanazt a delivered-számot adja mindkét felületen).
- export CSV/API; klikk-térkép adatszerkezet.
Implementáció: Analytics modul + dashboard.
DoD: konzisztencia-teszt zöld; dashboard + export zöld.
```

---

## C sáv — Zárás

### C1 · ventPay számlázás + sub-accountok → `venture-backend` (+ `venture-security`)

```
Teszt előbb:
- FakeVentPayClient: subscriptions()->create metering 6 dimenzióval (sends_counter, contacts_peak,
  validations_counter, inbound_counter, dedicated_ips, log_retention_tier).
- entitlement-gate: journeys, custom_domain, dedicated_ip, inbound_routes, validation_bulk;
  a kvóta a PreSendGate 4. kapujába kötve.
- sub-account: konszolidált havi számla a parentnél, PER-CHILD bontással; child nem látja más child
  használatát (izolációs teszt).
- havi zárás → usage-record → dunning-szimuláció (past_due → retry → canceled).
Implementáció: Billing modul; élesedéskor CSAK config vált valós ventPay-re.
DoD: metering + entitlement + sub-account bontás + dunning zöld.
Zárás: reviewer + contract-guard + venture-security (pénzügy) → /pr-kapu → PR.
```

### C2 · Super-admin + i18n + végső gate → `venture-backend` → `venture-frontend`

```
Teszt előbb:
- super-admin: workspace/plan-override, globális suppression, Postal + MX cluster health, IP-pool és
  warmup felügyelet, deliverability-riasztások, audit-kereső, impersonation (auditált).
- i18n kulcs-teljesség HU/EN/DE/IT/ES/RU — hiányzó kulcs = teszt-bukás.
Implementáció: SuperAdmin modul + Vue felület; Vue I18n lazy locale bundle.
DoD: super-admin flow-k + i18n teljesség zöld; a TELJES /pr-kapu átmegy.

>>> APP v1 DoD: minden sáv DoD zöld; main zöld; contract-guard 0 sértés; FakeDriver→PostalDriver csere
    csak config; a spec 28. kiváltás-térkép minden sora kipipálva (Mailgun + Mailchimp felmondható). <<<
```
