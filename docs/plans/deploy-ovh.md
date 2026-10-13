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
12. **Resource limitek**: Postgres 6g · app 2g · worker 2g · **worker-imports 3g** · scheduler 512m ·
    web 256m · Redis 1.25g · MariaDB 2g · Postal worker 1.5g/pool · Postal web/smtp 768m · edge 256m —
    egy elszálló worker nem viheti el az MTA-t.
    **Memória-költségvetés (frissítve 2026-10-13, v1.1 import-izoláció után):**
    - Egy szín (`compose.prod.yml`): app 2g + worker 2g + worker-imports 3g + scheduler 0.5g + web 0.25g
      = **7.75g**. A blue/green váltás alatt a régi és az új szín együtt fut: **+7.75g** a váltás idejére
      (a review „+6g” becslése a scheduler/web nélkül számolt). A migrációhoz egyszer futó `ops` +1g.
    - Közös (színfüggetlen): Postgres 6g, Redis 1.25g, MariaDB 2g, Postal worker 2 × 1.5g, Postal
      web/smtp, edge 0.25g, **Gotenberg 1g (v1.1 modul 8, 17. pont)**.
    - **worker-imports (3g) belső költségvetése (D224 → D259, modul 5 r10–r11):** 4 worker-folyamat
      (imports 2 + imports-inspect 2; mindegyik `memory_limit` 256 MB a php.ini-ből), plusz a Horizon
      master (`-d memory_limit=512M`, a valóságban ~50 MB) és a 2 supervisor (~50 MB-onként). Minden
      workernek legfeljebb EGY izolált XLSX-olvasó gyereke fut (D220): címtér-limit **448 MB**
      (`AUDIENCE_IMPORT_XLSX_CHILD_AS_MB`, Linuxon `ulimit -v`; a libxml memóriája is ebbe számít),
      `memory_limit` 256 MiB, CPU-idő `ulimit -t` (a keret + 10 mp). Legrosszabb eset: 4 × 256 MiB +
      ~150 MiB (BECSLÉS: master + 2 supervisor) + 4 × 448 MiB = **2966 MiB ≈ 2,90 GiB < 3 GiB (3072 MiB,
      a compose `mem_limit: 3g`)**. A `DeployArtifactsTest` a `memory_limit`-et a `docker/php/php.ini`-ből,
      a `mem_limit`-et a `deploy/compose.prod.yml`-ből olvassa. A mért gyerek-csúcs (~495 MB, review r11) így a
      GYEREKET állítja meg (`resources` elutasítás), nem a konténert — a cgroup OOM-killer nem viheti
      el a supervisorokat. Az image buildje igazolja, hogy a `ulimit -v` elfogadott ÉS érvényesül
      (Dockerfile, D222/D260). A gyerek minimális környezetet kap (titok nélkül) és a szülő fd-ijét
      nem örökli (D255–D256).
    - A box (mail-ovh-01): **31 GB RAM, ~28 GB szabad — mérve 2026-10-13.** A limitek PLAFONOK, nem
      foglalás: a tényleges csúcs a váltás alatt jóval a limitösszeg alatt van, de a limitösszeg a
      váltás pillanatában megközelíti a box méretét — új szolgáltatás vagy limitemelés előtt ezt a
      költségvetést újra kell számolni.
    - A Horizon-környezetek (review B5): a `worker` (`production`) csak sending + background +
      campaign-start, a `worker-imports` (`imports`) csak imports + imports-inspect supervisort indít —
      a `config/horizon.php` `defaults`-a ezért üres (a Horizon minden környezetbe beolvasztaná).
13. **Merge**: a CLAUDE.md „PR-only, auto-merge soha” és a hook a `main`-re pusht tilt; a prompt
    kifejezetten merge-et kér. Megoldás: PR → teljes CI zöld → `gh pr merge --merge` (merge-commit,
    = `--no-ff`) a tulajdonos promptbeli utasítására; nincs GitHub auto-merge és nincs direkt push.
14. **Bounce-teszt a seed-szabály betartásával**: harmadik fél postafiókja nem kap levelet; a
    teszt egy saját, nem létező címre megy (`bounce-test@rp-tx.ventmail.app`, a saját MX-ünk
    utasítja el 5xx-szel), az allowlistbe ideiglenesen felvéve, utána eltávolítva.

15. **Független review (venture-reviewer + venture-security) után javítva:** `t:variables` —
    csak rendereléshez használt, titkosított, a küldés végén törölt sablonváltozók (a ventSSO
    reset/magic-link/OTP tokenje így nem kerül eseménybe, webhookba, `/v1/events`-be); a
    ventSSO driver csak ezt küldi. `TRUSTED_HOSTS` + `ventmail-web` (különben élesben 400 a
    Postal-webhookra). CLI-tagság auditált. `MessageDelayed` ≠ soft bounce. IPv6 /64 vödör.
    MariaDB-jelszó `MYSQL_PWD`-ben; a Postal üzenet-DB 3 napos és nincs off-site mentésben.
    **Tudatosan nem változott:** `deploy` NOPASSWD sudo (trust-box paritás, kulcs-only SSH,
    admin-IP-re szűkítve); tag-alapú pull (a tagot csak a CI építi); kimenő 465/587 a
    konténerekből nincs tiltva (a pool-reputációt csak a 25 érinti).

16. **Megosztott fájltár (v1.1 modul 8, D227 / D242).** A `compose.prod.yml` minden PHP-szerepe (app,
    worker, worker-imports, scheduler, ops) ugyanazt a `storage/app`-ot látja két KÜLSŐ, nevesített
    volume-on: `ventmail-storage` → `/app/storage/app` (privát: import-feltöltés, riport-PDF, logó) és
    `ventmail-storage-public` → `/app/storage/app/public` (sablon-képek, D10). A `web` CSAK a public
    volume-ot kapja, csak olvasva; a Caddy `/storage/*`-ot sima fájlként szolgálja ki (PHP nélkül,
    `Cross-Origin-Resource-Policy: cross-origin`, mert a képeket a levelezőkliensek más originről töltik).
    Két volume egy helyett: így a web a privát fájlokat (importok, riportok) a fájlrendszerében sem látja.
    A `deploy.sh` 2. lépése `docker volume create`-tel hozza létre (idempotens), semmi nem törli; mindkét
    szín ugyanazt csatolja (a váltás fájlt nem veszít). Az üres volume-ot a Docker az image tartalmával
    tölti fel (10001-es tulajdonos); rossz tulajdonosnál az entrypoint `ensure_storage` megállítja a
    konténert. **Ideiglenes fájlok:** az import openspout-temp (`import-tmp/<uuid>`) és az izolált
    XLSX-gyerek (D220/D222) temp-je a `local` diszken, tehát a megosztott volume-on van — szándékosan:
    ugyanaz a worker írja és olvassa, a purge (1 óránál régebbi) a volume-on takarít; a konténer-lokális
    `/tmp`-n egy OOM-maradék a konténer élettartamáig foglalna. A `storage/framework` (cache, view)
    konténer-lokális marad.
    **Átállás (D254; a 2. biztonsági kör után D277–D281):** a D227 előtti színek a sablon-képeket és a
    privát fájlokat a SAJÁT konténer-rétegükben tartják. A `deploy.sh` 7. lépése ezért: drain →
    a régi szín **`stop`**-ja (a workerei és a schedulere nem futnak tovább — nincs dupla ütemezett
    job, nincs új queue-felvétel; a leállított konténer rétege megmarad, a `docker cp` olvassa) →
    egyszeri másolás → ellenőrzés → `down`. A másolás:
    - a forrás szigorúan keresett: hibás `docker ps` vagy „van konténer, de nincs `app`" → hiba;
    - **szabadhely-ellenőrzés** a staging előtt: a volume-on legalább 2× a forrás + 64 MB
      (`D227_MIN_FREE_FACTOR`, `D227_MIN_FREE_MARGIN_KB`); különben semmi nem íródik;
    - a már ott lévő (az új szín által írt) fájlok listája (`.d227-preexisting`) CSAK az első kísérletben
      készül, a stagingen kívül, és újrapróbáláskor nem számolódik újra;
    - minden fájl **atomian** másolódik (`<név>.d227-part` → `mv`), az újrapróbálás előbb törli a
      maradék `*.d227-part`-okat — csonka fájl soha nem kerül a végleges névre;
    - ütközésnél (más tartalom ugyanazon az úton) az új szín változata marad, a régi a
      `.d227-conflicts/` alá kerül (naplózva); a kihagyott szimlinkek száma naplózva;
    - az ellenőrzés a migrált fájlokat **bájtra** (`cmp`) veti össze; maradék `*.d227-part` = hiba;
      csak ezután íródik a `.d227-migrated` jelölő (`migrated=… files=… bytes=…`), és csak ezután `down`.
    - Hibánál a staging azonnal törlődik, a régi szín **leállítva, de nem eltávolítva** marad
      (a fájljai egyetlen példánya), a `.d227-pending` minden deployt tilt; javítás után
      `./deploy.sh --migrate-storage` fejezi be. Ha közben a régi színt ideiglenesen vissza kell
      indítani (pl. kiszolgálásra): `docker compose -p ventmail-<szín> -f compose.prod.yml start` a
      `/srv/apps/ventmail`-ből — a `--migrate-storage` ilyenkor is működik (előtte újra leállítja).
    - Élesítés előtt: `docker exec <app> du -s /app/storage/app` és `df` a docker-gyökéren.
    Ellenőrzés: `docker run --rm -v ventmail-storage:/d alpine cat /d/.d227-migrated`.
    **Rollback:** egy D227 előtti tagre visszalépéskor is a mostani (D227-es) `compose.prod.yml`,
    `compose.data.yml`, `compose.render.yml` maradjon a boxon (a volume-ok és a render-háló nélkül a
    régi kiadás fájljai újra konténer-lokálisak lennének). **Renderer-env:** egy elgépelt vagy élesben
    `fake` `ANALYTICS_PDF_RENDERER` az alkalmazás indulását állítja meg (D263) — minden szerepkörét;
    a deploy preflightja (`config:cache`) ezt a váltás ELŐTT kiszűri.

17. **PDF-renderer sidecar (v1.1 modul 8, D238).** `deploy/compose.render.yml` (projekt `ventmail-render`,
    színfüggetlen, a `deploy.sh` 2. lépése indítja és health-re vár): Gotenberg `8.37.0-chromium`, tag ÉS
    digest szerint pinelve. Csak a `ventmail-render` (`internal: true`) hálón — nincs host-port, nincs
    kimenő forgalom; csak az `app` és a `worker` csatlakozik. Chromium csak `file:///tmp/*`-t tölthet,
    `downloadFrom`/webhook/metrika kikapcsolva, a böngésző-állapot konverziónként törlődik. Read-only root,
    tmpfs `/tmp` + `/home/gotenberg` (enélkül a Chromium nem indul read-only rooton — mérve), `cap_drop: ALL`,
    `no-new-privileges`, `mem_limit 1g`, `cpus 1.0`, `pids_limit 512`. Az image GPL-es segédeszközöket is
    tartalmaz (pl. pdftk) — külön konténerben, HTTP-n át használjuk, a ventMail kódjával nem linkelődik.
    **Memória:** a 12. pont költségvetése +1 GB (Gotenberg, színfüggetlen, a váltáskor nem duplázódik);
    a worker `reports` supervisora (1 folyamat, 256 MB) a worker 2g-s keretén belül fut.

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
- 2026-10-12 01:4x — PR-ok: ventmail#37, venture-infra#11, ventsso#18. Review → javítások.
- 2026-10-12 01:4x — a ventmail CI a `VENTURE_PACKAGES_TOKEN` lejárt secretje miatt nem fut le
  (Bad credentials a privát csomagok klónozásánál) → emberi lépés, a GHCR-tokennel együtt kérve.
