# Family onboarding — ventMail oldali API-k (ventSSO · ventSec · ventCookie · Vemoa)

> Ág: `feat/family-onboarding`. Közös szerződés: C1 (sablon-név/nyelv), C3 (ventCookie e-mail-consent),
> C4 (ventSec). Minden tétel teszt előbb (Pest), expand-only séma, `/v1` érintetlen (OpenAPI ↔ SDK drift = 0).
> A `SENDING_LIVE` / marketing-flag / allowlist alapértékekhez nem nyúltunk.

## Döntések

**FO1 — Tárolt sablonverzió tárgya.** A `stored_template_versions` új, nullable `subject` oszlopot kap
(`string(255)`, expand-only). A megváltoztathatatlansági trigger a teljes sort összeveti, ezért a tárgy is
védett: csak új verzióval módosítható. A `/v1/templates` API-t NEM bővítettük (új request-/response-mező
OpenAPI ↔ SDK driftet okozna, és a szerződés szerint additív kell) — a tárgyat az importparancs és a felület állítja.

**FO2 — Import: `<név>.subject`.** `ventmail:templates:import {ws} {set}` az opcionális `<név>.subject` fájl
első nem üres sorát (trimmelve, max 255) a verzió tárgyának olvassa. A tartalom-összehasonlítás a tárgyat is
tartalmazza: változás (törlés is) új AKTÍV verziót hoz, azonos tartalomra nincs írás (idempotens).

**FO3 — Küldési út: a kérés tárgya nyer, a sablon tárgya renderelődik küldéskor.** A `SendMessageController`
sablon-ágán, ha a kérésben nincs `subject` (üres/whitespace is), a verzió tárgya kerül az üzenetre, és az
`options.subject_from_template = true` jelölő kerül mellé. A `MessageDispatcher::renderAll` CSAK jelölt tárgyat
renderel, címzettenként, ugyanazzal a `renderForRecipient`-tel (egy pass, `t:variables` + `v:*` +
`recipient-variables`, fallback) mint a törzset, de `escape: false` (sima szöveg, nincs `&amp;`), és a
CR/LF-et szóközre cseréli (fejléc-injekció ellen). A hívó saját tárgyát továbbra sem renderelzük (a
viselkedés változatlan). A renderelt tárgy a `renderedBodies[$rcpt]['subject']` kulcson utazik
(`Message::subjectFor()`), a `PostalDriver` és az `OutboundMimeBuilder` (DKIM-aláírt MIME) is ezt használja.
SMTP/MIME hívók nem használnak tárolt sablont, ott nincs teendő.
A renderelt tárgy a kampányéval azonos `SubjectSanitizer`-en megy át (C0/DEL/C1/U+2028-9 → szóköz, 255 vágás; a `HeaderSafetyGate` csak
a renderelés előtti tárgyat látja). A `messages.subject` SZÁNDÉKOSAN a RENDERELETLEN sablon-tárgyat tárolja (nincs benne személyes adat/
token); a kimenő tárgy a küldéskor áll elő.
**Tárgy-öröklés:** a `/v1/templates/{name}/versions` (nincs tárgy-mező, nem bővítjük) az új verzióba az AKTÍV verzió tárgyát viszi
tovább, különben egy törzs-frissítés némán elvenné; a felület új-verzió űrlapja az aktív verzió tárgyával van előtöltve
(`selected.activeSubject`).

**FO4 — Sablon-szerkesztés a felületen.** A StoredTemplates oldal verziónként mutatja a tárgyat, és új
„Új verzió" űrlapot kapott (HTML törzs, szöveges változat, tárgy, „mentés után élő"). A tárgy egy sorra
normalizálódik (sortörés → szóköz), max 255. A meglévő verzió tartalma továbbra sem írható. Szerver: `storeVersion`
action + `StoreVersionRequest` (`storedTemplate.manage`). Mind a 7 nyelv (szerver + kliens). A `{{ }}` a
vue-i18n szövegekben nem szerepelhet (`{example}` paraméter).

**FO5 — VentSec modul.** Új modul (`app/Modules/VentSec`, a 25-ös modullista kiegészítése): m2m token,
ítélet-ellenőrzés, VSES események. A `VentSecScanner` az Inbound modulban él (a `VirusScanner` megvalósítása).
A `VentSecServiceProvider` a `bootstrap/providers.php`-ban.

**FO6 — m2m token.** `ServiceTokenProvider`: `client_credentials` a ventSSO token-végpontján (`VENTSEC_TOKEN_URL`,
üresen az OIDC-discovery `token_endpoint`-ja), `client_secret_basic`, `scope` + `resource` paraméter. A token
titkosítva cache-elődik (`Crypt`), TTL = min(`expires_in`, JWT `exp`) − 60 s; 60 s alatti élettartamot nem
cache-elünk. 401-nél a cache eldobódik. https-kényszer (`OIDC_ALLOW_INSECURE` + local/testing kivétel).
Minden hiba `null` → a szkenner fail-closed, az esemény újrapróbál.

**FO7 — Ítélet-ellenőrzés.** `VerdictVerifier` + `Jcs` + `VerdictKeyStore`: Ed25519 (sodium) a
`ventsec.scan-verdict.v1\n` ‖ JCS(aláírt mezők) üzeneten; az aláírt mezőkészlet a ventSec vektor
`signed_fields`-je (a `ref` null esetén kimarad; `cached`/`stale` nem aláírt). A JCS float/I-JSON-on kívüli
egészre kivételt dob (soha nem kerekít) → az ellenőrzés `false`. A ventSec független vektorai
(`tests/Fixtures/ventsec/{scan-verdict,jcs,ingest-signature}.json`, `catalog.yaml`) másolva; a ventSec repóhoz nem nyúltunk.
Kulcsok: `/.well-known/ventsec-verdict-keys.json` (JWK OKP/Ed25519; a `kid` a kulcs SHA-256 első 16 hex jegye,
különben a bejegyzés elmarad), cache 300 s, ismeretlen `kid`-nél legfeljebb percenként egy újratöltés (rotáció).

**FO8 — `VentSecScanner` (fail-closed).** `POST {VENTSEC_SCAN_URL}/scan/v1/files`, `application/octet-stream`,
fejlécek: `X-VentSec-Profile: mail-inbound`, `X-VentSec-Declared-Ext` (csak a kiterjesztés, `^[a-z0-9]{1,16}$`,
különben nincs), `X-VentSec-Ref: ventmail:<sha256 első 32>`, `Idempotency-Key: vm-scan-<sha256>`. A fájlnév
SEHOL nem megy át. Az ítélet csak akkor `clean`/`infected`, ha az aláírás érvényes ÉS `sha256`/`size`/`ref`/`profile`
egyezik a küldöttel (visszajátszás ellen). `binary_verdict`: clean → `clean()`, infected → `infected(<szignatúra>)`,
minden más (timeout, nem-200, rossz/hiányzó aláírás, ismeretlen kulcs, `unavailable`, ismeretlen érték, 25 MB
fölött — nem is küldjük) → `unavailable()`. A `ScanResult` nullable `scanId`-t kapott (additív; az események korrelációjához).

**FO9 — Kapcsoló és éles guard.** `INBOUND_SCANNER=clamav|ventsec` (alap `clamav`, a ClamAV és a konténer
érintetlen, a kivezetés külön HUMAN-QUEUE #8). Ismeretlen érték mindig hiba; `ventsec` mellett hiányzó
`VENTSEC_CLIENT_ID/SECRET/SCAN_URL` production/staging alatt boot-hiba (`InboundServiceProvider::scannerDriver()`).

**FO10 — VSES események.** `VentSecEvents` (interfész) + `HttpVentSecEvents` + `FakeVentSecEvents`.
`EventFactory`: a ventSec producer-sémája szerint (zárt mezők, ULID, ms-os UTC, `source.env` az APP_ENV-ből,
rendszer-aktor, nincs network/target); a `VsesCatalog` a katalógus ventmail-szekciójának + `ventsec.heartbeat`
zárt kontextus-allowlistje, drift-teszttel a ventSec `catalog.yaml` másolatához. Aláírás: `X-VentSec-Signature:
sha256=<HMAC-SHA256(nyers törzs, forrás-titok SZÖVEGKÉNT)>` — a ventSec ingest-vektorain mérve. Küldés: `default`
queue-ra tett `SendVentSecEvent` job (6 próba, backoff 10 s…1 h); újrapróbálható: hálózat, 5xx, 429, 401 (token
eldobása után), hiányzó token; végleges: egyéb 4xx (pl. `403 ingest_gated`, amíg a ventSec prod ingest zárva).
`VENTSEC_EVENTS_ENABLED=false` alapból: semmi nem megy ki. Szívverés: `ventsec:heartbeat` percenként
(`routes/console.php`, `->when(enabled)`), a szigorú heartbeat-alakkal (`outbox_depth`/`outbox_oldest_seconds` = 0).
A `default` queue a Horizon `background` supervisorán fut (a `background` NEM queue-név).
Éles boot-guard: bekapcsolt események hiányos konfigurációval production/staging alatt boot-hiba.

**FO11 — Kibocsátási pontok.** `inbound.attachment_verdict` (`ParseInboundMessage`, a szkennelés után:
clean→success, infected→denied, egyéb→unknown; kontextus: `binary_verdict`, `scan_id` — fájlnév/hash/tartalom soha)
és `smtp.auth_failed` (`CredentialAuthenticator`, minden sikertelen ág: `plaintext_channel`,
`invalid_credentials` [ismeretlen név és rossz jelszó azonos], `credential_unusable`; felhasználónév/jelszó/IP soha).
Mindkét kibocsátás try/catch-ben: a folyamatot nem blokkolja. NINCS kibocsátva: `delivery.bounce_wave` —
**TODO ventSec:** nincs bounce-hullám detektor; a katalógus-bejegyzés megvan, a bekötés külön feladat.
**TODO ventSec:** tartós outbox (az SDK-é) — addig az újrapróbálás a queue-é, a tartós hiba a `failed_jobs`-ban (nem PII).
Ismert kockázat: az SMTP-auth brute-force minden próbálkozásra egy job — az `AuthThrottle` korlátozza, a ventSec
oldali dedup/rate-limit a detekció dolga.

**FO12 — ventCookie e-mail-consent főkönyv (C3).** `ConsentProofLedger` (Consent modul) + `HttpConsentProofLedger`
+ `FakeConsentProofLedger`; `CONSENT_LEDGER_DRIVER=fake|ventcookie` (alap `fake`; ismeretlen érték hiba; production/staging
alatt `ventcookie` hiányos konfigurációval boot-hiba). A `ConsentLedgerReporter` a `ConsentRecorder` (a consent egyetlen
írási pontja) írásai után, tranzakció-commit UTÁN (`DB::afterCommit` + saját try/catch → szinkron queue mellett sem
szivárog a hiba) tesz `SendConsentProof` jobot a `default` sorra (6 próba, backoff 30 s…1 h).
- Műveletek: `request()` → `opt_in_requested` (method `double_opt_in`); `confirm()` → `opt_in_confirmed`
  (`requested_at` + `confirmed_at`); `revoke()` → `withdrawn` (method: `preference_center` | `unsubscribe_link` | `api`).
- **Egykattintásos leiratkozás** (`UnsubscribeController`): a helyi consent-sort NEM írja át (a suppression a hivatalos
  tiltás), de a ventCookie főkönyvbe `withdrawn`/`unsubscribe_link` bizonyíték megy, ha van marketing-consent a címhez
  (megerősített, vagy nem importált kérés). Az Idempotency-Key az üzenet-uuid-ból képződik: a levélkliens újrapróbálása azonos kulcsot kap.
- **Import NEM kerül a főkönyvbe**: az import-consent nálunk `pending` (nem megerősített hozzájárulás), és `opt_in_requested`
  hamis állítás lenne (nem DOI-kérés); 300 000 soros importnál 300 000 job is lenne. **Tulajdonosi döntést kér** (lásd riport):
  ha az importált, jogalappal rendelkező hozzájárulást is rögzíteni kell, az `importedBatch` kapjon kötegelt
  (nem soronkénti) ledger-útvonalat és `method=import` bizonyítékot.
- `subject_ref = HMAC-SHA256(kisbetűs, vágott e-mail, CONSENT_LEDGER_SUBJECT_KEY)`; `scope_ref = ws:<workspace uuid>/channel:marketing`
  (a consent csatornánként él, nem listánként); `evidence.source_ref = consent:<consent uuid>` (átlátszatlan);
  `consent_text_version` = `policy_version` (max 40). Nyers cím, IP, UA SOHA — teszttel mérve (a törzs és a fejlécek
  nem tartalmazzák), a job sem hordoz nyers címet.
- **Nyugta**: `consents.ledger_receipt_id` (+ `ledger_receipt_at` = a művelet időpontja, hogy a késve érkező régebbi nyugta
  ne írja felül az újabbat); expand-only, nullable; az írás auditált (Auditable). A fake nyugtája `durable=false`, nem tárolódik.
- Hiba-kezelés: 201/200 → nyugta; 409 → lezárt (a bizonyíték már ott van); egyéb 4xx → végleges, naplózva; hálózat,
  5xx, 429, 401/403/408 → újrapróbál. A főkönyv-hiba soha nem blokkol; a küldési kapu a helyi consentből dönt (teszt).
- Hiányos konfiguráció: nem küld, naplóz (`consent.ledger.not_configured`).

**FO17 — Sablonos küldés ujjlenyomata.** Az idempotencia-ujjlenyomat sablonos kérésnél a hívó által küldött tartalmat fedi (sablon-NÉV, `t:variables`, címzettek, feladó, csatorna, opciók; a kérés saját tárgya), NEM a feloldott törzset/sablon-tárgyat. Így egy közben aktivált új verzió után a válasz-vesztéses újrapróbálás replay (202), nem 409. Sablon nélküli kérésnél semmi nem változik (a `template` kulcs csak sablonos kérésnél kerül az ujjlenyomatba).

**FO14 — Átmeneti elutasítás nem köti le az Idempotency-Key-t (B2).** A `GateReason::isTransient()` (DOMAIN_UNVERIFIED,
QUOTA_EXCEEDED, RATE_LIMITED, SENDING_RESTRICTED, CHANNEL_DISABLED, GATE_ERROR) esetén a `SendMessage` az elutasított üzenet
`idempotency_key`-ét NULL-ra állítja (a sor a naplóban marad, `rejected` + okkal): az ugyanazzal a kulccsal és törzzsel ismételt
kérés új üzenetként újra átmegy a kapun, és nem kap sem replay-422-t, sem 409-et. A kérés-függő okok (SUPPRESSED, NO_CONSENT,
INVALID_REQUEST, VALIDATION_RISK) továbbra is kötöttek és visszajátszódnak; az elfogadott küldés kulcsa a unique kényszer miatt a
párhuzamos duplikátumot továbbra is deduplikálja. Séma-változás nincs. A Gate által elfogadott, de az újrakiértékelésen (ütemezett)
elutasított üzenet útja nem érintett.

**FO15 — ventSec-szigorítások.** Az `Idempotency-Key` csatolmány-példányonként `vm-scan-<levél-uuid>-<sorszám>` (a `VirusScanner::scan`
kapott egy opcionális `$reference` paramétert; hivatkozás nélkül hívásonként új uuid) — nincs elavult-ítélet replay és kiterjesztés-váltási
422. Frissességi határ az aláírt `scanned_at`-on (`VENTSEC_VERDICT_MAX_AGE`, alap 600 s; +60 s jövőbeli óra-eltérés tűrve). **Tulajdonosi
döntés (naplózva):** `stale_signatures=true` + `clean` → `unavailable` (fail-closed, mail-inbound); a fertőzött ítélet így is fertőzött.
Opcionális kulcs-rögzítés `VENTSEC_VERDICT_KEY_PINS` (kid-ek). A kulcs-letöltés hibája után 30 s hűtés. A m2m token cache-kulcsa a
kliens-azonosítót/scope-ot/resource-t is tartalmazza. A csatolmány-ciklus teljes szkennelési időkerete `INBOUND_SCAN_BUDGET_SECONDS`
(alap 120 s): kimerülés után a hátralévők `unavailable`-ek; a szkennelési időkorlát alapja 20 s.

**FO16 — Consent főkönyv szigorítások.** `subject_ref = HMAC-SHA256("<workspace uuid>|<kisbetűs, vágott e-mail>", CONSENT_LEDGER_SUBJECT_KEY)`
— a workspace-hez kötött (tenantok között nem kapcsolható össze). **Ez eltér a C3 szerződés eredeti „HMAC(e-mail)" megfogalmazásától,
tulajdonosi döntés; a ventCookie oldalnak csak annyi a következménye, hogy a ref opaque marad.** Kulcs-rotáció: a rotáció után képzett
hivatkozások nem egyeznek a régiekkel — ugyanaz a cím új alanyként jelenik meg, a régi bizonyítékok a régi kulccsal köthetők; a kulcsot
ezért nem rotáljuk szükség nélkül, és rotáció előtt a ventCookie-val egyeztetni kell. Éles guard: a kulcs legalább 32 bájt. A reporter
teljes törzse try/catch alatt (soha nem gördíti vissza a consent-író tranzakciót); a `scope_ref` a consent saját workspace-éből képződik;
üres (GDPR-törölt) címnél nem jelent; a `consent_text_version` csak `^[A-Za-z0-9._:-]{1,40}$` alakban megy ki. Az egykattintásos
leiratkozás kulcsa tartalmazza az üzenet-uuid-t ÉS a consent állapot-verzióját (utolsó consent-esemény id + `confirmed_at`), így
withdraw → re-optin → withdraw ugyanabból a régi levélből új kulcsot kap, a levélkliens újrapróbálása azonosat. A 409 külön
`consent.ledger.idempotency_conflict` WARNING (PII nélkül). A nyugta-írás `lockForUpdate`-tel.

**FO13 — Konfig.** Minden kulcs `config/*.php`-ban (`env()` csak ott), `.env.example` és `deploy/init-env.sh` üres/`false`/`fake`
alapértékkel; a titkok (`VENTSEC_CLIENT_SECRET`, `VENTSEC_INGEST_SECRET`, `CONSENT_LEDGER_TOKEN`, `CONSENT_LEDGER_SUBJECT_KEY`)
`set-env.sh`-val érkeznek.

## Teendők a bekapcsoláshoz (nem ebben az ágban)
1. ventSSO: `ventmail-prod` m2m kliens (`ventsec.scan ventsec.ingest`, resource `https://sec.ventureco.app`); ventSec forrás + HMAC-titok.
2. OVH → ventSec hálózati útvonal (K4); `VENTSEC_*` kulcsok `set-env.sh`-val; először `VENTSEC_EVENTS_ENABLED=true` (csak szívverés, amíg a ventSec `INGEST_PROD_LIVE` zárva), majd `INBOUND_SCANNER=ventsec`.
3. ventCookie: `ventcookie:service-token:issue`, site-uuid; `CONSENT_LEDGER_*` kulcsok; `CONSENT_LEDGER_DRIVER=ventcookie`.
4. A ClamAV kivezetése külön PR (HUMAN-QUEUE #8), a ventSec prod élesítése és egy hét zöld után.
