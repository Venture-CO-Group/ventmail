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

## Állapot
Váz (M0-0) + közönség (B1) + kontakt-import (v1.1).
