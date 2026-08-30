---
paths: ["app/Modules/Audience/**", "app/Modules/Consent/**", "app/Modules/Forms/**"]
---
# Audience / Consent / GDPR — szabályok (PII → security 2. reviewer)

- Az audience-lista a kontakt IGAZSÁG FORRÁSA; kétirányú ventOS CRM-sync (`VentureOsSync`, idempotens
  upsert `external_id`-re, last-write-wins + audit). Amíg ventOS nem él → `FakeVentureOsSync` a kontraktus szerint.
- Marketing feliratkozás KÖTELEZŐEN double opt-in (`ConsentService`): signup → megerősítő transzakciós
  levél → confirmed consent (jogalap, forrás, ip_hash, verzió, timestamp). Megerősítetlen kontakt
  marketing csatornán NEM kaphat küldést (PreSendGate).
- GDPR: ROPA-export; data-subject export + törlés (törléskor suppression MEGMARAD, PII anonimizál);
  raw IP NEM tárolt (hash/csonkolt); konfigurálható retenció.
- Preference center minden marketing levélben linkelt; egykattintásos leiratkozással konzisztens.
- Form: honeypot + time-trap spam-védelem, GDPR consent-checkbox, double opt-in bekötés.
- PII-t érintő PR → `venture-security` kötelező 2. reviewer.
