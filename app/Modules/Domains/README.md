# Domains modul

**Sáv:** közös motor

Küldő domainek hitelesítése: SPF/DKIM/DMARC/PTR/MTA-STS/BIMI, tracking domain, DNS-check.

## Határok
- Minden entitás `workspace_id`-hez kötött, global scope-pal (Tenancy).
- Külső gazda csak interfész mögött; a fake teszttel fedett kontraktus.
- A modul nem épít saját identity / consent-banner / fájltár / számlázó / landing-page réteget.

## Állapot
Váz (M0-0). A tartalmat a saját packetje tölti fel — lásd `PHASE.md` és
`docs/ventmail-roadmap.md`.
