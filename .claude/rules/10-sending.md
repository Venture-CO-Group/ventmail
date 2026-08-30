---
paths: ["app/Modules/Sending/**", "app/Modules/Messages/**", "app/Modules/Events/**", "app/Modules/Logs/**"]
---
# Sending · Messages · Events · Logs — szabályok

## SendingDriver
- Minden kimenő üzenet a `SendingDriver` interfészen megy át (`PostalDriver` / `SmtpRelayDriver` /
  `LogDriver` / `FakeDriver`). Driver-csere = CONFIG, nem domain-kód. A domain-logika sose ismerje a
  Postal belsőségeit.

## PreSendGate — 6 kapu, megkerülhetetlen (BLOCKING)
1) domain-auth zöld · 2) suppression (globális + workspace) · 3) marketing-consent (double opt-in) ·
4) entitlement/kvóta · 5) rate-limit · 6) **címellenőrzési kockázat** a küszöb alatt.
Minden kapu külön teszttel; elutasítás strukturált `reason` kóddal, auditálva.
**A gate a REST-re ÉS az SMTP relayre is érvényes.**

## Messages API
- `channel` KÖTELEZŐ: `transactional` | `marketing`. Nem keverhető; transactional nem tiltható
  marketing-leiratkozással.
- **Idempotency-Key kötelező** minden küldő POST-on; ismételt kulcs = ugyanaz a válasz, nincs dupla küldés.
- Támogatandó: strukturált küldés, **nyers MIME** (`/v1/messages.mime`), **batch max 1000 címzett**
  `recipient-variables`-szel, tárolt sablon (`template=` + `vars[]`).
- Vezérlők: `o:deliverytime`, `o:testmode`, `o:tag` (max 3), `o:tracking[-clicks|-opens]`, `o:dkim`,
  `o:require-tls`, `o:sending-ip-pool`, `h:X-*` fejlécek, `v:*` változók (visszajönnek az eseményben).

## Events & Logs
- Eseménytípusok: accepted, rejected, delivered, deferred, bounced, complained, unsubscribed, opened,
  clicked, stored. Timescale hypertable.
- **Üzenet-trace kötelező:** sorbaállítás → gate-döntés → IP → **SMTP-párbeszéd naplója** → kézbesítés/
  bounce okkal → nyitás/klikk. Ez a „miért nem érkezett meg?" funkció.
- **Napló-retenció planenként**; lejárat után nyers esemény TÖRLŐDIK, csak aggregátum marad (GDPR).
- Tag-szintű statisztika kötelező (per-tag delivered/open/click/bounce).
- Webhook: HMAC-SHA256 + timestamp (replay-védelem) + exponenciális retry + **dead-letter** +
  **UI-ból újrajátszás (replay)**.
