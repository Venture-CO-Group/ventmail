# Messages modul

**Sáv:** PLATFORM (Mailgun-kiváltás)

Messages API (Mailgun-paritás): strukturált küldés, MIME, batch, o:/h:/v: vezérlők.

## Kimenő csatolmány (H123)
- `POST /v1/messages` `attachments`: `[{filename, content_type?, content (base64)}]` — csak JSON-ban.
- Ellenőrzés: `Data/AttachmentParser` (darabszám → méretbecslés a dekódolás előtt → szigorú base64 →
  pontos méret → típus-allowlist + mágikus bájt → fájlnév-tisztítás). Korlátok: `config/sending.php` `attachments`.
- Tárolás: `Sending\Models\MessageAttachment` (titkosítva, `expires_at`); a lezárt üzenet bájtjai a
  `Message` hookjában törlődnek, a háló a `messages:purge-attachments` (óránként).
- Kiküldés: `Message::outboundAttachments()` (fail-closed) → `OutboundMimeBuilder` (DKIM-aláírt út) és
  `PostalDriver` `send/message` `attachments` (aláíratlan út).

## Határok
- Minden entitás `workspace_id`-hez kötött, global scope-pal (Tenancy).
- Külső gazda csak interfész mögött; a fake teszttel fedett kontraktus.
- A modul nem épít saját identity / consent-banner / fájltár / számlázó / landing-page réteget.

## Állapot
Váz (M0-0). A tartalmat a saját packetje tölti fel — lásd `PHASE.md` és
`docs/ventmail-roadmap.md`.
