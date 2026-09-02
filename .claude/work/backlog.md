# ventMail — packet backlog v2.0 (a nightly runner ezt olvassa/írja)

# Formátum:  <státusz>  <packet-id>  <track>  <branch>  <agentek>   # leírás
# Státuszok: TODO | RUNNING | PR_OPEN | DONE | BLOCKED
# Track:     M (közös mag) | A (Platform/Mailgun) | B (Studio/Mailchimp) | C (zárás)
# A runner a legelső TODO-t veszi az adott trackből (`--track A`), fentről lefelé.
# M0 KÖTELEZŐEN elsőként, teljesen. Utána A és B PÁRHUZAMOSÍTHATÓ külön worktree-ben.
# C csak akkor indul, ha A1–A6 és B1–B4 mind DONE.
# main-re SOHA nem pushol; csak PR-t nyit. DONE-t ember állít merge után.

# ── M0 · Közös mag (nincs párhuzam) ──────────────────────────────────────────
DONE  m0-0  M  feat/m0-0-skeleton           devops,backend            # PR #3 — mergelve
DONE  m0-1  M  feat/m0-1-tenancy-oidc        architect,backend         # PR #8 — mergelve
DONE  m0-2  M  feat/m0-2-schema-audit        backend,db                # PR #9 — mergelve
DONE  m0-3  M  feat/m0-3-sending-gate        backend,security          # PR #10 — mergelve
DONE  m0-4  M  feat/m0-4-domains             backend,frontend,mockup   # PR #11 — mergelve

# ── A sáv · PLATFORM (Mailgun-kiváltás) ──────────────────────────────────────
TODO  a1    A  feat/a1-messages-api          backend,security          # Messages API: MIME, batch, o:/h:/v:, ütemezés, testmode, tárolt sablonok
TODO  a2    A  feat/a2-smtp-ip-queues        backend,security,devops   # SMTP relay 587/465, IP-poolok, warmup, sorok, retry, bounce-osztályozó
TODO  a3    A  feat/a3-logs-events           backend,frontend          # Napló, üzenet-trace (SMTP-párbeszéd), retenció, tag-stat, Events API, webhook replay+DLQ
TODO  a4    A  feat/a4-inbound-routing       backend,security,frontend # MX-fogadás, route-motor, MIME-parse sandbox, stripped-text, csatolmány, FBL/ARF
TODO  a5    A  feat/a5-validation            backend,frontend          # Címellenőrzés: szintaxis/MX/szerep/eldobható/catch-all/szonda/kockázat + bulk
TODO  a6    A  feat/a6-deliverability        backend,frontend          # Blocklist, DMARC RUA, seed/inbox placement, spam-score, MTA-STS/TLS-RPT/BIMI

# ── Review-ból jövő adósságok (nevesített gazdával) ──────────────────────────
# Nem külön packet: a nevesített packet DoD-jába tartozik.
#  · a `stored_template_versions` DELETE-je nincs triggerrel fogva (az UPDATE igen)   → A3
#  · a `From` display-name hamisítható (`"bank@x" <ok@sajat.hu>`) — Mailgun-paritás   → A6
#  · a nyers MIME Wasabira költöztetése (ma bytea-ban van)                            → A3
#  · audit hash-lánc; DB-szerep szeparáció (ventmail_owner/ventmail_app)              → C2
#  · DoH/DNSSEC resolver, `_ventmail-verify` tulajdon-token                           → A6
#  · a `messages` tábla retenciója és redakciós útja (a nyers MIME-é kész)            → A3
#  · címzettenkénti újrapróbálás-granularitás (ma üzenet-szintű a claim)              → A2
#  · a CI a `VENTURE_PACKAGES_TOKEN` hiányában KIHAGYJA a drift-tesztet; a secret
#    beállítása után a kapu élesedjen (ma hangos skip)                                → ember
#  · `workspace_invitations` (a tagság ma csak SSO-ból jön)                           → B1
#  · a `v:*` változók NYERSEN vannak a `message_events`-ben (Mailgun-paritás), és az
#    A3 webhook ki is küldi őket: retenció + redakció kell rájuk                       → A3
#  · a Postal `send/message` idempotency_key támogatása NEM igazolt (élő cluster kell)  → A2
#  · volumetrikus (L7) védelem az edge feladata: a trusted-proxy mögötti XFF-rotációt
#    alkalmazásszinten nem lehet megfogni — Caddy/LB rate limit kell                    → devops

# ── B sáv · STUDIO (Mailchimp-kiváltás) ──────────────────────────────────────
TODO  b1    B  feat/b1-audience-consent      backend,security,frontend # Listák/szegmensek/tag, ConsentService double opt-in, űrlapok, pref-center, ventOS sync
TODO  b2    B  feat/b2-editor-campaign       backend,frontend,mockup   # Block-tree + MJML, editor UI, kampány-életciklus, célzás, A/B, ütemezés
TODO  b3    B  feat/b3-journey               backend,frontend,mockup   # Journey motor + builder UI (API- és inbound-trigger)
TODO  b4    B  feat/b4-analytics             backend,frontend          # Marketing analitika, klikk-térkép, export

# ── C sáv · Zárás (csak A+B után) ────────────────────────────────────────────
TODO  c1    C  feat/c1-billing-subaccounts   backend,security          # ventPay metering 6 dimenzió, entitlement, sub-account konszolidált számlázás
TODO  c2    C  feat/c2-superadmin-i18n       backend,frontend          # Super-admin konzol, i18n HU/EN/DE/IT/ES/RU, teljes gate-sor
