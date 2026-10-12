# Template modul

**Sáv:** STUDIO (Mailchimp-kiváltás)

Studio block-tree (közös JSON Schema) → MJML → reszponzív HTML + plaintext.

## Határok
- Minden entitás `workspace_id`-hez kötött, global scope-pal (Tenancy).
- Külső gazda csak interfész mögött; a fake teszttel fedett kontraktus.
- A modul nem épít saját identity / consent-banner / fájltár / számlázó / landing-page réteget.

## Állapot
B2 (58–61., 67.) + v1.1 4. modul: drag-and-drop szerkesztő (új blokkok: logó, videó,
termék, kérdőív, lábléc, megosztás; feltételes blokk; globális stílus), verziótörténet
(`template_versions`), mentett blokkok, képfeltöltés `AssetStore` mögött (D10, ventVault →
HUMAN-QUEUE), hat indulósablon (`lang/*/editor.php`), optimalizálás panel és teszt-küldés a
`SendMessage` / PreSendGate úton, „megnyitás böngészőben" (`/w/{token}`).
