# WARM-UP PLAN — javaslat (NEM indult el)

> Állapot: **javaslat**. A marketing pool küldés-tiltott (`SENDING_MARKETING_ENABLED=false`,
> a `marketing` IP-pool napi kerete 0). Az indításról a tulajdonos dönt; a lépéseket a
> RUNBOOK-mail §13 sorrendje szerint kell kapcsolni. Ez a dokumentum a 300 ezres lista
> biztonságos felfuttatásáról szól, a tranzakciós forgalomról csak a végén.

## 1. Kiinduló helyzet

| | Tranzakciós pool | Marketing pool |
|---|---|---|
| IP | 141.95.64.117 (`mta-tx1`) | 51.38.111.172 (`mta-mk1`) |
| Előélet | friss, tiszta (ZEN/Barracuda/SpamCop/UCE L1–L2) | friss; **UCEPROTECT L2 tartomány-listázás** (L1 tiszta) |
| Return-Path | `rp-tx.ventmail.app` | `rp-mk.ventmail.app` |
| Lista | — | ~300 000 cím, vegyes (magyar és nem magyar), ismeretlen frissességű |

**Előfeltételek a marketing warm-up előtt** (mind HUMAN-QUEUE):
1. Google Postmaster Tools és Microsoft SNDS/JMRP, Yahoo CFL regisztráció a küldő domainre és mindkét IP-re.
2. A marketing feladó-domain (pl. `news.ventureco.app`) felvétele a ventMailben: SPF, DKIM (2048),
   DMARC, `psrp-mk` Return-Path CNAME, one-click unsubscribe (RFC 8058 — a kapu kikényszeríti).
3. UCEPROTECT L2 újraellenőrzése (`dnsbl-check`); ha még listáz, a tartomány-tiltást fizetős
   delistinggel NEM érdemes oldani — vagy várunk, vagy másik blokkból kérünk IP-t.
4. A lista jogalapjának tisztázása (6. pont) — enélkül nincs küldés.

## 2. Szegmentálás (engagement szerint)

A lista NEM egyben indul. Négy szegmens, a legjobb jelekkel kezdve:

| Szegmens | Feltétel | Becsült méret | Mikor |
|---|---|---|---|
| A — aktív | nyitás/kattintás az utolsó 90 napban | ~10–15% | 1. héttől |
| B — langyos | aktivitás 90–365 nap között | ~20–25% | 3. héttől |
| C — hideg | >365 nap, vagy nincs adat | ~40% | csak re-permission kampány után (5. pont) |
| D — kockázatos | korábbi bounce, role-cím (`info@`, `admin@`), eldobható domain | ~10–20% | **soha** — címellenőrzés (A5) kiszűri |

Minden import előtt: a ventMail címellenőrzése (bulk validation), és a D szegmens a suppression-listára.

## 3. Napi volumen-lépcsők (marketing IP, egy IP-n)

Fogadónként külön keret; a `SENDING_THROTTLE_*` percenkénti limitek mellett a napi plafon az
IP-pool `daily_limit`-je (`ventmail:ip-pools:sync --marketing-daily-limit`), illetve a
warm-up ütemező (`WarmupSchedule`) — a kettő közül a kisebb érvényes.

| Nap | Gmail | Microsoft (Outlook/Hotmail) | Yahoo/AOL | Egyéb (freemail.hu, citromail, céges) | Össz/nap |
|---|---|---|---|---|---|
| 1–2 | 150 | 100 | 50 | 200 | 500 |
| 3–4 | 300 | 200 | 100 | 400 | 1 000 |
| 5–7 | 600 | 400 | 200 | 800 | 2 000 |
| 8–10 | 1 500 | 1 000 | 500 | 2 000 | 5 000 |
| 11–14 | 3 000 | 2 000 | 1 000 | 4 000 | 10 000 |
| 15–21 | 6 000 | 4 000 | 2 000 | 8 000 | 20 000 |
| 22–28 | 12 000 | 8 000 | 4 000 | 16 000 | 40 000 |
| 29–35 | 20 000 | 14 000 | 6 000 | 25 000 | 65 000 |

Csak akkor lépünk feljebb, ha az előző lépcső **minden** stop-feltételen átment (4. pont),
legalább 48 órája. Visszalépés: egy lépcsővel lejjebb, 3 napig.

## 4. Stop-feltételek (azonnali megállás, a lépcső nem nő)

- **Bounce > 2%** (hard) egy napi kötegen belül — lista-higiénia, a szegmens leáll.
- **Panasz (complaint) > 0,1%** — FBL (Microsoft JMRP, Yahoo CFL) és Postmaster alapján.
- **Google Postmaster**: domain- vagy IP-reputáció „Medium” alá esik, vagy a spam-arány ≥ 0,1%.
- **Microsoft SNDS**: piros/sárga állapot az IP-n.
- **Blocklist**: bármely nem-informatív listázás a `dnsbl-check`-ben (RUNBOOK-mail §9).
- **OVH Vade port-25 tiltás**: azonnali teljes leállás és okfeltárás (RUNBOOK-mail §7).

## 5. Lista-higiénia és re-permission

1. Bulk címellenőrzés minden szegmensen (szintaxis, MX, eldobható, role, korábbi bounce).
2. A C (hideg) szegmens CSAK re-permission kampánnyal érhető el: egyetlen levél, egyértelmű
   „maradni szeretnék” gombbal (double opt-in megerősítés). Aki nem kattint 14 napon belül,
   az automatikusan a suppression-listára kerül — **nem** kap több marketing levelet.
3. Soft bounce: 5 kísérlet után suppression (a motor már így működik).
4. Inaktivitás: 180 napja semmit nem nyitó címzett kikerül a rendszeres kampányokból.

## 6. Jogalap (GDPR) — különösen a nem magyar részre

- A ventMail szabálya: **marketing csak double opt-in consenttel** (golden rule 4). Egy
  importált lista tagjainak NINCS ventMail-consentje → a ConsentGate minden marketing levelet elutasít.
- Teendő a lista importja előtt: a meglévő hozzájárulás bizonyítékainak (időpont, forrás,
  szöveg, IP) importja a Consent modulba, VAGY a re-permission kampány mint egyetlen,
  jogos érdeken alapuló megkeresés jogi jóváhagyása.
- A nem magyar címzetteknél a helyi szabályok is számítanak (pl. Németország: UWG §7 —
  kifejezett, bizonyítható opt-in; Franciaország: CNIL). **Jogi ellenőrzés kell** (HUMAN-QUEUE).

## 7. További IP-k

300 000 címhez, heti 1–2 kampánnyal, egy IP csúcsidőben ~65 000/nap fölött kockázatos.
Javaslat: a stabil állapot (5–6. hét) után **még 2 marketing IP** (összesen 3), mindegyik
saját warm-uppal, eltolva 2 héttel; egy pool, round-robin (`IpPoolSelector`). A tranzakciós
pool marad egy IP-n (volumene kicsi), de egy **tartalék tranzakciós IP** javasolt hidegen,
gyors csere esetére (feketelista, Vade-tiltás).

## 8. Tranzakciós pool (ventSSO-levelek)

A tranzakciós IP-t a valódi, alacsony volumenű auth-forgalom melegíti: napi keret 500-zal
indul (`ventmail:ip-pools:sync --transactional-daily-limit`), hetente duplázva, amíg a
Postmaster-reputáció „High”. Az átkapcsolás sorrendje: RUNBOOK-mail §13.
