# ADR 0001 — SMTP submission listener (587 STARTTLS / 465 implicit TLS)

- **Státusz:** elfogadott (architect-döntés, az A2 packet előfeltétele)
- **Dátum:** 2026-09-02
- **Packet:** M1-A2 · SMTP relay + IP-poolok + sorok
- **Érintett kritériumok:** PHASE.md 11–15 (közvetve 16–21)
- **Kötelező review:** `venture-reviewer` + `venture-security`

## Kontextus

Az A2 packet kimondatlan előfeltétele: **hol fut az a folyamat, ami a 587-es és a 465-ös porton SMTP-t beszél?** A Laravel nem SMTP-szerver, a PHP-FPM request-modellje alkalmatlan hosszan élő, kétirányú socket-párbeszédre.

A meglévő mag a döntést bekeretezte:

- A `PreSendGate::evaluate()` már **fail-closed workspace-kontextusra** és a kapu-hibákra. Használható nem-HTTP belépési pontról — nem kell hozzányúlni.
- A `MessageDispatcher` a `SendingDriver::send()` **egyetlen hívója**, és csak `accepted` üzenetet küld. A `FakeDriver → PostalDriver` csere ezért maradhat config-szintű.
- A `SendMessage` action a küldés belső, HTTP-független belépési pontja, idempotencia-kezeléssel.
- A `MimeParser` létezik és szándékosan csak fejlécet olvas a kimenő úton.
- A `smtp_credentials` tábla **már megvan** (M0-2).

A küldési lánc tehát kész és megkerülhetetlen — az A2-ben már csak egy protokoll-frontendet kell hozzátenni, nem egy második küldési utat.

**Megjegyzés a stackhez.** SMTP-szervert PHP-ben írni nem az a döntés, amit tiszta lapról hoznék: a userland event loop egy szálon fut, a TLS-vezérlés a PHP stream-rétegében korlátozott, és egy 25 MiB-os `DATA` gondos streamelést igényel. A stack a tulajdonos döntése; az architektúra úgy van felszabva, hogy ez később, domain-kód írása nélkül orvosolható (lásd Migrációs kiút).

## Döntés

**Saját, minimális felületű SMTP submission listener a ventMailben, ReactPHP transzporton, külön processzben — a protokoll-állapotgép I/O-mentes, és a submission KIZÁRÓLAG a `SendMessage` actionön juthat be.**

### D1 · Transzport és processz

- `react/socket` + `react/event-loop` (pure PHP, a meglévő image futtatja). **Nem Swoole:** kiterjesztést és külön image-et kérne.
- Belépési pont: `php artisan smtp:serve --port=587|465`. Egy processz egy portot szolgál ki; két compose-service.
- A listener nem web-worker: submissionönként állítja és elfelejti a workspace-kontextust.
- A listener **soha nem küld levelet**: nem ismeri a `SendingDriver`-t, a `MessageDispatcher`-t, az IP-poolt, a DKIM-et. A `DATA` végén `SendMessage`-t hív, a választ SMTP-kódra fordítja.
- **A port 25-öt nem kötheti.** Indulási assertion: a `--port` csak a `config/smtp.php` `allowed_ports` listájából vehet értéket; a 25 explicit tiltott (az A4/Inbound dolga).

### D2 · Modulhatárok — `app/Modules/Smtp`

Publikus: `Contracts\SmtpAuthenticator`, `Data\SubmissionPrincipal`, `Models\SmtpCredential` (+ actionök), `smtp:serve`. Minden más belső (`Protocol\*`, `Transport\*`).

| Réteg | Osztály | Felelősség |
|---|---|---|
| Protokoll (pure) | `Protocol\SmtpSession` | Állapotgép. `feed(string $line): list<SmtpReply>`. Nincs socket, DB, facade, `now()` — injektált óra. PIPELINING miatt több válasz is lehet. |
| Protokoll (pure) | `Protocol\SessionState` (enum) | `Greeting · Ready · Authenticating · Mail · Rcpt · Data · Quit · Closed` |
| Protokoll (pure) | `Protocol\SmtpCommand`, `SmtpReply` | Parse-olt parancs; strukturált válasz (kód + enhanced + szöveg) |
| Protokoll (pure) | `Protocol\ChannelSecurity` | A transzport által ÁTADOTT tény: titkosított-e a csatorna. Az állapotgép nem találja ki. |
| Protokoll (pure) | `Protocol\SmtpLimits` | A D4 számai `config/smtp.php`-ból |
| Hitelesítés | `Contracts\SmtpAuthenticator` | `authenticate(string $username, string $password, ChannelSecurity $security): ?SubmissionPrincipal` |
| Hitelesítés | `Services\CredentialAuthenticator` | `smtp_credentials`, `KeyedHash` `smtp_credential` domainnel |
| Hitelesítés | `Testing\FakeSmtpAuthenticator` | Determinisztikus, teszttel fedett kontraktus |
| Hitelesítés | `Services\AuthThrottle` | Redis brute-force védelem (IP-hash, username), processzek között osztott |
| Bizalmi határ | `Data\SubmissionPrincipal` | A hitelesített session bizonyítéka: `workspaceId`, `credentialUuid`, `domainId`, `requireTls`, `channelRestriction`. **Nem konstruálható hitelesítés nélkül.** |
| Ingest | `Services\SubmissionIngest` | A modul EGYETLEN kijárata: `accept(SubmissionPrincipal $principal, SmtpEnvelope $envelope, string $rawMime): SubmissionOutcome` |
| Ingest | `Services\ControlHeaderMapper` | `X-VentMail-Tag/-Variables/-Deliverytime/-Testmode` → `o:`/`v:` szemantika; a vezérlő fejléceket **levágja** |
| Ingest | `Services\GateReasonToSmtpReply` | `GateReason` → SMTP-válaszkód |
| Transzport | `Transport\StreamSubmissionServer` | Az EGYETLEN osztály, ami socketet lát. Streamel, időkorlátoz, felszámol, TLS-t emel. |
| Üzemeltetés | `Console\ServeSubmissionCommand` | port-assertion, graceful shutdown |
| Adat + UI | `Models\SmtpCredential`, `Actions\Issue/Rotate/RevokeSmtpCredential`, `Http\Controllers\SmtpCredentialController` | Hitelesítő-kezelés, `developer` szerep, auditálva |

NEM kerül a modulba: gate-logika, `SendingDriver`, `MessageDispatcher`, sor-prioritás, IP-pool, DKIM (→ `Sending`/`IpPools`); MIME-fejléc értelmezés (→ meglévő `MimeParser`); **bejövő (MX) parser** (→ `Inbound`, A4 — szándékosan külön osztály: más bizalmi modell); bounce-osztályozás és `smtp_transactions` (→ A3); identity (→ ventSSO — az SMTP-hitelesítő gépi hitelesítő, nem fiók).

### D3 · Bizalmi határ

A hitelesítés egyetlen pontban dől el; négy független záron kell átjutni:

1. **Protokoll-szint.** `MAIL FROM` principal nélkül → `530 5.7.0`; `RCPT`/`DATA` → `503 5.5.1`. Az állapotgépben **nincs átmenet** `Data`-ba principal nélkül.
2. **Típus-szint (a valódi garancia).** A `SubmissionIngest::accept()` első paramétere nem-nullable `SubmissionPrincipal` — hitelesítetlen hívás nem megfogalmazható. A konstruktor a modulra korlátozott: csak az authenticator állítja elő.
3. **Kontextus-szint.** Az ingest a principalból állítja a `WorkspaceContext`-et; ha elmaradna, a `PreSendGate` a meglévő `RuntimeException`-nel elhasal.
4. **Architektúra-szint.** Arch-teszt: `App\Modules\Smtp` nem hivatkozhat `SendingDriver`-re és `MessageDispatcher`-re.

Kiegészítő szabályok: a hitelesítő feloldása `username` alapján `withoutGlobalScope`-pal, ismeretlen usernévnél is **dummy hash-összehasonlítás** (nincs felderítő oracle), `KeyedHash::matches`. A `username` nem választható a felhasználó által (rendszer generálja `vm-<workspace-uuid8>-<random>`), különben „A" tenant lefoglalhatná „B" várható nevét. A `require_tls` oszlop a **kimenő** lábra vonatkozik (`o:require-tls`) — a submissionre MINDIG kell TLS (golden rule 3). A `MAIL FROM` nem bizalmi input: a domain-jogosultságot a `DomainVerifiedGate` dönti el a `From:` alapján, a principal `domainId`-je szűkíti; eltérés → `550 5.7.1`. Az `AUTH` sorok **soha nem kerülnek naplóba** (`AUTH PLAIN <redacted>`).

### D4 · Protokoll-felület és limitek

Hirdetett: `PIPELINING`, `SIZE 26214400`, `8BITMIME`, `ENHANCEDSTATUSCODES`, `STARTTLS` (csak 587), `AUTH PLAIN LOGIN` (**kizárólag titkosított csatornán**).
Nem támogatott: `CHUNKING`/`BDAT`, `DSN`, `SMTPUTF8` (backlog), `CRAM-MD5` (visszafejthető tárolást kérne), `VRFY`/`EXPN` (`252`, illetve `502`).

| Limit | Érték | Viselkedés |
|---|---|---|
| Parancssor max hossza | 512 B (RFC 5321 §4.5.3.1) | `500 5.5.2 Line too long` |
| `AUTH` folytatósor | 12 288 B | a base64 PLAIN/LOGIN nem fér 512-be |
| `DATA` szövegsor | 1 000 B | `500`, tranzakció eldobva |
| Üzenet max mérete | 26 214 400 B = `sending.max_mime_bytes` | `SIZE=` túllépés → `552 5.3.4` azonnal; `DATA` közbeni → `552` + abort |
| Címzett / tranzakció | 1 000 = `sending.max_recipients` | `452 4.5.3` |
| Parancs / session (nem-mail) | 100 | `421 4.7.0` + bontás |
| Tranzakció / session | 50 | `421 4.7.0` + bontás |
| Hitelesítés előtti parancs-büdzsé | 10 | `421 4.7.0` + bontás |
| Sikertelen `AUTH` / session | 3 | `421 4.7.0` + bontás |
| Sikertelen `AUTH` / IP-hash | 10 / 15 perc → tarpit (1 s); 25 → 1 órás tiltás | Redis, osztott |
| Parancs-tétlenség | 300 s | RFC 5321 §4.5.3.2 minimum |
| `DATA` chunkok között | 180 s | lassú-loris ellen |
| `DATA` teljes átvitel | 600 s | |
| Session életkora | 600 s | |
| Egyidejű kapcsolat / processz | 200 | felette `421 4.7.0` (backpressure, nem OOM) |
| Egyidejű kapcsolat / forrás-IP | 10 | |
| Egyidejű kapcsolat / hitelesítő | 5 | |
| Túl hosszú sor / session | 3 alkalom | utána bontás |
| TLS | minimum 1.2 | plaintext auth kísérlet → `538 5.7.11` |

**`STARTTLS` után teljes állapot-reset:** a session eldobja az EHLO-adatokat, a principalt és **minden pufferelt bemenetet** (CVE-2011-0411 család: plaintextben pipeline-ozott parancsok a titkosított munkamenet elé). Nevesített teszteset.
**A hitelesítés nem hirdetése nem elég védelem:** titkosítatlan csatornán az `AUTH` nem szerepel az EHLO-válaszban, ÉS megkísérelve `538 5.7.11`-et kap.

### D5 · Vezérlő fejlécek és idempotencia

- `X-VentMail-Tag` (max 3, a 4. → `550 5.6.0`), `X-VentMail-Variables` (JSON → `v:*`), `X-VentMail-Deliverytime` (múltbeli vagy >7 nap → `550 5.6.0`), `X-VentMail-Testmode`. A mapper ezeket **kivágja** a levélből (bent hagyva a `HeaderSafetyGate` átengedné, és a vezérlés kétszer érvényesülne).
- **Idempotencia SMTP-n:** ha a `250` elveszik, a kliens újraküldi ugyanazt a levelet. Az ingest determinisztikusan származtat kulcsot: `smtp:<credential_uuid>:<Message-ID vagy sha256(boríték + nyers MIME)>` — az újrapróbálás a meglévő idempotencia-ágra fut.
- A `channel` SMTP-n `transactional`. Marketing SMTP-n nincs engedve: consentet SMTP-fejlécből nem lehet bizonyítani (golden rule 4). Kivétel csak explicit, auditált `smtp_credentials.channel_restriction` — a `ConsentGate` akkor is dönt.

### D6 · Adatmodell — csak additív

`smtp_credentials`-hez: `name`, `channel_restriction` (alap `transactional`), `expires_at`, `revoked_at`, `last_ip_hash` (nyers IP nem tárolható — `KeyedHash` `ip` domain), `rotated_from_id`, `rate_limit_per_minute`. Index: `(workspace_id, deleted_at)`. Az `smtp_transactions` trace-tábla az **A3** dolga.

## Alternatívák és miért nem

### (b) Postfix / Haraka frontend, SASL-auth delegálva, LMTP-ingest
A legjobb elvetett alternatíva — ezért ez a **migrációs kiút**. Amiért most nem:
- **Két auth-felület.** A „nyílt relay tilos" bizonyítéka átcsúszik egy `main.cf`-be, amit a `php artisan test` nem lát. Egy elgépelt `smtpd_recipient_restrictions` csendben nyílt relayt csinál — pont az a hibaosztály, ami ellen a 12. kritérium született.
- **Idegen sor a gate előtt.** A Postfix a `250`-et a saját queue-jába írás után adja: átvette a levelet, mielőtt a gate döntött.
- **Üzemeltetési súly** az A2 volumenén még nem térül meg.
- A **bejövő** oldalon (A4, port 25) ez a mérleg másképp áll — ott a Haraka/Postfix + rspamd + ClamAV lánc indokolt.

### (c) Postal submission + ventMail-hook
**Elutasítva, mint konstrukciós gate-megkerülés.** A Postal elfogadja és a saját queue-jába teszi, majd hívja a hookot: a kliens `250`-et kapott, a levél már a sorban van. A 14. kritérium („verifikálatlan domain innen is elutasítva") nem teljesíthető, mert az `550`-et akkor kell adni, amikor a tranzakció még nyitva van. Emellett második identity-forrás (kettős gazda egy képességre), a driver-csere „csak config" garanciája elesik, és a trace hiányos lesz.

### (a-variáns) Swoole ReactPHP helyett
Elutasítva: kiterjesztés, külön image, a php-fpm/Horizon/Reverb konténerekkel osztott Dockerfile szétvágása. A nyereség elhanyagolható ott, ahol a socketet egyetlen osztály látja.

## Következmények

**Kedvező:** a 11–15. kritérium socket nélkül, determinisztikusan tesztelhető; a PreSendGate-hez nem kell hozzányúlni; a driver-csere config marad; kis protokoll-felület; a `GateReason` → SMTP leképezés a legacy kliensnek értelmes viselkedést ad.

| `GateReason` | SMTP-válasz | Miért |
|---|---|---|
| (átment) | `250 2.0.0 Ok: queued as <uuid>` | a `2xx` MOST már azt jelenti, hogy a gate elfogadta |
| `DOMAIN_UNVERIFIED` · `SUPPRESSED` · `NO_CONSENT` | `550 5.7.1` | permanens |
| `VALIDATION_RISK` | `550 5.1.1` | a címzett a probléma |
| `INVALID_REQUEST` | `550 5.6.0` | tartalom/fejléc |
| `QUOTA_EXCEEDED` | `452 4.3.1` | a kvóta megújul |
| `RATE_LIMITED` | `452 4.3.2` | átmeneti |
| `GATE_ERROR` | `451 4.3.0` | fail-closed: inkább késés, mint ellenőrizetlen küldés |
| `IN_PROGRESS` / `IDEMPOTENCY_KEY_REUSED` | `451 4.3.0` | nincs végleges döntés |

**Vállalt:** saját protokoll-implementációt tartunk fenn (enyhítés: szűk felület + transcript-fixtúrák valódi kliensekből); új composer-függőség (`react/socket`, `react/event-loop`, `react/stream`); egyszálas event loop (streamelt `DATA`, kapcsolat-cap, vízszintes skálázás).

**Üzemeltetés (devops-alpacket):** két compose-service (`smtp-submission` 587, `smtp-tls` 465) a meglévő image-ből; dev `127.0.0.1:2587`/`2465` (nem privilegizált port), prod valódi portok `CAP_NET_BIND_SERVICE`-szel; tanúsítvány `smtp.ventmail.app`-ra, a Caddy **nem** terminálja az SMTP TLS-t, a listener read-only volume-ról olvassa és cert-mtime figyeléssel újratölti; healthcheck egy EHLO/QUIT **transcriptet** játszik le (nem `nc -z`); skálázás N replikával TCP LB mögött **PROXY protocol**-lal (különben a throttle a balancer IP-jét látja), a throttle-állapot Redisben; a konténernek nincs kimenő internet-hozzáférése; graceful shutdown `SIGTERM`-re (max 30 s, félbemaradt `DATA` → `421`); metrikák: kapcsolat/s, AUTH-hibaarány, gate-elutasítás okonként, `DATA` méret-hisztogram.

## Tesztelhetőség

Transcript-fixtúrák (`tests/Fixtures/Smtp/*.transcript`: „bemenő sor → várt válaszkód") egy `SmtpSession` példányon lejátszva. Nincs port, nincs várakozás, nincs valós óra.

| Kritérium | Determinisztikus bizonyíték |
|---|---|
| **11.** 587/465 | Transcript `ChannelSecurity::encrypted`-del: `EHLO → STARTTLS → EHLO → AUTH PLAIN → MAIL FROM → RCPT → DATA → 250`, majd `Message::count() === 1`, `status === 'delivered'` a FakeDriveren. Ugyanaz 465-re STARTTLS nélkül. |
| **12. NYÍLT RELAY (blocking)** | **Négy független teszt**, hogy egyetlen refaktor se tudja mind a négyet eltüntetni: (1) AUTH nélkül `MAIL FROM` = `530`, `DATA` = `503`, `Message::count() === 0`; (2) **reflection-teszt**: `SubmissionIngest::accept()` első paramétere nem-nullable `SubmissionPrincipal`; (3) **arch-teszt**: a `Smtp` modul nem hivatkozik `SendingDriver`-re/`MessageDispatcher`-re; (4) **állapotgép-invariáns**: a parancs-ábécé összes ≤4 hosszú sorozata közül AUTH nélkül egyik sem ér `SessionState::Data`-ba. |
| **13. plaintext auth** | `encrypted: false`: az EHLO-válasz nem tartalmaz `AUTH` sort; `AUTH PLAIN`/`LOGIN` → `538 5.7.11`. Plusz STARTTLS-reset teszt (a pipeline-ozott `MAIL FROM` nem érvényesül). |
| **14. gate az SMTP-úton** | Verifikálatlan domain → a `DATA` vége `550 5.7.1`, `Message.reason === 'DOMAIN_UNVERIFIED'`, `audit_logs`-ban `sending.gate.rejected`. Ugyanez `SUPPRESSED`-re és `RATE_LIMITED`-re (`452`). |
| **15. vezérlő fejlécek** | `X-VentMail-Tag: a,b,c` → `tags`; 4 tag → `550`; `-Variables` → visszajön a `MessageEvent`-ben; `-Deliverytime` → `scheduled`, múltbeli → `550`; `-Testmode` → a driver nem hívódik; a `X-VentMail-*` fejlécek a mentett üzenetben nincsenek benne. |
| Limitek (D4) | Pest-dataset minden limitre („határon belül átmegy / túl a várt kód"). Időkorlátok injektált órán — nincs `sleep()`. |
| Idempotencia | Ugyanaz a transcript kétszer, azonos `Message-ID`-vel → `Message::count() === 1`. |
| Brute force | `AuthThrottle` fake órán: 3 hibás AUTH → `421`; 10/IP-hash → tarpit; 25 → tiltás. |
| Tenant-izoláció | „B" hitelesítője nem oldható fel „A" kontextusából. |

A socket sem marad teszt nélkül: `smtp-socket` csoportú integrációs teszt loopback
ephemeral porton — a párhuzamos alap-futásból kizárva, a CI-ban külön, soros job futtatja.
**Amit ez a teszt NEM fed, és amit ezért ki kell mondani:** a TLS-handshake és a
hitelesített küldés a dróton. A szerver és a kliens egy processzben fut (a blokkoló
handshake különben holtpontot adna), a hitelesítés pedig titkosított csatornát követel.
Az élő TLS-próba a telepítés utáni füstteszt dolga.

## Migrációs kiút

A felszabás egyetlen csuklóra épül: **az állapotgép nem tud a transzportról, a transzport nem tud a domainről, és a domain-oldal egyetlen ajtaja a `SubmissionIngest`.**

1. **Csak a transzport cserélése.** A `ReactSubmissionServer` helyére Swoole vagy külső (Go) terminátor kerül, ami ugyanazt az `SmtpSession`-t táplálja. A `Protocol\*`, az ingest, a limitek és **az összes teszt változatlan** — ezért mérhető, hogy nincs regresszió.
2. **Protokoll-frontend átadása a (b)-nek.** A Postfix veszi a portokat, a SASL-auth **ugyanazt** az `SmtpAuthenticator` kontraktust hívja, a levél LMTP-n jön be ugyanabba az ingestbe. **Feltétel, ami nem alku:** a `250` csak az ingest döntése után mehet ki (`smtpd_proxy_filter` / `smtpd_end_of_data_restrictions`). Ha ez nem megvalósítható, a (b) nem választható.
3. **Amit a kiút SOHA nem tartalmaz:** a (c). Ha a Postal egyszer natív, elfogadás-ELŐTTI policy-callbacket kap, az ADR újranyitható — addig nem.
4. **Visszaút a REST-re.** `SMTP_SUBMISSION_ENABLED=false`, az ügyfelek a `/v1/messages`-re állnak át. Funkciócsökkentés, de nem biztonsági kockázat.

**A migráció invariánsai:** a `SubmissionIngest` mint egyetlen ajtó · a nem-nullable `SubmissionPrincipal` · a `SendMessage` mint egyetlen kijárat · a `GateReason` → SMTP-kód leképezés · a D4 limitszámok · a 12. kritérium négy tesztje.

## Kockázatok

1. **Interoperabilitás** (JavaMail, .NET `SmtpClient`, sendmail-wrapper) — az A2 DoD-jába legalább 4 kliens-transcript.
2. **TLS a PHP stream-rétegében** — `TlsContextFactory` külön teszttel, cert-lejárat-monitor a healthcheckben.
3. **Egyszálas event loop fejnehéz feldolgozással** — kapcsolat-cap, vízszintes skálázás, latencia-metrika.
4. **A `channel = transactional` kijátszása** — a védelem a volumen-anomália felismerése (A6), nem a gate.
5. **A globálisan unique `username` + scope-kikapcsolás** — egyetlen felelősség, pontos egyezés, izolációs teszt.
6. **Dev- és prod-portok különbsége** — indulási assertion + healthcheck.

## Nyitott kérdések (tulajdonosi döntés)

1. Marketing SMTP-n: végleg tiltva, vagy marad a `channel_restriction` operátori kiskapu? Az ADR a szigorúbbat javasolja.
2. `SMTPUTF8` / IDN szükséges-e az induló ügyfélkörben? (ma backlog)
3. ~~`react/socket` mint függőség jóváhagyása~~ — **tárgytalan:** natív stream-ekkel oldottuk meg, nincs új csomag (lásd D1).
4. Hitelesítő-szintű rate limit alapértéke (300/workspace elég, vagy 60/perc per credential?).
5. PROXY protocol + TCP load balancer prodban.
6. A 465-ös port szükségessége az induláshoz (a 11. kritérium mindkettőt kéri — a szűkítés kritérium-módosítás).

## Backlog (NEM ebben a packetben)

`CHUNKING`/`BDAT`, `DSN`, `SMTPUTF8` · `smtp_transactions` + SMTP-párbeszéd a trace-ben (A3) · bounce-osztályozó és per-domain throttle (A2 másik fele / A3) · MX-fogadás, rspamd + ClamAV, sandboxolt inbound parser (A4) · mailbox-szonda (A5) · MTA-STS / TLS-RPT a submission-lábon (A6) · IP-allowlist a hitelesítőkhöz.
