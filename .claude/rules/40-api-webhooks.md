---
paths: ["app/Modules/Api/**", "app/Modules/Webhooks/**", "app/Modules/StoredTemplates/**", "routes/**"]
---
# Public / Admin API · webhookok · tárolt sablonok — szabályok (contract-guard figyeli)

- Verziózás `/v1`. Breaking change a `/v1`-en belül BLOCKING. OpenAPI ↔ `@venture/sdk` **drift = 0**.
- **Idempotency-Key KÖTELEZŐ** minden küldő POST-on (`/v1/messages`, `/v1/messages.mime`).
- API-kulcs: hash-elve, workspace-hez kötve, rotálható, rate-limit a planből.
  Scope-ok: `messages:send`, `templates:read`, `templates:write`, `events:read`, `suppressions:write`,
  `validate:run`, `routes:write`, `logs:read`, **`webhooks:write`** (végpont-CRUD, titok-rotáció,
  DLQ-replay — a webhook-kézbesítések OLVASÁSA `logs:read`).
- Webhook: eseménytípusonként konfigurálható endpoint; **HMAC-SHA256 + timestamp** (replay-védelem);
  exponenciális retry; **dead-letter**; **UI-ból replay**; utolsó 100 kézbesítés naplója.
- **Tárolt sablonok:** verziózott, `active` verzióval; változó-behelyettesítés auto-escape-pel és
  fallbackkel. A Studio block-editorából publikálható tárolt sablonná (közös block-tree → MJML).
- Publikus endpoint SOHA rate-limit/auth nélkül. Bot-szűrés a form- és validate-endpointokon.
