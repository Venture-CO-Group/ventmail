# Journey modul

**Sáv:** STUDIO (Mailchimp-kiváltás)

Customer Journey motor: trigger → condition → action → wait → branch, idempotens lépésekkel.

## Határok
- Minden entitás `workspace_id`-hez kötött, global scope-pal (Tenancy).
- Külső gazda csak interfész mögött; a fake teszttel fedett kontraktus.
- A modul nem épít saját identity / consent-banner / fájltár / számlázó / landing-page réteget.

## Állapot (v1.1 · 6. modul)
- **Vászon** (`/journeys/{uuid}`, `Pages/Journey/Canvas.vue`, `@vue-flow/*` MIT): gráf a
  `journeys.graph`-ban (`Graph/JourneyGraph` normalizál, `Graph/JourneyGraphValidator` validál:
  árva csomópont, hiányzó trigger, várakozás nélküli hurok, kötelező konfiguráció, hivatkozások).
- **Verziók** (D5): `JourneyPublisher` → `journey_versions` (megváltoztathatatlan) + a verzió
  lépései (`journey_steps.journey_version_id`); a futás a saját verzióján halad.
- **Motor**: `JourneyEngine` (a régi lánc ÉS a vászon lépései), `JourneyConditions` (közös
  feltétel-kiértékelés), webhook csomópont a Webhooks modul `DeliverWebhook`-ján.
- **Statisztika**: `JourneyNodeStats` (előre összesített `executed_count` + korlátos „vár").
- **Teszt-futtatás**: `JourneySimulator` — csak olvas, nem küld.
- Publikálás / szüneteltetés: `journey.publish` képesség, auditált.
