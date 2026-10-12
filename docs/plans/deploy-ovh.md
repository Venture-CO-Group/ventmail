# ventMail éles deploy — mail-ovh-01 (OVH) · PLAN + döntések

> Forrás: `docs/prompts/deploy-ovh.md`. Futtatási mód: autonóm, egy menetben; csak a
> kényszer-STOP (S1–S4) és a hiba-STOP (F1–F5) pontoknál áll meg. Ez a fájl a felmérés
> eredménye, a terv és minden döntés indoklása. Állapot: lásd a „Napló” szakaszt a végén.

## 0. Felmérés (2026-10-12)

### Szerver — `141.95.64.117` (OVH RISE, Ubuntu 24.04.5 LTS)
| Tétel | Eredmény |
|---|---|
| RAID | `md2` (/boot) és `md3` (/) RAID 1, mindkettő `[UU]`, **szinkronban** (nem szinkronizált épp) |
| Lemez / RAM / CPU | 2× 476.9G NVMe, `/` 467G (1% foglalt) · 31 GiB RAM · 12 mag |
| Swap | a telepítő létrehozta: 2× 512M partíció (1 GiB) → **nem** kell swapfile, csak `vm.swappiness=10` |
| Fő IPv4 | `141.95.64.117/24` az `enp3s0f0`-n ✔ |
| IPv6 | `2001:41d0:700:5c75::/64` ✔ (a sablon a puszta `::` prefixet tette fel; a web `::1`-et kap) |
| Additional IP | **nem volt konfigurálva** → netplan drop-inben, `/32` alias, virtuális MAC nélkül |
| **Additional IP — javítás** | A promptban a `141.95.64.254` szerepelt; a tulajdonos futás közben javította: **a helyes IP `51.38.111.172`**. Mindenhol ez szerepel. |

### 25-ös port (kimenő)
- `gmail-smtp-in.l.google.com:25` **nyitva**.
- `mx1.hotmail.com:25` időtúllépés — ez egy **elavult** hostnév (a Microsoft már nem ezen fogad). A
  valódi MX-ek nyitva: `hotmail-com.olc.protection.outlook.com`, `outlook-com.olc…`, `mta5.am0.yahoodns.net` → **nem F4**.

### Feketelisták
| Lista | 141.95.64.117 (tx) | 51.38.111.172 (mk) |
|---|---|---|
| Spamhaus ZEN | tiszta | tiszta |
| Barracuda | tiszta | tiszta |
| SpamCop | tiszta | tiszta |
| UCEPROTECT L1 | tiszta | tiszta |
| UCEPROTECT L2 | tiszta | **listázott** (tartomány-szint) |
| UCEPROTECT L3 | listázott | listázott |

- **Spamhaus**: az OVH resolvere a tesztpontra (`127.0.0.2`) is NXDOMAIN-t ad, a Spamhaus
  authoritative szerverei `127.255.255.254`-et (elutasítás). A mérés helyi resolveren készült,
  a boxon pedig egy loopback-unbound (`127.0.0.2`) adja a DNSBL-útvonalat. A figyelő **először a
  tesztpontot ellenőrzi**, és vak resolver mellett hibával áll meg.
- **UCEPROTECT L3** az egész OVH ASN-t listázza (ellenőrizve: 213.186.33.99, 51.75.0.1, 145.239.1.1
  is rajta van) → IP-cserével nem javítható, a nagy postafiók-szolgáltatók nem használják: **nem F3**, informatív.
- **UCEPROTECT L2 az `51.38.111.172`-n → F3 STOP volt.** Tulajdonosi döntés (2026-10-12): **az IP marad**,
  a marketing pool küldés-tiltott, a listázás a figyelőben „elfogadott” (box.env `DNSBL_ACCEPTED`),
  és a warm-up előtt újraellenőrizzük (WARMUP-PLAN, HUMAN-QUEUE).

### Repó
| Kérdés | Megállapítás |
|---|---|
| MTA-motor | **Postal** (`PostalDriver`, HTTP API). Driver-csere: `SENDING_DRIVER` (`fake`/`log`/`postal`). |
| `_LIVE` flagek | **Nem voltak.** Új: `SENDING_LIVE`, `SENDING_MARKETING_ENABLED`, `SEND_ALLOWLIST(_ENFORCED)`. |
| PreSendGate | kész, 10 kapu + 0. kapuk; fail-closed. Új kapu: `SendingModeGate`. |
| Domain-auth wizard | kész (DKIM 2048, SPF/DKIM/DMARC/tracking/MTA-STS/TLS-RPT rekordok + `domains:verify`). Return-Path rekord **hiányzott** → poolonkénti `psrp-tx`/`psrp-mk` CNAME. |
| Suppression | kész (hard → azonnal, soft → 5 után). **A Postal visszacsatolás hiányzott**: a bounce sosem jutott volna el a táblába → új aláírt webhook-fogadó. |
| Pool-választás | `IpPoolSelector` megvolt, **de a `PostalDriver` egyetlen kulccsal mindent ugyanoda küldött**, és a `campaign`/`journey` csatorna sosem illeszkedett a `marketing` poolra (pontos sztring-egyezés). |
| OIDC | kész (PKCE, JWKS-rotáció, támadási vektorok, RP-initiated logout). Hiány: `aud`=UUID éles alak, scope `roles` nélkül. |
| Proxy / süti | `TRUSTED_PROXIES` alapértéke `*` volt; nem volt `__Host-` süti és Host-ellenőrzés. |
| Meilisearch | csak a kontakt-keresőhöz; hiányában a fake index áll be (kaput nem nyit) → v1-ben **nincs**. |
| Reverb | csak a kampány-progress élő frissítéséhez → v1-ben `BROADCAST_CONNECTION=log`. |
| SMTP submission | **van** (587/465, `StreamSubmissionServer`). |
| Inbound MX | van, de a 25-öt a Postal kapja (bounce/DSN) → v1-ben `INBOUND_ENABLED=false`. |
| Hardcode-olt domainek | csak config-alapértékekben (`config/*.php`, env-ből felülírható) és egy UI placeholderben — rendben. |
| Production image / deploy | **nem volt** (csak dev compose) → a ventSSO mintája szerint elkészült. |

## Döntések (indoklással)

1. **Két teljes Postal-példány, nem két IP-pool egyben.** A Postal IP-poolja szerver-szintű, a
   Return-Path domain pedig példány-szintű (`dns.return_path_domain`). A „soha nem keveredve”
   szabály így fizikailag érvényesül: külön konfig, adatbázis, aláíró kulcs, egress-hálózat,
   HELO (`mta-tx1`/`mta-mk1`), Return-Path (`rp-tx`/`rp-mk.ventmail.app`), és saját 25-ös port a saját IP-n.
2. **Forrás-IP Docker SNAT-tal** (`com.docker.network.host_ipv4`), nem host networkkel: a
   `postal-tx-egress` → 141.95.64.117, a `postal-mk-egress` → 51.38.111.172 (mérve, ipify).
   Így a Postal belső szolgáltatásai nem kerülnek host-portra, és a Postal webje csak a belső
   `postal-api` hálózaton érhető el.
3. **Kimenő 25 csak a Postalnak** — `DOCKER-USER` lánc (`venture-docker-user.service`): minden más
   konténer kimenő tcp/25-je resetet kap (mérve: edge → nincs banner, postal-tx → Gmail banner).
   IPv6-on tcp/25 kifelé tiltva (ufw + ip6tables).
4. **A ventMail a csatorna pool-osztálya szerint választ példányt** (`Channel::poolClass()`):
   `transactional` → tx, minden consent-köteles (`marketing`, `campaign`, `journey`) → mk.
   Hiányzó példányra NINCS visszaesés, közös végpont/kulcs/Return-Path esetén nincs küldés,
   rossz osztályú IP-foglalásnál a driver megáll. Teszt: `PoolSeparationTest`.
5. **Küldési mód: három kapcsoló, alapból zárva.** `SENDING_LIVE=false` = seed-mód (csak az
   allowlist; a kötelezőség kikapcsolása sem nyit ki semmit), `SEND_ALLOWLIST_ENFORCED`, és
   `SENDING_MARKETING_ENABLED=false`. Két helyen dönt: a PreSendGate-ben ÉS a dispatcherben a
   driver hívása előtt (a sorban álló levél is megáll, ha közben visszazárják). Teszt: `SendingModeTest`.
6. **Postal → ventMail visszacsatolás**: `POST /internal/mta/postal/{pool}`, RSA-SHA256 aláírás
   (`X-Postal-Signature-256`) poolonkénti publikus kulccsal; kulcs nélkül 503, rossz aláírásra 401;
   uuid-dedup a feldolgozással egy tranzakcióban; az edge az `/internal/*`-ot nem engedi.
   A Postal-token → üzenet hivatkozás tenant-izolált (`PostalMessageRef`), a kontextusmentes
   feloldás az `ApiKey::findByPlainKey()` mintáját követi. Az aszinkron DSN → hard bounce.
7. **A Postal `use_for_any` domainnel** (`rp-tx`/`rp-mk.ventmail.app`) hitelesít bármely, a
   PreSendGate által engedett feladót (a ventMail maga írja alá a DKIM-et, a feladó domainjére
   igazítva); a `notify.ventureco.app` külön domainként is fent van a tx-en, így a Return-Path
   `psrp-tx.notify.ventureco.app` (SPF is igazodik). A Postal 3.3.7 1024 bites kulcsait 2048-ra cseréljük.
8. **Edge mögötti bizalom**: `config/proxy.php` — alapból senkinek nem hiszünk, élesben csak az
   edge `/32`-jének; a `Prefix`/`Port` sosem. `TRUSTED_HOSTS` (app + api) élesben; `__Host-` süti.
   A rate-limit kulcsok (`peer()`, API-kulcs fék, consent-bizonyíték IP) a `ClientAddress`-ből
   jönnek: TCP-partner, vagy a szűkített edge-től a továbbított cím — különben az edge mögött minden
   fék egyetlen globális vödör lenne.
9. **v1-ből kimarad (HUMAN-QUEUE):** SMTP submission 587/465 (a repó támogatja, de a v1 forgalom
   API-n jön; TLS + DNS + rate-limit élesítése külön lépés), inbound MX (a 25 a Postalé),
   Meilisearch, Reverb, ügyféldomaines tracking/MTA-STS (on-demand TLS kell az edge-en),
   ügyféldomainek szinkronja a Postalba (saját Return-Path ügyfeleknek).
10. **Backup**: Postgres (pg_dump) + MariaDB (mariadb-dump) éjjel, 7 nap lokálisan, age +
    Wasabi Object Lock csak ha be van állítva (HUMAN-QUEUE: backup-cél). Restore-teszt mindkettőre.
11. **Rate limit kezdetben**: domain-csoportonként a `SENDING_THROTTLE_*` (Gmail 100, Microsoft 60,
    Yahoo 60, egyéb 60/perc); a tx IP napi kerete 500, a mk-é 0 (`ventmail:ip-pools:sync`).
12. **Resource limitek**: Postgres 6g · app 2g · worker 2g · Redis 1.25g · MariaDB 2g ·
    Postal worker 1.5g/pool · Postal web/smtp 768m · edge 256m — egy elszálló worker nem viheti el az MTA-t.
13. **Merge**: a CLAUDE.md „PR-only, auto-merge soha” és a hook a `main`-re pusht tilt; a prompt
    kifejezetten merge-et kér. Megoldás: PR → teljes CI zöld → `gh pr merge --merge` (merge-commit,
    = `--no-ff`) a tulajdonos promptbeli utasítására; nincs GitHub auto-merge és nincs direkt push.
14. **Bounce-teszt a seed-szabály betartásával**: harmadik fél postafiókja nem kap levelet; a
    teszt egy saját, nem létező címre megy (`bounce-test@rp-tx.ventmail.app`, a saját MX-ünk
    utasítja el 5xx-szel), az allowlistbe ideiglenesen felvéve, utána eltávolítva.

## PLAN (sorrend)

1. Hardening (`venture-infra` `boxes/mail/bootstrap-host.sh`) ✔
2. Edge, Postgres (+TimescaleDB), 2× Postal + MariaDB ✔, Postal-provisioning ✔
3. Repó: küldési mód, pool-szétválasztás, Postal-webhook, proxy/süti/host, OIDC éles alak,
   provisioning-parancsok, production image + compose + deploy.sh + release workflow ✔
4. ventSSO: `feat/ventmail-driver` (outbox + Idempotency-Key + retry, flag mögött, alapból Null)
5. Teljes tesztelés → PR → merge → `v1.0.0` tag → GHCR image
6. **STOP (S3+S2+S1/1):** GHCR read-token a szerverre · PTR mindkét IP-re · DNS (a ventMail-DKIM kivételével)
7. Deploy (`init-env.sh`, `deploy.sh v1.0.0`), Postal-kulcsok + webhook-kulcsok `set-env.sh`-val,
   OIDC kliens a prod ventSSO-ban, rendszer-workspace, `notify.ventureco.app` → ventMail-DKIM
8. **STOP (S1/2):** a ventMail-DKIM TXT (egy rekord) — utána `domains:verify` → verified
9. Éles ellenőrzés (10 pont), takarítás, riport

## Napló
- 2026-10-12 00:3x — felmérés, F3 (L2 a mk IP-n) → tulajdonos: marad.
- 2026-10-12 00:48 — hardening kész, sshd lockout-védelemmel (második session, `ubuntu` letiltva).
- 2026-10-12 01:2x — edge, Postgres, Postal ×2 egészséges; Postal-provisioning kész.
