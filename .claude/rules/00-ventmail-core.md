---
paths: ["**"]
---
# ventMail — mag-szabályok (mindig igaz)

- **Cél: a Mailgun ÉS a Mailchimp egyidejű kiváltása.** Két pillér egy motoron:
  **PLATFORM** (Messages API, SMTP relay, inbound routing, napló+trace, címellenőrzés, IP/warmup,
  kézbesíthetőség, sub-accountok) és **STUDIO** (közönség, consent, block editor, kampány, journey,
  űrlapok, analitika). A kampány UGYANAZON a küldőmotoron megy, mint az API-hívás.
- ventMail az e-mail EGYETLEN gazdája a családban — **kimenő ÉS bejövő**. Más app „log + TODO ventMail"
  stubot használ, saját SMTP/MX/template réteget nem épít.
- ventMail NEM épít saját: identity (→ ventSSO) · consent-banner (→ ventCookie) · fájltár (→ ventVault) ·
  számlázó (→ ventPay) · **landing page (→ ventStudio)** · social (→ ventPost) réteget.
  Kivétel: a ventMail saját STATIKUS termékoldala az apexen (`ventmail.app`) itt él — nem landing-page-builder, az a ventStudióé.
- Stack: Laravel 12 modulmonolit `app/Modules/<Name>`, Vue 3 + TS + Inertia, PG17 + Timescale,
  Redis+Horizon, Reverb, Meili, Caddy, Docker Compose, Vultr EU, Wasabi EU. MTA: Postal (kimenő) +
  MX-fogadó (bejövő) + SMTP submission 587/465.
- Auth = ventSSO OIDC (`ventmail`, `resource_access.ventmail.roles`). Tenant-tagság a ventMail SAJÁT
  `workspace_members`-ében. Minden entitás `workspace_id`-hez kötött, global scope-pal.
  **Sub-account (parent↔child) izoláció ugyanolyan szigorú, mint a tenant-izoláció.**
- „teszt előbb" mindig. PR-only, auto-merge SOHA. Az író agent nem review-zza magát.
- Secret csak env. Dev = noindex + Basic Auth. Minden admin-művelet auditált.
- Design: csak `@venture/design` token + `@venture/ui` komponens. Hardcode szín/spacing/font TILOS —
  ha nincs komponens, JELENTSD, ne gyárts lokálisat.
