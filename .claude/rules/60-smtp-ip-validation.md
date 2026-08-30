---
paths: ["app/Modules/Smtp/**", "app/Modules/IpPools/**", "app/Modules/Validation/**", "app/Modules/Deliverability/**"]
---
# SMTP relay · IP-poolok · címellenőrzés · kézbesíthetőség — szabályok

## SMTP relay
- Submission: **587 (STARTTLS)** és **465 (implicit TLS)**. A **port 25 kizárólag MX-fogadásra**.
- **NYÍLT RELAY TILOS** — minden submission hitelesített, per-domain `smtp_credentials`-szel
  (hash-elve tárolva, rotálható). **Plaintext auth tiltva.**
- Az SMTP-n beadott levél **ugyanazon a PreSendGate-en** megy át, mint a REST. Nincs kiskapu — ez BLOCKING.
- Vezérlő fejlécek: `X-VentMail-Tag`, `X-VentMail-Variables`, `X-VentMail-Deliverytime`, `X-VentMail-Testmode`.
- Brute-force védelem + hitelesítő-alapú kvóta és rate-limit.

## IP-poolok & warmup
- Pool → csatorna és pool → domain hozzárendelés. **Marketing IP SOHA nem szennyezheti a transactionalt.**
- Warmup: napi volumen-görbe, automatikus visszafogás reputáció-romlásnál, beteg IP → `quarantined`.
- Sor-prioritás: `transactional` > `journey` > `campaign`. A kampány nem éheztetheti a tranzakciósat.
- Deferral (4xx): exponenciális backoff, **per-fogadó-domain throttle** (Gmail/Yahoo/Microsoft külön bucket).
- Bounce-osztályozó: `hard`/`soft`/`blocked`/`spam`/`mailbox_full`/`policy`. Hard → azonnali suppression.

## Címellenőrzés
- Ellenőrzések: szintaxis, MX, szerepcím, eldobható, catch-all, elgépelés, **mailbox-szonda**, kockázati pontszám.
- A **mailbox-szonda konzervatív rate-limittel**, cache-elve — az RBL-kockázat minimalizálása kötelező.
- Beépülés: űrlap valós idejű ellenőrzés + **PreSendGate 6. kapu** (küszöb konfigurálható, per-domain kapcsolható).

## Kézbesíthetőség
- 2025/2026 bulk-sender megfelelés: SPF+DKIM+DMARC, **one-click unsubscribe (RFC 8058)**, spam-ráta
  <0.3%, PTR/rDNS, TLS, ARC. Verifikálatlan domainről nincs küldés.
- Blocklist-monitor, DMARC RUA ingest, seed/inbox placement, spam-score küldés előtt, link-ellenőrzés.
