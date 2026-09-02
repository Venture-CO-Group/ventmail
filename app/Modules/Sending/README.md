# Sending modul

**Sáv:** közös motor

A küldés szíve: `SendingDriver` interfész + **PreSendGate**.

## Határok
- A `SendingDriver::send()` EGYETLEN hívója a `MessageDispatcher` — architektúra-teszt őrzi
  (`GateBypassGuardTest`), és a container is csak neki adja meg a drivert.
- A PreSendGate MEGKERÜLHETETLEN: a REST (A1) és az SMTP relay (A2) is ezen megy át.
- A driver-csere KIZÁRÓLAG `config/sending.php` — a domain-logika sosem ismeri a Postal
  belsőségeit.

## Kapuk (sorrendben)
| # | Kapu | Kód |
|---|---|---|
| 0 | bemenet-épség (csatorna, címzett, szintaxis) | `INVALID_REQUEST` |
| 0/b | fejléc-biztonság (Bcc/From, CRLF) | `INVALID_REQUEST` |
| 1 | rate-limit (címzettben mér) | `RATE_LIMITED` |
| 2 | domain hitelesített | `DOMAIN_UNVERIFIED` |
| 3 | suppression (workspace + globális) | `SUPPRESSED` |
| 4 | marketing consent | `NO_CONSENT` |
| 5 | entitlement / kvóta | `QUOTA_EXCEEDED` |
| 6 | címellenőrzési kockázat | `VALIDATION_RISK` |

Kapu-hiba esetén a lánc **fail-closed**: `GATE_ERROR`, és a döntés auditált.

## Állapot
M0-3 kész. Ami még nyitott: prioritásos sorok és retry-ütemező (**A2**), üzenet-trace és
webhook (**A3**), publikus `/v1/messages` (**A1**).
