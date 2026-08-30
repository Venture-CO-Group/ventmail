# INDÍTÁS — ventMail v2.0 · folyamatos fejlesztés (Mac mini, 0-24)

**Cél: a Mailgun ÉS a Mailchimp egyidejű kiváltása.** Nem hírlevélküldő — e-mail infrastruktúra
(ESP), amire ráépül egy marketing suite. Két pillér, egy motor.

| PLATFORM (Mailgun-oldal) | STUDIO (Mailchimp-oldal) |
|---|---|
| Messages API: MIME, batch 1000, `o:`/`h:`/`v:`, ütemezés, testmode | Közönség + dinamikus szegmensek |
| **SMTP relay** 587/465, per-domain hitelesítők | Consent + double opt-in |
| **Inbound routing (MX)** — route-motor, stripped-text, csatolmány | Block editor + MJML |
| Napló + **üzenet-trace SMTP-párbeszéddel** | Kampányok + A/B + ütemezés |
| **Címellenőrzés** (szintaxis→mailbox-szonda, bulk) | Customer Journey |
| IP-poolok + **warmup-automatika**, bounce-osztályozó | Űrlapok + preference center |
| **Kézbesíthetőségi suite** (blocklist, DMARC, inbox placement) | Marketing analitika |
| Webhookok replay + dead-letter · **sub-accountok** | |

**Amit szándékosan NEM itt építünk:** landing page → ventStudio · social/ads → ventPost ·
fizetés → ventPay · azonosítás → ventSSO · consent banner → ventCookie.

---

## A pakk

```
ventmail/
├── INDÍTÁS.md                         ← ez a fájl
├── CLAUDE.md                          ← repo-szintű tények + 11 golden rule
├── PHASE.md                           ← aktuális fázis + DoD (SessionStart hook injektálja)
├── bootstrap.sh                       ← EGYSZERI setup a Mac minin
├── venture-nightly.sh                 ← folyamatos headless runner, `--track A|B|C`, PR-only
├── docs/
│   ├── ventmail-specifikacio.md       ← v2.0 spec (28 fejezet, két pillér, kiváltás-térkép)
│   ├── ventmail-fejlesztesi-terv.md   ← sávos terv + mockolási stratégia + Mac mini hurok
│   ├── ventmail-indito-promptok.md    ← packetenkénti, beilleszthető Claude Code promptok
│   └── ventmail-latvanytervek.html    ← 10 képernyős interaktív mockup (1–9, 0)
└── .claude/
    ├── rules/  00-core · 10-sending · 20-consent · 30-billing · 40-api · 50-inbound · 60-smtp-ip-validation
    └── work/   backlog.md (track-oszloppal) · _templates.md
```

## Indítás

```bash
# 1) egyszeri setup
cd ~/Developer/venture/ventmail && ./bootstrap.sh

# 2) első kör interaktívan (hogy lásd, a konvenciók a helyükön vannak)
claude
#   /fazis-terv M0  →  illeszd be az M0-1 promptot a docs/ventmail-indito-promptok.md-ből

# 3a) folyamatos kör — M0 alatt EGY runner elég (a mag nem párhuzamosítható)
tmux new -s ventmail './venture-nightly.sh; bash'

# 3b) M0 UTÁN két sáv párhuzamosan, külön worktree-ben
tmux new -s vm-a './venture-nightly.sh --track A; bash'   # Platform (Mailgun)
tmux new -s vm-b './venture-nightly.sh --track B; bash'   # Studio (Mailchimp)
```

A runner kapui beépítve: amíg **M0** nyitott, más sáv nem indul; a **C sáv** csak akkor, ha A1–A6 és
B1–B4 mind `DONE`. A `DONE`-t **ember** állítja merge után.

## A hurok garanciái (venture-kit hookok)
- **teszt előbb** — a commit-kapu maga futtatja a teszteket.
- **main védve** — push-guard; a runner csak feature branchre pushol.
- **PR-only** — a runner PR-t nyit, **soha nem mergel**. A merge az egyetlen kötelező emberi pont.
- **szerep-szeparáció** — az író agent sosem review-zza magát.
- **2. security reviewer** kötelező: auth · PII · pénzügy · kripto · **inbound MIME-parser** · **SMTP relay**.

## Amit ellenőrizned kell (környezet-függő)
- **Claude Code headless flagek:** `venture-nightly.sh` → `CLAUDE_HEADLESS_ARGS`; hitelesítsd
  `claude --help`-pel (print mód alap: `claude -p`).
- **venture-kit rules-útvonal:** az `install.sh` a `templates/rules/`-ból másol; a pack **7** szabályfájlja
  kerüljön a `.claude/rules/` alá.
- **`paths:` frontmatter** szintaxis egyezzen a kit-verzióddal.

## Halasztott ops-sáv (éles indulás előtt, kézzel)
**Kimenő:** Postal cluster · PTR/rDNS · port 25 · IP-pool szeparáció · SPF/DKIM/DMARC élő DNS · IP-warmup ·
Postmaster/SNDS · `FakeDriver → PostalDriver`.
**Bejövő:** MX-rekordok · SMTP-fogadó · rspamd + ClamAV · `.eml` fixtúra-injektor lecserélése.
**Relay:** 587/465 tanúsítvány + tűzfal · éles SMTP-hitelesítők.
**Validation:** szonda kimenő IP + konzervatív rate-limit (RBL-kockázat).
**Kézbesíthetőség:** seed-fiókok · blocklist API-kulcsok · DMARC RUA cím a DNS-be.
**Integrációk:** élő ventPay · élő ventOS · ventStudio block motor.
Részletes checklist: `docs/ventmail-fejlesztesi-terv.md` 8. szakasz.

## Mikor mondható fel a két előfizetés
Amikor a spec **28. fejezetének kiváltás-térképe** minden sora kipipálva, minden sáv DoD zöld, a `main`
zöld, a `contract-guard` 0 sértést jelez, és a `FakeDriver → PostalDriver` csere **csak config**.
