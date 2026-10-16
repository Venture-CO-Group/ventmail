# VentSec modul

**Sáv:** közös (ventSec-kötés)

A ventMail **fogyasztói** oldala a ventSec felé (FO2, `docs/plans/family-onboarding.md`):

- `Services/ServiceTokenProvider` — m2m token a ventSSO-tól (client_credentials, cache-elt, titkosítva).
- `Services/VerdictVerifier` + `VerdictKeyStore` + `Support/Jcs` — a ventSec Ed25519 ítélet-aláírásának
  ellenőrzése (JCS a `ventsec.scan-verdict.v1\n` prefix mögött; kulcs: `/.well-known/ventsec-verdict-keys.json`).
- `Contracts/VentSecEvents` + `Services/HttpVentSecEvents` + `Testing/FakeVentSecEvents` — VSES események
  (HMAC-aláírt ingest), `ventsec:heartbeat` percenként, alapból KI (`VENTSEC_EVENTS_ENABLED=false`).

A `VentSecScanner` (a `VirusScanner` megvalósítása) az Inbound modulban él.

## Határok
- Nincs identitás/token-kibocsátás: a tokent a ventSSO adja ki, mi csak kérjük.
- Az esemény nem hordoz címzettet, fájlnevet, tartalmat, IP-t (a katalógus kontextus-allowlistje zárt).
- Minden hiba fail-closed (szkenner) vagy néma-de-naplózott (események): a küldési/fogadási folyamatot nem blokkolja.
