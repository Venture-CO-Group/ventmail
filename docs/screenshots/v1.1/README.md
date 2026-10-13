# ventMail v1.1 – képernyőképek (app vs. mockup)

Generálva: 2026-10-13, **tiszta demó-adatbázison** (D271 / D284 / D292), a D284 és a D292 UI-javítások után.
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
  **üres állapotot** mutatnak (lásd Backlog).
- A demó-domain (`pelda.hu`) a D292 óta következetes: hitelesített, mind az öt rekordja rendben, valódi
  DKIM-kulccsal (`DemoDomain::ensure()`).
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

## Mi változott a D292 óta (javítva, a képeken már így)

1. **Közös link-stílus:** a linkek tokenes színt kapnak (`--vt-color-accent-300`, ≥ 4,5 : 1 a sötét téma
   minden felületén — vitest őrzi), nem a natív kéket.
2. **Gépi kódok fordítva** (`useCodes`, `codes.*` hét nyelven, biztonságos visszaeséssel): trace-események,
   kapunevek és -okok, `no_signing_key`, bounce-osztály, csatorna, tiltólista ok/hatókör/forrás, journey
   állapot/trigger, import-státuszjelvény, kontakt- és hozzájárulás-állapot. Pest-teszt követeli meg minden
   backend enum-érték címkéjét.
3. **Mobil sávok:** a szegmens- és fülsávok tördelnek (statisztika, webhookok, tiltólista, közönség, import,
   szegmensépítő); a journey-minitérkép mobilon rejtett, a journey neve teljes sort kap. A `layout-overlap`
   „clipped-bar" és „minimap-overlap" méréssel őrzi.
4. **Demó-domain** következetes (lásd fent).
5. **Kampány-analitika:** az üres „Napi események: nincs adat" kártya nem jelenik meg az adatot mutató
   megnyitás/kattintás grafikon mellett.

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
| 08 Közönség | `audience` | ≈ | ≈ | KPI-k, növekedés, források, engagement, lapozott listák/szegmensek, fordított állapotok, tokenes linkek. A kontakttábla gazdagsága (lapméret, avatar, címke-chip, aktivitás) és a mobil kártyanézet a Backlogban. |
| 09–11 import 1–3. lépés | `import-step1…3` | ≈ | ≈ | A lépésjelző sima sor (mockup: kártyák), mobilon vízszintesen görgethető; a fülek tördelnek; a státuszjelvény fordított. A hozzájárulás dátuma natív dátummező (`mm/dd/yyyy`) — Backlog. |
| 12 import 4. lépés | `import-step4` | ✔ | ≈ | Mobilon a minta-tábla vízszintesen görget (kártyanézet: Backlog); „kihagy" / „Kihagy" kis-nagybetű nem egységes. |
| 13 Sablonok | `templates` | ≈ | ≈ | A saját sablonok bélyegképe szürke helykitöltő (mockup: renderelt előnézet); a verziótörténet-panel nem a könyvtárban, hanem a szerkesztőben él; a kereső és az „Új sablon" gomb a fejlécben egymás alatt. |
| 14 Sablonszerkesztő | `editor` | ≈ | ≈ | Háromhasábos elrendezés, paletta, Desktop/Mobil/Sötét egyezik; a demó-sablon egyszerűbb; mobilon a fejléc három sorra bomlik. |
| 15 Kampánylista | `campaign` (lista) | ≈ | ≈ | Az app a listát és a checklistet külön oldalon mutatja (mockup: egy oldalon). Mobilon csak a név-oszlop fér ki (kártyanézet: Backlog). |
| 16 Kampány-checklist | `campaign` | ≈ | ≈ | Címzett-választó kereséssel/lapozással. A PreSendGate-kártya 3 ellenőrzést mutat a mockup 6 kapuja helyett, a Teszt küldés / Ütemezés gomb a lépésekben van. |
| 17 Journey-vászon | `journey` | ≈ | ≈ | A demó-journey 3 csomópontos (mockup: elágazó, számlálókkal). Mobilon a paletta vízszintesen görgethető sáv, a minitérkép rejtett. |
| 18 Űrlapok | – | – | – | Nincs oldalfej (eyebrow/lead), a „Mező hozzáadása" kétszer szerepel (lista + link). |
| 19 Domainek | `platform-domains` | ≈ | ≈ | 5 / 5 rekord rendben, valódi DKIM-kulccsal. A DKIM-kártya a részlet alatt (mockup: a lista alatt). Mobilon a DNS-tábla vízszintesen görgethető. |
| 20 API-kulcsok | `platform-keys` | ≈ | ≈ | Üres állapot (nincs seed); a scope-kártyák egyeznek. |
| 21 Webhookok | `platform-webhooks` | ≈ | ≈ | Üres állapot (Backlog: demó-seed); az újrapróbálkozási menetrend kártya hiányzik; a szűrősáv mobilon tördel. |
| 22 Napló | `platform-logs` | ≈ | ≈ | A trace külön oldalon (mockup: oldalpanel). Csatorna és állapot fordítva. A dátumszűrők natív mezők (Backlog). Mobilon a szűrők egymás alatt. |
| 23 Üzenet-trace | `platform-logs` (idővonal) | ✔ | ≈ | Teljes lánc, minden esemény, kapu, ok és a DKIM-figyelmeztetés fordítva. Mobilon a táblák vízszintesen görgetnek (kártyanézet: Backlog). |
| 24 Suppression | `platform-suppression` | ≈ | ≈ | Üres állapot (Backlog: demó-seed); az Export gomb hiányzik; a fülek mobilon tördelnek; ok/hatókör/forrás fordítva. |
| 25 Statisztika | – | – | – | Profilformátumú tengelyek; az időszak-váltó mobilon tördel. Mobilon a jelmagyarázat két sorban az Y-tengely fölött. |
| 26 Fejlesztői doksi | `platform-docs` | ✘ | ✘ | A mockup paraméter-táblája, kódminta-fülei és webhook-séma kártyái hiányoznak (Backlog); a szekciócímek az OpenAPI-tagek (MESSAGES, GENERAL). |
| 27 Infrastruktúra | – | – | – | Üres IP-pool tábla (demó-seed: Backlog); mobilon a tábla vízszintesen görget. |
| 28 Analitika | `analytics` | ≈ | ≈ | Minden kártya kirajzolódik, tokenes linkek. Néhány grafikon kategóriája rövid `MM-DD` alak, a riport-előnézetben ISO dátum. |
| 29 Kampány-analitika | `analytics` | ≈ | ≈ | A KPI-számok kicsik (nem display betű); a hőtérkép %-jelvényei a gombfeliratra lógnak. A napi esemény-kártya csak adattal látszik. |
| 30 Branding | `branding` | ≈ | ≈ | Nincs „Eltávolítás" gomb és kontraszt-szöveg alapértelmezett színnél; a riport-előnézet dátuma ISO. |
| 31 Profil | `profile` | ≈ | ≈ | Kapcsolók helyett jelölőnégyzetek; hiányzik a „Kampány elküldve" és a „Domain-hitelesítés változása" értesítés; nincs avatar; a formátum-opciókból hiányzik a magyarázó felirat. |

## Nyitott tételek (kisebb, a D292 után)

1. Kampány-analitika: kicsi KPI-számok, a hőtérkép %-jelvényei rálógnak a gombfeliratra.
2. Néhány analitika-grafikon `MM-DD` kategóriája és a riport-előnézet ISO dátuma nem a profil formátumát követi.
3. A PreSendGate-kártya 3 ellenőrzést mutat a mockup 6 kapuja helyett.
4. A bejövő route-ok képernyője („hamarosan" funkció) beégetett magyar feliratokkal és nyers állapotkóddal.

## Backlog / HUMAN-QUEUE

1. **Natív dátummezők** (import hozzájárulás-dátuma, napló Ettől/Eddig): a böngésző locale-ját követik
   (`mm/dd/yyyy`), nem a profilt — közös, profilformátumú dátumválasztó kell (pl. `VtDatePicker`).
2. **Mobil táblák kártyanézete** (07, 08, 15, 22, 23, 27): ma vízszintesen görgető táblák.
3. **Kontakttábla gazdagsága**: 50 soros lap (mockup: 8 + lapozó), hiányzó avatar, címke-chip, aktivitás-sáv.
4. **Fejlesztői doksi**: a mockup paramétertáblája, nyelvi kódminta-fülei és webhook-séma kártyái.
5. **Hiányzó demó-seedek**: API-kulcs, webhook, tiltólista, egyéni mező, űrlap, IP-pool — ezek a képek üres
   állapotot mutatnak.

## Újragenerálás

```
php artisan migrate:fresh --seed   # CSAK a helyi dev-adatbázison
npm run screenshots
```
