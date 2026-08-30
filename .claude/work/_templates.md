# `.claude/work/_templates` — agent-átadó fájlok

Az agentek **nem beszélnek egymással** — a fő szál a busz, az átadás **fájlban** történik. Minden
packet a `.claude/work/<packet-id>/` alá dolgozik. Másold ezeket a sablonokat oda.

---

## `plan.md` (a lead / architect írja, a fejlesztő olvassa)
```
# <packet-id> — terv
## Cél
## Érintett modulok / fájlok
## Teszt-előbb: a megírandó PIROS tesztek
- [ ] ...
## Implementációs lépések (kis lépések)
1. ...
## DoD
- [ ] ...
## Bevonandó agentek (sorrend)
test → backend/frontend → reviewer + contract-guard (+ security ha kripto/auth/PII/pénz)
```

## `review.md` (a reviewer írja, súlyozva)
```
# <packet-id> — review
## BLOCKING
## SHOULD
## NIT
## Golden-rule megfelelés (ventMail)
- [ ] PreSendGate megkerülhetetlen
- [ ] transactional ≠ marketing, consent-alap marketingnél
- [ ] Idempotency-Key + HMAC webhook (ha API)
- [ ] tenant-izoláció (global scope)
- [ ] nincs saját identity/consent/fájl/számlázó reimplementáció
- [ ] design token csak @venture/*
```

## `contract-report.md` (a contract-guard írja)
```
# <packet-id> — "egy képesség — egy gazda" jelentés
## Talált sértések (BLOCKING)
- [ ] saját mailer/SMTP?      -> ventMail maga a gazda: OK, DE ne legyen 2. réteg
- [ ] saját consent-banner?   -> ventCookie a gazda
- [ ] saját fájlfeltöltés?    -> ventVault
- [ ] saját számlázás?        -> ventPay
- [ ] saját jelszó-hash/auth? -> ventSSO
- [ ] saját e-aláírás?        -> ventSign
## OpenAPI ↔ @venture/sdk szinkron
- [ ] drift = 0 ; /v1 breaking change = nincs
```
