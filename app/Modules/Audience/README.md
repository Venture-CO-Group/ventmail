# Audience modul

**Sáv:** STUDIO (Mailchimp-kiváltás)

Kontaktok, listák, tagek, groupok, dinamikus szegmensek (SegmentEngine).

## Határok
- Minden entitás `workspace_id`-hez kötött, global scope-pal (Tenancy).
- Külső gazda csak interfész mögött; a fake teszttel fedett kontraktus.
- A modul nem épít saját identity / consent-banner / fájltár / számlázó / landing-page réteget.

## Kontakt-import (v1.1)

Hat lépéses varázsló (`/audience/import`): feltöltés → oszlopok → beállítások → előnézet →
folyamat → eredmény. Négy bevitel: kézi egyedi, beillesztett lista, CSV, XLSX.

- **Beolvasás:** streamelve (`Services/Import`): CSV `fgetcsv` (elválasztó az első 20 sorból, kódolás:
  UTF-8 ± BOM, különben Windows-1250 — saját táblával, mert a mbstring nem ismeri), XLSX az
  `openspout/openspout`-tal (MIT; munkalap-választás). Határok: méret, sor, oszlop, cellahossz,
  zip-bomba (a zip központi jegyzékéből, kitömörítés előtt). A fájl privát lemezen (`imports/{ws}/`),
  a futás végén törlődik; a maradékot az `audience:purge-imports` takarítja (napi).
- **Állapot a szerveren:** `contact_imports` (ki, mikor, fájlnév+hash, jogalap, forrás, számlálók,
  státusz), auditált (`Auditable` + `audience.import.started/completed/failed`; a minta-sorok és
  a címek az audit-naplóba SOHA nem kerülnek).
- **Jogalap kötelező (`ImportLegalBasis`):** `subscribed` CSAK `verified_consent` + forrás + dátum
  esetén; minden más `pending` (re-permission szükséges). Kontaktonként egy `imported`
  consent-esemény (`ConsentRecorder::importedBatch`). A `consents` sor `pending` marad — az import
  megerősített hozzájárulást nem hoz létre.
- **Soha nem éled fel:** globális/workspace suppression, visszavont consent, GDPR-sírkő, törölt
  kontakt; létező kontakt státusza nem változik.
- **Teljesítmény:** 1000 soros chunkok, kötegelt `INSERT`/`UPSERT`, chunkonként egy tranzakció;
  a fájlon belüli duplikátum-felismerés memóriában (12 bájtos hash-kulcs, ~80 B/sor). Az
  `ImportTableStats` az import közben `ANALYZE`-ol (elavult statisztikával a tervező az egész
  workspace-en végigment). 300 000 sor: ~3 perc, memória-csúcs +50 MB (`heavy` teszt).
- **Küldés:** az import NEM küld levelet (sem DOI-t). // TODO ventMail: a re-permission kampányt a
  tenant indítja, a PreSendGate-en át.
- **ventOS / ventPay:** az importált kontakt `external_id`-ja `NULL` (a szinkron érintetlen); az
  import végén `contacts_peak` a valós létszámra áll (`Metering::observePeak`).

### Import — biztonsági és üzemeltetési szabályok (review-körök után)

- **Feltöltési plafon:** `AUDIENCE_IMPORT_MAX_MB` (alap 100) ≤ `docker/php/php.ini` (`upload_max_filesize`
  128M, `post_max_size` 132M, `max_execution_time` 120) ≤ a Caddy `request_body max_size` (140MB). A
  felület a konfig és a PHP tényleges limitjének MINIMUMÁT mutatja; az összhangot teszt őrzi. A
  publikus edge (venture-infra) body-limitje is legyen ≥ 140MB.
- **Felmérés a háttérben (D71):** a kérés CSAK tárol, draft rekordot (`inspecting`) készít, olcsó
  őröket futtat (zip-szerkezet + valós bájt- és csomópont-számlálás minden XML-részre:
  munkalapok, sharedStrings, styles, workbook) és dispatch-el. A fejléc/minta/leképezés-javaslat az
  `InspectContactImport` jobban készül (`imports` queue, 60 mp timeout, `inspecting` → `draft`
  feltételes átmenet; hibánál/timeoutnál `abandon()`: fájl, minta törlődik, `failed`). A
  munkalap-váltás is ez. Az openspout a kérésben NEM fut. Workspace-enként 1 egyidejű felmérés.
- **Zip-bomba:** a központi jegyzék mérete hamisítható, ezért a feltöltéskor a VALÓS kitömörített
  bájtokat számoljuk streamen (megszakítás a deklarált méretnél / plafonnál / időkeretnél). A
  felmérés kérésen belül fut (`inspect_budget_seconds`, alap 25 mp): a képernyőnek a fejléc és a
  minta azonnal kell; az időkeret + sorplafon + a fizikai sor hossz-plafonja védi az FPM-workereket.
- **Throttle:** feltöltés (`audience-import`) és minden további lépés (`audience-import-step`)
  workspace-kulcsú, ablakonként KÜLÖN kulccsal; workspace-enként legfeljebb `max_concurrent` (2)
  futó előnézet/import.
- **Státuszátmenetek** mindenhol feltételes `UPDATE … WHERE status IN (…)` (lock, job-bevétel,
  lezárás, mégse) — dupla futás nem lehetséges. A mégse csak `draft`/`previewed` kötegen megy.
- **Írás:** `INSERT … ON CONFLICT DO NOTHING`, a ténylegesen beszúrt sorok (saját uuid-k) kapnak
  consent-eseményt és listatagságot; a frissítés `UPDATE … FROM VALUES … deleted_at IS NULL`
  (nincs upsert-feltámasztás); a tiltólista / GDPR-sírkő a tranzakción belül újraellenőrzött.
  A létező kontakt új listatagsága a SZIGORÚBB státuszt kapja.
- **Hibás futás:** a riportfájl, a forrásfájl és a mintasorok törlődnek; a napló csak osztályt,
  SQLSTATE-et és a köteg uuid-ját kapja (QueryException-kötések nélkül). A hibariport letöltése auditált.
- **Tiltólista-találat** egységes `suppressed` kódot kap (nincs tenantközi orákulum).
- **Journey (N5):** az import NEM indít journey-t közvetlenül. A `contacts` sorok bulk-írása
  megkerüli a modell-eseményeket, ezért egy „kontakt belépett a szegmensbe” (`segment_entered`)
  eseményre épülő journey-belépés az importált kontaktokra a journey SAJÁT szegmens-kiértékelési
  ciklusában (nem az importkor) fog lefutni. Ez szándékos: az import `pending` kontaktokat ír, a
  marketing-journey pedig a PreSendGate 3. kapuján át úgysem küldhetne nekik megerősítésig.

## Állapot
Váz (M0-0) + közönség (B1) + kontakt-import (v1.1).
