---
paths: ["app/Modules/Inbound/**"]
---
# Inbound routing (MX) — szabályok (parser = támadási felület → security 2. reviewer KÖTELEZŐ)

- Fogadási lánc: MX → SMTP-fogadó → **spam/vírus-szűrés** (rspamd/SpamAssassin + ClamAV) →
  **MIME-parser SANDBOXBAN** → route-motor. A parser sose fusson az app-folyamatban.
- **Route-motor:** prioritás szerint rendezett szabályok. Match: `match_recipient`, `match_header`,
  `catch_all`. Akciók: `forward(url|email)`, `store(notify)`, `stop()`. Az első `stop()` lezárja a láncot.
- **Parsed payload kötelező mezői:** sender, recipient, subject, body-plain, body-html,
  **`stripped-text`** (idézett előzmény + aláírás levágva), fejlécek, csatolmány-lista (Wasabi presigned),
  spam-score, SPF/DKIM eredmény.
- A `forward(url)` POST **HMAC-SHA256 aláírt**, timestamp-tel (replay-védelem), retry + dead-letter.
- **Biztonsági limitek (BLOCKING):** csatolmány méretlimit; **zip-bomba védelem**; ClamAV-szkennelés
  minden csatolmányra; parser időkorlát; rekurzió-mélység limit a beágyazott MIME-nál.
- `store()` retenció konfigurálható (alap 3 nap); lejáratkor a nyers MIME törlődik.
- Az inbound SOHA nem kerülheti meg a tenant-izolációt: a route mindig egy `workspace_id`-hez tartozik.
- Az inbound e-mail lehet **journey-trigger** — de csak megerősített kontaktnál indíthat marketing ágat.
