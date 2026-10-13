# ventMail v1.1 – képernyőképek (app vs. mockup)

Generálva: 2026-10-13, **tiszta demó-adatbázison** (D271 / D284), a D284 UI-javítások után.
Parancs: `npm run screenshots` (`e2e/screenshots.spec.ts`, csak `SCREENSHOTS=1` mellett fut).

| Mappa | Tartalom | Darab |
|---|---|---|
| `desktop/` | app, 1440×900, teljes oldal (a menük, a szerkesztő és a vászon csak a nézet) | 31 |
| `mobile/` | app, 390×844 | 31 |
| `mockup/desktop/` | `docs/mockups/ventmail-v1.1.html` megfelelő képernyői | 20 |
| `mockup/mobile/` | ugyanaz mobilon | 20 |

## Adat

- A helyi dev-adatbázis `php artisan migrate:fresh --seed` után üres (a `DatabaseSeeder` szándékosan
  nem seedel), utána CSAK a spec saját demó-seedjei futnak: `/dev/seed-logs`, `/dev/seed-studio`,
  `/dev/seed-analytics` (`pelda.hu` / `example.test` címek, a dev-felhasználó „Dev workspace"-e).
  E2E-maradék nincs. Az „E2E Studio x" nevű lista/sablon/szegmens a `/dev/seed-studio` saját demó-
  adata (a seed a futás-azonosítót a névbe teszi), nem korábbi tesztfutás maradéka.
- API-kulcs, webhook, tiltólista, egyéni mező, űrlap és IP-pool nincs seedelve: ezek a képernyők
  **üres állapotot** mutatnak, a sorok elrendezése így nem hasonlítható a mockuphoz.
- Az import-varázsló az előnézetig megy (kontakt nem jön létre). Az Infrastruktúra-képhez a dev-user
  ideiglenesen platform-admin, a `finally` visszaveszi.
- A mockup-képeken a mockup saját, lebegő képernyőváltó sávja is látszik — az nem az app része. A
  mobil teljes-oldalas képeken a rögzített felső sáv a kép közepén is megjelenhet (rögzítési
  artefakt).

## Mi változott a D284 óta (javítva, a képeken már így)

1. **Formázás:** minden szám, százalék, dátum és időpont a profil szám-/dátumformátumában és
   időzónájában (közös `useFormat`, `format` megosztott prop) — a grafikonok tengelyei és tooltipjei
   is. Az üzenet- és kontaktállapotok fordítva (`rejected` → „Elutasítva", `subscribed` → „feliratkozott").
2. **Közönség:** a listák és a szegmensek szerveroldalon lapozva (10/lap) és kereshetők.
3. **Kampány → Címzettek:** kereső + lapozó választó, a kiválasztottak chipként mindig látszanak.
4. **Journey-paletta:** a „Hamarosan" jelvény a név alatt, a név szóhatáron tördel.
5. **Áttekintés:** KPI-sor sparkline-nal és előző időszakkal, legutóbbi kampányok, kézbesíthetőség-
   kártya (bounce/panasz, SPF/DKIM/DMARC/tracking), teendők gombokkal, küldési-mód sáv, Import /
   Új kampány.
6. **DNS-tábla:** a név és az érték teljes hosszában tördelve (1440 px-en sem csonkol), másolás gombbal.
7. Mobil import-lépésjelző vízszintesen görgethető (korábban betűnként függőleges oszlopokra esett).

## Megfeleltetés és eltérések (MINDEN képernyő átnézve, desktop és mobil)

Jelmagyarázat: **✔** lényegében egyezik · **≈** a szerkezet egyezik, részletek eltérnek ·
**✘** érdemi eltérés · **–** nincs mockup-párja.

| App | Mockup | Desktop | Mobil | Eltérések |
|---|---|---|---|---|
| 01–03 shell menük | `shell` | ✔ | ✔ | A Platform-menü több pontot tartalmaz (SMTP, Tárolt sablonok, IP-poolok, Kézbesíthetőség); 900 px magas nézetben a panel alja görgetéssel érhető el. A Beállítások menüből hiányzik a mockup Számlázás / Auditnapló / Integrációk pontja (más helyen élnek). |
| 04 workspace-váltó | `shell` | ✔ | ✔ | — |
| 05 avatar-menü | `shell` | ✔ | ✔ | Nincs avatar-kép (monogram). |
| 06 nyelvválasztó | `shell` | ✔ | ✔ | — |
| 07 Áttekintés | `overview` | ≈ | ≈ | Minden mockup-blokk megvan. Az app a küldést megállító számokat (kvóta, domainek, tiltólista) és a legutóbbi üzeneteket is mutatja. Előző időszak nélkül a trend „Nincs előző időszaki adat". Mobilon a KPI-k egy oszlopban (mockup 2×2), a táblák vízszintesen görgetnek. |
| 08 Közönség | `audience` | ≈ | ✘ | KPI-k, növekedés, források, engagement, lapozott listák/szegmensek rendben. **Eltérés:** a kontakttábla 50 soros lap (mockup: 8 sor + lapozó), nincs avatar / címke-chip / aktivitás-sáv; az e-mail linkek natív kék, sötét háttéren gyenge kontrasztúak; mobilon csak két oszlop fér ki. |
| 09–11 import 1–3. lépés | `import-step1…3` | ≈ | ≈ | A lépésjelző sima sor (mockup: kártyák). A hozzájárulás dátuma natív dátummező — a böngésző locale-ját követi (`mm/dd/yyyy`), nem a profilt. A státuszjelvény nyers kód (`pending`). |
| 12 import 4. lépés | `import-step4` | ✔ | ≈ | Mobilon a minta-tábla utolsó oszlopa levágva; „kihagy" / „Kihagy" kis-nagybetű nem egységes. |
| 13 Sablonok | `templates` | ≈ | ≈ | A saját sablonok bélyegképe szürke helykitöltő (mockup: renderelt előnézet); a verziótörténet-panel nem a könyvtárban, hanem a szerkesztőben él; a kereső és az „Új sablon" gomb a fejlécben egymás alatt. |
| 14 Sablonszerkesztő | `editor` | ≈ | ≈ | Háromhasábos elrendezés, paletta, Desktop/Mobil/Sötét egyezik; a demó-sablon egyszerűbb; mobilon a fejléc három sorra bomlik. |
| 15 Kampánylista | `campaign` (lista) | ≈ | ✘ | Az app a listát és a checklistet külön oldalon mutatja (mockup: egy oldalon). A kampánynév natív kék link (gyenge kontraszt); mobilon csak a név-oszlop látszik, a szűrőfülek három sorba törnek. |
| 16 Kampány-checklist | `campaign` | ≈ | ≈ | Címzett-választó kereséssel/lapozással. A PreSendGate-kártya 3 ellenőrzést mutat a mockup 6 kapuja helyett, és a Teszt küldés / Ütemezés gomb a lépésekben van, nem a kártyán. „Szegmens építése" natív kék link. |
| 17 Journey-vászon | `journey` | ≈ | ✘ | A demó-journey 3 csomópontos (mockup: elágazó, csomópont-számlálókkal). Mobilon a cím levágva, a paletta vízszintesen kilóg, a minitérkép rálóg egy csomópontra. |
| 18 Űrlapok | – | – | – | Nincs oldalfej (eyebrow/lead), a „Mező hozzáadása" kétszer szerepel (lista + link). |
| 19 Domainek | `platform-domains` | ≈ | ≈ | A DKIM-kulcs kártya a részlet alatt (mockup: a lista alatt). A demó `pelda.hu` „Rendben" jelvényt kap 0/5 ellenőrzött rekord mellett — a seed a domaint `verified`-re állítja, a rekordok `pending`-ek: ellentmondás a felületen. Mobilon a DNS-név szó közepén tör (szándékos, a teljes érték olvasható). |
| 20 API-kulcsok | `platform-keys` | ≈ | ≈ | Üres állapot (nincs seed); a scope-kártyák egyeznek. |
| 21 Webhookok | `platform-webhooks` | ≈ | ≈ | Üres állapot; az újrapróbálkozási menetrend kártya hiányzik; mobilon a szűrőfül-sáv levágva. |
| 22 Napló | `platform-logs` | ≈ | ≈ | A trace külön oldalon (mockup: oldalpanel). A dátumszűrők natív mezők (`mm/dd/yyyy`). Mobilon a kilenc szűrő egymás alatt. |
| 23 Üzenet-trace | `platform-logs` (idővonal) | ≈ | ✘ | Teljes lánc látszik (sorba állítva → kapu → accepted → delivered → opened → clicked). **Nem fordított:** az eseménynevek (`delivered`…), a kapukódok (`sending_mode`, `rate_limit`…) és a DKIM-figyelmeztetés kódja (`no_signing_key`). Mobilon a kapu-tábla 3. oszlopa levágva. |
| 24 Suppression | `platform-suppression` | ≈ | ≈ | Üres állapot; az Export gomb hiányzik; mobilon a fülek levágva. |
| 25 Statisztika | – | – | – | Profilformátumú tengelyek. Mobilon az időszak-váltó kilóg, a jelmagyarázat rálóg az Y-tengelyre. |
| 26 Fejlesztői doksi | `platform-docs` | ✘ | ✘ | A végpontlista és egy szekció; a mockup paraméter-táblája, nyelvi kódminta-fülei és webhook-séma kártyái az alapértelmezett „Hitelesítés" szekción nem látszanak. A szekciócímek angolul (MESSAGES, GENERAL). |
| 27 Infrastruktúra | – | – | – | Üres IP-pool tábla (nincs seed); mobilon a táblafej levágva. |
| 28 Analitika | `analytics` | ≈ | ≈ | Minden kártya kirajzolódik. Néhány grafikon kategóriája rövid `MM-DD` alak (nem a profil dátumformátuma), a riport-előnézetben ISO dátum. A „Branding" link natív kék. |
| 29 Kampány-analitika | `analytics` | ≈ | ≈ | A KPI-számok kicsik (nem display betű); „Napi események: nincs adat", miközben a megnyitás-grafikon adatot mutat (ellentmondás); a hőtérkép %-jelvényei a gombfeliratra lógnak. |
| 30 Branding | `branding` | ≈ | ≈ | Nincs „Eltávolítás" gomb és kontraszt-szöveg alapértelmezett színnél; a riport-előnézet dátuma ISO. |
| 31 Profil | `profile` | ≈ | ≈ | Kapcsolók helyett jelölőnégyzetek; hiányzik a „Kampány elküldve" és a „Domain-hitelesítés változása" értesítés; nincs avatar; a formátum-opciókból hiányzik a magyarázó felirat. |

## Nyitott tételek (nem része a D284 javításainak)

1. Natív kék linkek sötét háttéren (közönség, kampánylista, szegmens-építés, branding-link) — közös link-stílus kell.
2. Nem fordított gépi kódok: trace-események és kapukódok, `no_signing_key`, import `pending` jelvény.
3. Natív dátummezők a böngésző locale-ját követik (`mm/dd/yyyy`) — közös, profilformátumú dátumválasztó kell (pl. `VtDatePicker`).
4. Mobil: szűk táblák kártyanézet nélkül (07, 08, 15, 22, 23, 27), kilógó fül-/szegmenssávok (17, 21, 24, 25), journey-minitérkép átfedés.
5. Ellentmondások: domain „Rendben" 0/5 rekorddal (seed-adat), kampány-analitika „nincs adat" a grafikon mellett.
6. A kontakttábla lapmérete (50) és hiányzó oszlopai a mockuphoz képest.

## Újragenerálás

```
php artisan migrate:fresh --seed   # CSAK a helyi dev-adatbázison
npm run screenshots
```
