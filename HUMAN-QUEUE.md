# HUMAN-QUEUE — ventMail

> Emberi döntést vagy kézi lépést igénylő tételek, prioritás szerint. A deploy-ovh futás
> (2026-10-12) óta. Részletek: `docs/plans/deploy-ovh.md`, `venture-infra/docs/RUNBOOK-mail.md`.

## P0 — az éles indulás előtt / közben
- [ ] **Google Postmaster Tools, Microsoft SNDS + JMRP, Yahoo CFL** regisztráció
      (`notify.ventureco.app`, `ventmail.app`, mindkét IP). A JMRP/CFL jelentés-címének bekötése a
      complaint → suppression útba (a Postal nem dolgoz fel ARF-et).
- [ ] **Riasztási csatorna** (RAID `mdadm-event`, `dnsbl-check` failed unit, OVH Vade-tiltás, deploy-hiba).
      Amíg nincs: journal + `systemctl --failed`.
- [ ] **Backup-cél**: OVH Backup Storage vagy Wasabi (Object Lock) + `AGE_RECIPIENT` a
      `/srv/backups/backup.env`-be. Addig a mentés csak helyi (ugyanazon a RAID-en!).
- [ ] **Mail-tester** pont (ha a futáskor nem volt cím): egy `test-…@srv1.mail-tester.com`
      cím ideiglenesen az allowlistbe (`@srv1.mail-tester.com`), tesztlevél, ≥ 9/10, majd ki.

## P1 — a ventSSO-levelek átkapcsolása (sorrend kötött — RUNBOOK-mail §13)
1. [ ] warm-up indul (docs/WARMUP-PLAN.md §8, tranzakciós IP)
2. [ ] `SENDING_LIVE=true`
3. [ ] az allowlist ki (`SEND_ALLOWLIST_ENFORCED=false`)
4. [ ] a ventSSO driver-flag be (`VENTMAIL_DRIVER=ventmail` a trust boxon) — a `ventsso`
       API-kulcs (`messages:send`, `templates:read`) már a trust box `.env`-jében van
5. [ ] jelszó-reset teszt a tulajdonos saját fiókjára

## P2 — kézbesíthetőség
- [ ] **DMARC `p=none` → `quarantine`** ütemezése: 2–4 hét tiszta rua-jelentés után
      `quarantine; pct=25` → `pct=100` → `reject` (notify.ventureco.app és ventmail.app).
- [ ] **Marketing warm-up** indítása (docs/WARMUP-PLAN.md) — előtte: UCEPROTECT L2 újraellenőrzés az
      `51.38.111.172`-n (2026-10-12: tartomány-listázás, tulajdonosi döntés: marad), jogalap-ellenőrzés.
- [ ] **Spamhaus DQS** kulcs (ingyenes kvótán belül) a ventMail `deliverability:blocklists` jobjához:
      a konténerek az OVH resolverét látják, amit a Spamhaus elutasít (a box saját figyelője
      loopback-unboundon megy, az helyes).

## P3 — v1-ből kimaradt képességek
- [ ] SMTP submission 587/465 élesítése (TLS `smtp.ventmail.app`, ufw + DNS, rate-limit).
- [ ] Inbound MX (a 25-öt ma a Postal kapja — külön IP vagy port-szétválasztás kell).
- [ ] Ügyféldomaines tracking és MTA-STS: on-demand TLS az edge-en + `TRUSTED_HOSTS` bővítés.
- [ ] Ügyféldomainek szinkronja a Postalba (saját Return-Path `psrp-tx/mk.<domain>` ügyfeleknek;
      ma a `use_for_any` domain hitelesít, a Return-Path `rp-tx.ventmail.app`).
- [ ] Meilisearch (kontakt-kereső) és Reverb (élő kampány-progress).
- [ ] A ventMail saját rendszer-levelei (meghívó stb.) a saját motoron (`MAIL_MAILER=log` ma).
- [ ] `@venture/sdk` hibakód-unió: `SENDING_RESTRICTED`, `CHANNEL_DISABLED` felvétele.

## Review-eszközök
- [ ] coderabbit, aikido, nightvision, pr-review-toolkit — CLI-ből nem érhetők el ebben a futásban;
      a PR-eken kézzel futtatandók.
