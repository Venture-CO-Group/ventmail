---
paths: ["app/Modules/Billing/**", "app/Modules/Tenancy/**"]
---
# Billing (ventPay) + sub-accountok — szabályok (pénzügy → security 2. reviewer)

- MINDEN számlázás ventPay-en át. Kártyaadat SOHA nem ér ide.
- **Metering 6 dimenzió:** `sends_counter` · `contacts_peak` · `validations_counter` ·
  `inbound_counter` · `dedicated_ips` · `log_retention_tier`.
- **Entitlement-gate:** `journeys`, `custom_domain`, `dedicated_ip`, `inbound_routes`, `validation_bulk`.
  A plan-kvóta a PreSendGate 4. kapujába is bekötve.
- **Sub-accountok:** parent↔child workspace. A parent lát összesített használatot; a childok adatai
  egymás elől REJTVE (teszt-fedett izoláció). Számlázás konszolidált a parentnél, **per-child bontással**.
- Amíg ventPay nem él → `FakeVentPayClient` az OpenAPI kontraktus szerint; élesedéskor CSAK config vált.
- Pénzügyi logikát érintő PR → `venture-security` 2. reviewer + determinisztikus fixtúrák.
