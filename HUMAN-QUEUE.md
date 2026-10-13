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

## P2 — journey vászon (v1.1 · 6. modul)
- [ ] **RUNBOOK-mail kiegészítése (venture-infra repó) — VISSZAÁLLÁS v1.1 előttire:** a régi kód a
      vászon lépéseit nem ismeri (`StepType::from()` ValueError az új típusokon, a `wait` másként
      vár, a szüneteltetett journey futásait kilépteti). Visszaállítás ELŐTT minden gráf-alapú
      journey-t szüneteltetni kell (`journeys.current_version_id is not null and status = 'active'`
      → `paused`), és a futó gráf-futásokat ellenőrizni (`journey_runs.journey_version_id is not null`).
      Az előre-telepítés után folytatható (publikálás változatlan gráffal = folytatás).
      A CÉL: visszaállás után a régi kód a journey-szintű célt olvassa, amit az új publikáló már nem
      frissít (a cél a verzióban él) — a vásznon publikált journey-k célja a régi kódon nem érvényesül.
- [ ] **Bekötetlen journey-triggerek döntése:** `tag_added`, `tag_removed`, `field_changed` — a forrásnak
      ma nincs egykontaktos hívója (a címkézés tömeges `TagOperations`, a mezők importból jönnek). A
      vásznon „hamarosan", a validátor nem engedi publikálni. Döntés: tüzeljen-e tömeges műveletből
      (tömeges beléptetés!) vagy csak egykontaktos szerkesztési útról.
- [ ] **Kampány-megnyitás trigger:** az Apple MPP / képproxy gépi megnyitásai is beléptetnek — maradjon-e
      a megnyitás triggerként, vagy csak a kattintás.

## P2 — analitika, PDF-riport, branding (v1.1 · 8. modul)
- [ ] **Első deploy a `ventmail-render` stackkel:** a `deploy.sh` a 2. lépésben indítja a Gotenberg
      sidecart (`deploy/compose.render.yml`) és létrehozza a `ventmail-storage` / `ventmail-storage-public`
      volume-okat. A boxra a `compose.render.yml`-t is fel kell másolni (a `deploy/` mellé; a venture-infra RUNBOOK-mail
      „Első telepítés” fájllistájába is fel kell venni), és a meglévő
      `.env`-be `set-env.sh`-val: `ANALYTICS_PDF_RENDERER=gotenberg`, `GOTENBERG_URL=http://gotenberg:3000`,
      `ANALYTICS_PDF_TIMEOUT=60`. Deploy után: `php artisan analytics:render-smoke` (ops) → `OK … oldal`.
- [ ] **D227-átállás ellenőrzése (automatikus, D254):** az első D227-es deploy a régi szín leállítása
      előtt maga másolja át a sablon-képeket és a privát fájlokat a volume-okra. Utána ellenőrizd a
      jelölőt: `docker run --rm -v ventmail-storage:/d alpine cat /d/.d227-migrated` (fájlszám, bájt). Ha a
      deploy `storage migration … FAILED`-del állt meg: a régi szín fut, ne deployolj újra — javítás után
      `./deploy.sh --migrate-storage`.
- [ ] **Memória-keret:** +1 GB a Gotenbergnek (mem_limit 1g) + a worker `reports` supervisora (256 MB a
      2g-n belül). A deploy-ovh 12. pont költségvetését jóvá kell hagyni.
- [ ] **ventVault:** a riport-PDF (`reports/…`) és a workspace-logó (`branding/…`) ma a privát helyi
      diszken (megosztott volume) él — a ventVault fájltár élesítésekor oda költözik (`ReportArtifactStore`,
      `BrandingService`).
- [ ] **Saját (OFL) betűk a riportban (D234):** a Gotenberg hálózat nélkül fut, a Google Fonts nem
      érhető el — a PDF a rendszer-fallback betűt használja. Döntés: Inter / Space Grotesk / JetBrains Mono
      OFL-fájlok a riport-csomagba (licenc-felülvizsgálat: OFL ≠ MIT/BSD/Apache, D178-kivétel kell).
- [ ] **@venture/ui:** hiányzó komponensek jelentve — chart (vonal/oszlop/kör/tölcsér) + PNG-export menü,
      A4-borító-előnézet. A ventMail lokálisan pótolja (`Components/Charts/*`, `Components/Analytics/*`).
- [ ] **Gotenberg-image frissítés:** a pinelt `8.37.0-chromium@sha256:0d28ae9a…` (2026-10-13). Új verziónál
      a CI `gotenberg-contract` jobja és a deploy-teszt digest-egyezése vezet; a Chromium-biztonsági
      frissítések miatt negyedévente.

## Review-eszközök
- [ ] coderabbit, aikido, nightvision, pr-review-toolkit — CLI-ből nem érhetők el ebben a futásban;
      a PR-eken kézzel futtatandók.
