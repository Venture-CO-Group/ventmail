# HUMAN-QUEUE — ventMail

> Emberi döntést vagy kézi lépést igénylő tételek, prioritás szerint. A deploy-ovh futás
> (2026-10-12) és a v1.1 futás (kiadás: `v1.2.0`) óta. Részletek: `docs/plans/deploy-ovh.md`,
> `docs/plans/v1.1.md` (D-számok), `venture-infra/docs/RUNBOOK-mail.md`, `docs/screenshots/v1.1/README.md`.
> Jelölés: `[ ]` nyitott · `[x]` lezárt (forrással). A tételek egyszer szerepelnek; a hivatkozott Dxxx a döntés a `docs/plans/v1.1.md` 1b. szakaszában.

## P0 — az éles indulás előtt / közben

### Küldési éles üzem (deploy-ovh)
- [ ] **Google Postmaster Tools, Microsoft SNDS + JMRP, Yahoo CFL** regisztráció
      (`notify.ventureco.app`, `ventmail.app`, mindkét IP). A JMRP/CFL jelentés-címének bekötése a
      complaint → suppression útba (a Postal nem dolgoz fel ARF-et).
- [ ] **Riasztási csatorna** (RAID `mdadm-event`, `dnsbl-check` failed unit, OVH Vade-tiltás, deploy-hiba).
      Amíg nincs: journal + `systemctl --failed`.
- [ ] **Backup-cél**: OVH Backup Storage vagy Wasabi (Object Lock) + `AGE_RECIPIENT` a
      `/srv/backups/backup.env`-be. Addig a mentés csak helyi (ugyanazon a RAID-en!).
- [ ] **Mail-tester** pont (ha a futáskor nem volt cím): egy `test-…@srv1.mail-tester.com`
      cím ideiglenesen az allowlistbe (`@srv1.mail-tester.com`), tesztlevél, ≥ 9/10, majd ki.

### A `v1.2.0` kiadás előtt / közben (v1.1 futás)
- [ ] **Flaky teszt determinisztikussá tétele (D297):** `StoredTemplateScreenTest` „diff a szabad
      memóriához igazodik" (`VersionDiff::regionLimit` a `memory_get_usage()`-ből számol, a teszt újraszámolt
      limittel hasonlít) — a változtatások nélkül is bukik önállóan. Gyengítés nélkül javítandó.
- [ ] **Első deploy a `ventmail-render` stackkel:** a `deploy.sh` a 2. lépésben indítja a Gotenberg
      sidecart (`deploy/compose.render.yml`) és létrehozza a `ventmail-storage` / `ventmail-storage-public`
      volume-okat. A boxra a `compose.render.yml`-t is fel kell másolni (a `deploy/` mellé), és a meglévő
      `.env`-be `set-env.sh`-val: `ANALYTICS_PDF_RENDERER=gotenberg`, `GOTENBERG_URL=http://gotenberg:3000`,
      `ANALYTICS_PDF_TIMEOUT=60`. Deploy után: `php artisan analytics:render-smoke` (ops) → `OK … oldal`.
- [ ] **RUNBOOK-mail „Első telepítés" fájllistájába felvenni a `compose.render.yml`-t** (venture-infra repó;
      a `compose.data.yml` mellé). Rollback-jegyzet is: régebbi tagre visszalépéskor is a mostani
      `compose.prod.yml` / `compose.data.yml` / `compose.render.yml` maradjon a boxon (különben a fájlok
      újra konténer-lokálisak lennének).
- [ ] **D227-átállás ellenőrzése (automatikus, D254/D277–D300):** élesítés ELŐTT `docker exec <app> du -s
      /app/storage/app` és `df` a docker-gyökéren (a volume-on ≥ 2× + 64 MB kell). Az első D227-es deploy a
      régi színt drain után leállítja, a fájljait atomian átmásolja és bájtra ellenőrzi, csak utána távolítja
      el. Utána: `docker run --rm -v ventmail-storage:/d alpine cat /d/.d227-migrated` (fájlszám, bájt), és
      ha van `/d/.d227-conflicts/`, annak átnézése. Ha a deploy `storage migration … FAILED`-del állt meg: a
      régi szín LEÁLLÍTVA, de megvan — ne deployolj újra; javítás után `./deploy.sh --migrate-storage`.
      Megjegyzés: az éles box 2026-10-14-i olvasása szerint az aktív (green) szín `/app/storage/app` 0 fájl
      (12 KB), így az első migráció gyakorlatilag üres.
- [ ] **Helyi dev DB: `php artisan migrate:fresh --seed` (D271 miatt):** a `2026_10_13_140000` migrációt
      helyben szerkesztettük (kiadatlan, `feat/v1.1`-en; éles DB-n soha nem futott). Csak a HELYI dev DB-n.
- [ ] **Dev DB teszt-adat takarítás (`del.php`) — NEM futott le, tulajdonosi beleegyezés kell:** a prompt 9.
      lépésének „teszt-adat takarítás" része (dev-adatbázis). A szkript tartalmát a források nem rögzítik —
      futtatás előtt a tulajdonos nézze át és döntsön. (A screenshotok tiszta demó-DB-n készültek, ezt nem igénylik.)
- [ ] **ventSec teszt-DB szivárgás (a gép lemezét kétszer is megtöltötte):** a ventSec tesztjei
      `ventsec_scratch_*` adatbázisokat szivárogtatnak (`ventsec_pgdata` ~85 GB; a lemez 235 MB-ra telt, az
      OrbStack leállt). A takarítást a tulajdonos futtatja (`drop-ventsec-scratch.sh`: csak a `ventsec_scratch_*`
      DB-ket dobja, a konténert utána leállítja). A ventSec-repóban a teszt-takarítás javítandó (ventSec-feladat).
- [ ] **Edge (venture-infra) body-limit ≥ 140 MB:** az edge Caddy-n nincs `request_body` limit (Caddy
      alapértelmezés: korlátlan; ellenőrizve 2026-10-12) → az app-szintű plafon (D67) a mérvadó. Ha az edge
      valaha limitet kap, ≥ 140 MB kell az import-útvonalra.
- [ ] **PNG screenshotok mérete / LFS:** `docs/screenshots/v1.1/` ~23 MB (102 kép), tömörítő nincs a gépen.
      Döntés: marad a repóban, vagy Git LFS / optimalizálás (pl. `oxipng`).

## P0 — Unified UI v2 pilot (branch `feat/unified-ui-v2`, terv: `venture-design/REDESIGN-PLAN.md`)
- [ ] **Grafikon-, nyomat- és branding-paletta (tulajdonosi döntés):** a design 2.0 a 0.x primitíveket
      (`--vt-color-accent-300…600`, `cyan-*`, `violet-*`, `neutral-0…900`, `--vt-signature-gradient`) nem
      szállítja. Érintett: `chartTheme.ts`, `report/*`, `Analytics/Branding.vue` + `brandContrast.ts`
      (a kontraszt-jelzés üres — `e2e/branding.spec.ts` bukik), `BrandColor` (`BrandColorTest` bukik),
      `editor.css`, `VmCoverPreview`, `VmHeatmap`, `VmBarList`, `DomainStats`, `glow.css`. Döntés (2026-10-16):
      Figma `chart/*` + `print/*` változók a 0.x értékekkel → design 2.1.0.
- [x] **venture-ui 2.1.0 kiadva** (PR #14, `v2.1.0`, tulajdonosi engedéllyel, 2026-10-16); a ventMail `^2.1.0`, a lockfile a regiszterből.
- [x] **GitHub Packages „Manage Actions access":** a `ventmail` repó Read joga a `@venture-co-group/design` és `/ui` csomagon (2026-10-16; a #51 CI-ja zöld).
- [ ] **Értesítés-funkció (csengő):** a Figma felső sávjában van, a ventMailnek nincs ilyen funkciója —
      a csengő nem kerül ki, amíg nincs mögötte valami.
- [ ] **Hiányzó szerkesztő-ikonok** a Figma `icon/*` készletben (a `VmIcon` a szerkesztő ikonjait még
      helyben rajzolja).
- [ ] **Figma-eltérések (design-jelentés):** a GlowLine helye, a táblázatfejléc magassága (35 vs 40),
      a Billing kvóta-sáv `app/mail` színnel (a 2. szabály szerint csak AppIcon / GlowLine).

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
- [ ] **Kampány-megnyitás trigger / Apple MPP:** az Apple MPP / képproxy gépi megnyitásai is beléptetnek
      a journey-be — maradjon-e a megnyitás triggerként, vagy csak a kattintás.

## P2 — termékdöntések (tulajdonos)
- [ ] **Verified-consent import → `confirmed` consent?** Ma a kontakt `subscribed`, de a `consents` sor
      `pending` marad (D30, golden rule 4), így a 3. kapu marketingre zár megerősítésig. Ha legyen
      `confirmed`, tulajdonosi (jogi) döntés.
- [ ] **Bekötetlen journey-triggerek:** `tag_added`, `tag_removed`, `field_changed` — a forrásnak ma nincs
      egykontaktos hívója (a címkézés tömeges `TagOperations`, a mezők importból jönnek). A vásznon
      „hamarosan", a validátor nem engedi publikálni (D152, D162). Döntés: tüzeljen-e tömeges műveletből
      (tömeges beléptetés!) vagy csak egykontaktos szerkesztési útról.
- [ ] **„Becslés nem elérhető" → consent-előellenőrzés kimarad (D119):** a checklist QueryBudget-túllépésnél
      nem blokkol (a PreSendGate dönt). Termékdöntés: elég-e, vagy blokkoljon / figyelmeztessen.
- [ ] **Megerősítő levél újraküldése (DOI resend):** a DOI-plafon (D117/D123: cím-hash 3/nap, cím 10/nap)
      miatt levél nélkül maradt pending kontaktoknak ma nincs újraküldés-út. Kell egy „megerősítő levél
      újraküldése" művelet.
- [ ] **Sablon-pillanatkép / verzió-pin ütemezéskor:** az élő `template->html` olvasódik küldés közben; a
      D122/D144 zárak csak a módosítást védik. Döntés: pillanatkép vagy verzió-pin a kampányon/journey-n.
- [ ] **Aktív sablon írásvédett jelzése a szerkesztőben:** a D143 sáv megvan; a jelzés a sablonlistában /
      kampányon még nincs.
- [ ] **Workspace-beállítás oldal:** időzóna (`settings.timezone`) és postai cím (`settings.postal_address`)
      ma UI-ból nem állítható; a D64 helyőrző postai cím miatt a kampányindítás blokkol, amíg nincs valós cím.
- [ ] **Űrlaponkénti origin-lista (frame-ancestors):** a `/f` oldal `frame-ancestors *` (D92; a review N2-jét
      nem vettük be). Ha kell szűkítés: űrlaponkénti engedett origin-lista.
- [ ] **`/w/` süti-mentes tracking host:** a webes változat (`/w/{token}`) ma az app hosztján él; külön,
      süti-mentes hoszt a tracking-nek.
- [ ] **`messages:send:marketing` scope szándékosan nem kiadható** (D170) — megerősítendő, hogy ez marad.

## P2 — journey vászon (v1.1 · 6. modul)
- [ ] **RUNBOOK-mail kiegészítése (venture-infra repó) — VISSZAÁLLÁS v1.1 előttire:** a régi kód a
      vászon lépéseit nem ismeri (`StepType::from()` ValueError az új típusokon, a `wait` másként
      vár, a szüneteltetett journey futásait kilépteti). Visszaállítás ELŐTT minden gráf-alapú
      journey-t szüneteltetni kell (`journeys.current_version_id is not null and status = 'active'`
      → `paused`), és a futó gráf-futásokat ellenőrizni (`journey_runs.journey_version_id is not null`).
      Az előre-telepítés után folytatható (publikálás változatlan gráffal = folytatás).
      A CÉL: visszaállás után a régi kód a journey-szintű célt olvassa, amit az új publikáló már nem
      frissít (a cél a verzióban él, D149) — a vásznon publikált journey-k célja a régi kódon nem érvényesül.
- [ ] **Éles migráció CONCURRENTLY indexszel:** a `journey_runs_open_step_idx` (D133) `CONCURRENTLY`
      épül — éles migrációnál tranzakción kívül futtatandó / ellenőrzendő.
- [ ] **Author szerepű e2e-belépés (SSO-seed):** a csak olvasható sablonszerkesztő (D143) Playwright-jéhez
      kell egy `author` szerepű belépés; ma csak unit/feature-teszt fedi.
- [ ] **Tömeges beléptetés** a `tag_added` triggerhez (lásd bekötetlen triggerek) — csak a döntés után.

## P2 — analitika, PDF-riport, branding (v1.1 · 8. modul)
- [x] **Memória-keret:** +1 GB a Gotenbergnek (`mem_limit 1g`) + a worker `reports` supervisora (256 MB a
      2g-n belül). A deploy-ovh 12. pont költségvetése **elfogadva (D252, a vezénylő/orchestrator
      jóváhagyta)**: egy szín 7,75g, blue/green alatt +7,75g, + Gotenberg 1g ≈ 16,5g a 31 GB-os boxon
      (mérés 2026-10-13: ~28 GB szabad). *A tulajdonosi tudomásulvétel külön nem rögzített — a tulajdonos
      kérésre felülbírálhatja.*
- [ ] **Valós memóriacsúcs mérése blue/green váltás alatt:** a 16,5g becslés a limitek összege; az első éles
      váltáskor `docker stats` / cgroup-csúcs mérendő (az `ops` konténer és a Gotenberg is).
- [ ] **ventVault:** a riport-PDF (`reports/…`) és a workspace-logó (`branding/…`) ma a privát megosztott
      helyi volume-on él — a ventVault fájltár élesítésekor oda költözik (`ReportArtifactStore`,
      `BrandingService`). Ugyanide: az import-feltöltés (`imports/{ws}/`, D39) és a sablon-képek
      (`VentVaultAssetStore`, D10; CDN-variánsokra az URL-alapú keresés bővítendő, D147 N-d).
- [ ] **Saját (OFL) betűk a riportban (D234):** a Gotenberg hálózat nélkül fut, a Google Fonts nem
      érhető el — a PDF a rendszer-fallback betűt használja. Döntés: Inter / Space Grotesk / JetBrains Mono
      OFL-fájlok a riport-csomagba + self-hosted betűk a `@venture/design`-ban (licenc-felülvizsgálat: OFL ≠
      MIT/BSD/Apache, D178-kivétel kell).
- [ ] **Gotenberg-image frissítés:** a pinelt `8.37.0-chromium@sha256:0d28ae9a…` (2026-10-13). Új verziónál
      a CI `gotenberg-contract` jobja és a deploy-teszt digest-egyezése vezet; a Chromium-biztonsági
      frissítések miatt negyedévente.
- [ ] **ventPay `log_retention_tier`:** amíg a ventPay nem él, az érték configból jön (`FakeVentPayClient`);
      a riport nem ventPay-entitlement (D236).
- [ ] **Domain-statisztika visszamenőleges kitöltése:** a `domain_daily_stats` (D175) és a bounce-osztály
      oszlopok (D231) csak a bevezetés utáni eseményeket számolják; régi adatra nincs backfill.
      Döntés: kell-e egyszeri visszatöltés a `message_events`-ből (retenciós ablakon belül).
- [ ] **Szerveroldali hőtérkép-PNG (D230)** és **`contacts.cleaned_at` (D232)** — backlog, ha a churn/hőtérkép PDF-be kell.

## P2 — integrációk, SDK, dokumentáció
- [ ] **`@venture/sdk`:** (a) `/v1/suppressions` metódusok + az additív `detail` mező (D174, D206) —
      OpenAPI ↔ SDK drift = 0 (golden rule 7); (b) hibakód-unió: `SENDING_RESTRICTED`, `CHANNEL_DISABLED`
      (korábbi tétel), `IDEMPOTENCY_KEY_RESERVED`, `SUPPRESSION_IRREVOCABLE`, `UNKNOWN_SUPPRESSION`.
- [ ] **API-referencia csak magyarul:** az in-app fejlesztői doksi (D177) az OpenAPI szövegeiből épül, amelyek
      magyarok. Döntés: angol OpenAPI-leírások (fordítás) vagy külön nyelvi réteg.
- [ ] **ventSSO `picture` claim** tárolása + CSP `img-src` az avatar-képhez (D22; ma monogram).
- [ ] **Meilisearch** (kontakt-kereső) és **Reverb** (élő kampány-progress) — lásd P3.
- [ ] **Nem-ASCII kis/nagybetű-független keresés** a szegmens-szabályokban (C-locale `lower()`).
- [ ] **`/u` (leiratkozás) oldalak régi stílusa:** RFC 8058 viselkedés érintetlen; csak vizuális igazítás.
- [ ] **Űrlaponkénti statisztika** és **kampány-másolás**, **kontakt aktivitás-oszlop** (a M5-ből kimaradt).
- [ ] **Suppression-lista export** (M7-ből kimaradt), a webhook-napló késleltetés-jelzése régi sorokon.

## P2 — üzemeltetés / CI / tesztinfra
- [ ] **`--parallel` a commit-kapu hookban (D99):** a soros suite 330–350 s; ma `composer.json`
      `process-timeout: 900` a kerülő. Hosszabb távon `php artisan test --parallel` a hookban.
- [ ] **Teszt-DB elkülönítése párhuzamos agentek között (D17):** a `ventmail_test` közös (phpunit.xml-ben
      rögzítve); egyszerre egy fejlesztő agent futhat. Agensenként külön DB (pl. `ventmail_test_<n>`).
- [ ] **Worker `background` supervisor timeout 300 vs `retry_after` 90** (M3 megjegyzés): ellenőrizni, hogy
      a `background` sor kapcsolatán a `retry_after` > timeout (D111/D246 mintájára). Ellenőrizve 2026-10-14:
      `config/horizon.php` background timeout 300, a `redis` kapcsolat `retry_after` 90 → egy 90 mp-nél hosszabb
      háttérjob kétszer indulhat; v1.1 előtti, a mai jobok rövidek — külön kapcsolat kell (`redis_background`).
- [ ] **Éles cgroup-mérés (opcionális):** legitim nagy XLSX (64 MB sharedStrings, 1M sor) Linux-képben
      mérve (gyerek-csúcs ~32–59 MB, 448 MB AS-be bőven belefér — **a korábbi „élesítés előtti" tétel
      lezárva**); csak éles `worker-imports` cgroup-mérés maradt opcionális. Szükség esetén
      `AUDIENCE_IMPORT_XLSX_CHILD_AS_MB` ≤ ~600 (a költségvetés < 3 GiB, D259).
- [ ] **Bináris-hely lista bővítése / hiteles Excel-fixture (D197):** ma csak `xl/activeX/*.bin` (+ `xl/media/`,
      `printerSettings/`, `embeddings/`, `docProps/thumbnail.*`, `xl/model/item.data`). Ha valós Excel más
      bináris részt hoz, bővíteni. **Ember tölti fel** egy valódi, Excel-mentett regressziós fixture-t.
- [ ] **Polish-NIT-ek:** a modul 5, 7 és 8 záró NIT-listái (a futás scratchpadjében: `m5-final-nits.md`,
      `m7-final-nits.md`, `m8-final-nits.md`) — a vezénylő döntse el, mi kerül a `v1.2.1`-be. Köztük: a D298
      (két pre-D227 szín) elhalasztott eset; élesben nem érhető el.
- [ ] **Code-review eszközök:** coderabbit, aikido, nightvision, pr-review-toolkit — CLI-ből nem érhetők el;
      a PR-eken kézzel futtatandók.

## P2 — `@venture/ui` / `@venture/design` hiányzó elemek (jelentve; a ventMail lokálisan pótolja)
- [ ] **Tokenek:** `--vt-bp-*` töréspont-tokenek (D18).
- [ ] **Layout / űrlap:** VtCard gap, form-szekció / -sor / -csoport, VtTable ellipszis + vízszintes görgetés,
      oldalfej (eyebrow/lead), link-gomb, VtSelect/VtCheckbox attribútum a wrapperen, VtSegmented ne tördelődjön.
- [ ] **Shell:** ikon, dropdown, nav-panel, workspace-váltó, nyelvválasztó, felhasználói menü (lokális `Vm*`).
- [ ] **Import:** progress, dropzone, choice-card, stat, tag-input.
- [ ] **Szerkesztő:** vászon / húzható lista, színválasztó, toolbar, csúszka, kapcsoló, fájlfeltöltő, ikonos szegmentált választó.
- [ ] **Studio:** bar-list, growth-bars, page-header, status-badge, accordion, feltétel-csoport, szűrő-pill.
- [ ] **Journey:** folyamat-vászon, csomópont-kártya, minimap/zoom-vezérlő, húzható paletta-elem.
- [ ] **Platform:** copy-button, secret-reveal, state-badge, split, vonaldiagram, trace-idővonal, DNS-rekordtábla.
- [ ] **Analitika:** chart (vonal/oszlop/kör/tölcsér) + PNG-export menü, A4-borító-előnézet.
- [ ] **Dátumválasztó (`VtDatePicker`)** profilformátummal (a natív `date` mező a böngésző formátumát követi).

## P3 — UI-backlog (screenshot README + D292/D297; nem blokkoló)
- [ ] **Natív dátummezők** (import hozzájárulás-dátuma, napló Ettől/Eddig) → profilformátumú választó (fent).
- [ ] **Mobil táblák kártyanézete** (Áttekintés, Közönség, Kampánylista, Napló, Trace, Infrastruktúra) — ma vízszintes görgetés.
- [ ] **Kontakttábla gazdagsága:** 50 soros lap (mockup: 8 + lapozó), avatar, címke-chip, aktivitás-sáv.
- [ ] **Fejlesztői doksi:** a mockup paramétertáblája, nyelvi kódminta-fülei és webhook-séma kártyái.
- [ ] **Demó-seedek:** API-kulcs, webhook, tiltólista, egyéni mező, űrlap, IP-pool (a képek üres állapotot mutatnak).
- [ ] **Kampány-analitika:** kicsi KPI-számok (nem display betű); a hőtérkép %-jelvényei a gombfeliratra lógnak.
- [ ] **Analitika-grafikonok `MM-DD` kategóriái és a riport-előnézet ISO dátuma** → profilformátum (D297 a kiadás előtt tervezte; ha nem készül el, ide marad).
- [ ] **PreSendGate-kártya** 3 ellenőrzést mutat a mockup 6 kapuja helyett.
- [ ] **Inbound route-ok „hamarosan" képernyő:** beégetett magyar feliratok és nyers állapotkód (D297: i18n a kiadás előtt).
- [ ] Beállítások menü: hiányzó Számlázás / Auditnapló / Integrációk pont; sablonok bélyegképe (renderelt előnézet);
      Űrlapok oldalfej; Webhookok újrapróbálkozási menetrend kártya; Branding „Eltávolítás" gomb + kontraszt-szöveg;
      Profil: kapcsolók (jelölőnégyzet helyett), „Kampány elküldve" / „Domain-hitelesítés változása" értesítés, formátum-magyarázat.

## P3 — v1-ből kimaradt képességek
- [ ] SMTP submission 587/465 élesítése (TLS `smtp.ventmail.app`, ufw + DNS, rate-limit).
- [ ] Inbound MX (a 25-öt ma a Postal kapja — külön IP vagy port-szétválasztás kell).
- [ ] Ügyféldomaines tracking és MTA-STS: on-demand TLS az edge-en + `TRUSTED_HOSTS` bővítés.
- [ ] Ügyféldomainek szinkronja a Postalba (saját Return-Path `psrp-tx/mk.<domain>` ügyfeleknek;
      ma a `use_for_any` domain hitelesít, a Return-Path `rp-tx.ventmail.app`).
- [ ] Meilisearch (kontakt-kereső) és Reverb (élő kampány-progress).
- [ ] A ventMail saját rendszer-levelei (meghívó stb.) a saját motoron (`MAIL_MAILER=log` ma).
- [ ] `@venture/sdk` hibakód-unió: `SENDING_RESTRICTED`, `CHANNEL_DISABLED` felvétele (bővítve: lásd P2 SDK).

## Lezárt a v1.1 futás alatt (forrással)
- [x] **Box RAM a +3g `worker-imports` konténerre:** 31 GB, ~28 GB szabad (2026-10-13); a limitek összege blue/green átfedéssel is belefér (D112, D252).
- [x] **php-fpm upload / post limit ≥ `AUDIENCE_IMPORT_MAX_MB` (100):** php.ini 128M / 132M / `max_execution` 120, konzisztencia-teszt (D53).
- [x] **PHP `zip` és `gd` az image-ben:** Dockerfile (modul 3, 2. kör); GD-újrakódolás metaadat nélkül (D76).
- [x] **Éles képkiszolgálás (`/storage`):** megosztott `ventmail-storage-public` volume + Caddy `handle_path /storage/*` (D242); a `TEMPLATE_ASSET_DRIVER=null` ideiglenes állapot felülvizsgálandó (éles deploy után ellenőrizni, hogy a képek kiszolgálódnak).
- [x] **Horizon `imports` supervisor / külön worker:** D38, D100, D112, D121.
- [x] **`LicenseTest` (a plan által hivatkozott):** `tests/Feature/Skeleton/LicenseTest.php` létezik (megengedett: MIT, BSD-2/3, Apache-2.0, ISC, 0BSD).
- [x] **Pint CI-hiba a release előtt:** a polírozás végén pint PASS (a2e7326e).
- [x] **Nagy legitim XLSX Linuxon, 448 MB AS-sel:** mérve, belefér (D259; modul 5 11. kör).
