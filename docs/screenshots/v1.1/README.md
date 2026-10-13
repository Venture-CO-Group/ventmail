# ventMail v1.1 – képernyőképek (app vs. mockup)

Generálva: 2026-10-13, `npm run screenshots` (`e2e/screenshots.spec.ts`, csak `SCREENSHOTS=1` mellett fut).

| Mappa | Tartalom | Darab |
|---|---|---|
| `desktop/` | app, 1440×900, teljes oldal (a menük/szerkesztő/vászon csak a nézet) | 31 |
| `mobile/` | app, 390×844 | 31 |
| `mockup/desktop/` | `docs/mockups/ventmail-v1.1.html` megfelelő képernyői | 20 |
| `mockup/mobile/` | ugyanaz mobilon | 20 |

**Adat:** csak seedelt demó-adat (`/dev/seed-logs`, `/dev/seed-studio`, `/dev/seed-analytics`, `pelda.hu` /
`example.test` címek, dev-felhasználó). Az import-varázsló az előnézetig megy, kontakt nem jön létre.
Az Infrastruktúra-képhez a dev-user ideiglenesen platform-admin, a `finally` visszaveszi (ellenőrizve: utána `false`).

## Fontos fenntartás: a dev-adatbázis e2e-maradékkal terhelt

A képek a közös dev-adatbázisról készültek, amelyben a Playwright-suite korábbi futásai **append-only / tartós
maradékot** hagytak: ~90 „E2E Studio …" lista, ~25 „E2E szegmens", ~15 hosszú nevű layout-seed lista, 45 `e2e-*.pelda.hu`
domain, „E2E blokk" mentett blokkok, „E2E journey", és a 300k-s import ~600 000 kontaktja. Emiatt több kép
(közönség, kampány-címzettek, analitika növekedés-grafikon, domainlista, szerkesztő mentett blokkjai) **nem
reprezentatív** demó-nézet. Tiszta képhez üres adatbázison, csak a demó-seedekkel kell újrafuttatni (nyitott tétel).

A mockup-képeken a mockup saját, lebegő képernyőváltó sávja (`mockup · shell · overview …`) is látszik — ez a
mockup része, nem az appé.

## Megfeleltetés és eltérések

Jelmagyarázat: **✔** lényegében egyezik · **≈** a szerkezet egyezik, részletek eltérnek · **✘** érdemi eltérés ·
**–** nincs mockup-párja. „Átnézve" = egymás mellett, szemmel ellenőrizve; a többinél csak az elkészülés ellenőrzött.

| App | Mockup | Átnézve | Értékelés | Megjegyzés |
|---|---|---|---|---|
| 01–03 shell menük | `shell` | mobil 01 | ≈ | Mobil hamburger-panel csoportokkal, leírásokkal, nyelvválasztóval rendben. |
| 04–06 workspace-váltó, avatar, nyelv | `shell` | – | ? | Nem vetettem össze. |
| 07 Áttekintés | `overview` | desktop + mobil | ✘ | Az app jóval egyszerűbb: nincs sparkline-os KPI-sor, „Legutóbbi kampányok" tábla, Kézbesíthetőség-kártya (SPF/DKIM/DMARC), Teendők, Import/Új kampány gomb, küldési-mód sáv. **Hibák:** HU felületen angol szám- és dátumformátum (`10,000`, `10/13/2026, 8:20:36 PM`; a mockup `12 406`, `2026. 10. 12.`), a státusz nyersen, fordítatlanul (`rejected`). |
| 08 Közönség | `audience` | desktop | ✘ | KPI-k, Növekedés, Források, Engagement megvannak. **Hiba:** a Listák és a Szegmensek blokk lapozás nélkül minden elemet kirajzol → a desktop oldal 10 677 px magas. A kontakttábla a mockupnál szegényebb (nincs avatar, címke-chip, aktivitás-sáv). |
| 09–12 import 1–4. lépés | `import-step1…4` | 12 | ✔ | Az előnézet kategória-kártyái, a mintatábla és a lépésjelző a mockupot követi. |
| 13 Sablonok | `templates` | – | ? | Nem vetettem össze (a legnagyobb kép – valószínűleg e2e-maradék sablonok). |
| 14 Sablonszerkesztő | `editor` | desktop | ≈ | Háromhasábos elrendezés, blokkpaletta, Desktop/Mobil/Sötét, Mentve-jelző egyezik. A palettán HU felirat (Tartalom/Elrendezés) a mockup angol „Content/Layouts" helyett; a demó-sablon rövidebb, mint a mockup gazdag hírlevele. |
| 15–16 Kampányok, kampány | `campaign` | 16 | ✘ | Lépés-akkordeon + PreSendGate-kártya megvan. **Hiba:** a Címzettek lépés minden listát és szegmenst checkboxként listáz, keresés/lapozás nélkül (maradékkal ~1 500 px). A demó-kampány státusza „Sikertelen" (a dev küldési mód zárt). |
| 17 Journey-vászon | `journey` | desktop | ≈ | **Hiba:** a trigger-palettán a „Hamarosan" címke összenyomja a feliratot, a szavak törve: „Címke hozzáa / dva", „Mezővá / ltozás". |
| 18 Űrlapok | – | – | – | |
| 19 Domainek | `platform-domains` | desktop | ≈ | A rekordtábla neve/értéke 1440 px-en is csonkolt („demo.peld…"; másolás gombbal elérhető). |
| 20 API-kulcsok | `platform-keys` | – | ? | Nem vetettem össze. |
| 21 Webhookok | `platform-webhooks` | – | ? | Nem vetettem össze. |
| 22–23 Napló, trace | `platform-logs` | – | ? | Nem vetettem össze. |
| 24 Suppression | `platform-suppression` | – | ? | Nem vetettem össze. |
| 25 Statisztika | – | – | – | |
| 26 Fejlesztői doksi | `platform-docs` | – | ? | Nem vetettem össze. |
| 27 Infrastruktúra | – | – | – | Csak platform-admin. |
| 28–29 Analitika | `analytics` | 28 | ≈ | Minden kártya kirajzolódik (megnyitás/kattintás, kliens, hőtérkép, top linkek, bounce, kézbesíthetőség, PDF). A növekedés-grafikon egyetlen ~600k-s tüskét mutat (300k-s e2e-import), a journey-választó alapból egy „E2E journey"-t nyit. |
| 30 Branding | `branding` | – | ? | Nem vetettem össze. |
| 31 Profil | `profile` | – | ? | Nem vetettem össze. |

## Talált hibák (javításra javasolt)

1. **Áttekintés: locale-formázás** – szám és dátum `en-US` formátumban HU felületen; a napló-státusz fordítatlan.
2. **Közönség: lapozatlan Listák / Szegmensek** – sok listánál végtelenül hosszú oldal.
3. **Kampány-címzettek: lapozatlan, kereshetetlen lista- és szegmensválasztó.**
4. **Journey-paletta: „Hamarosan" címke tördeli a trigger-neveket.**
5. **Áttekintés: a mockup kártyáinak nagy része (sparkline-ok, kampánytábla, kézbesíthetőség, teendők) hiányzik.**

## Újragenerálás

```
npm run screenshots
```

A futás idempotens seedeket hív, és a képeket felülírja.
