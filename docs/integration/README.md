# Család-integráció: sablon-manifesztek

`docs/integration/manifests/<set>.json` az érintett app-repók `docs/integration/ventmail-templates.json`
fájljának MÁSOLATA. A forrás az app-repó (ott él a kulcs, a változók, a címzett és a tárgy); itt
csak azért van másolat, hogy a `tests/Feature/StoredTemplates/FamilyTemplateSetsTest.php` önálló legyen.
Manifeszt-változáskor a másolatot és a `resources/mail-templates/<set>/` fájljait együtt frissítjük.

Motor-tények (TemplateRenderer): `{{ var }}` HTML-escape-elve (a .txt nem), `{{ var || tartalék }}` a hiányzó
VAGY üres változóra a tartalékot adja, feltételes blokk API-eredetű küldésben NINCS. A sablonok színei az
`@venture/design` tokenek értékei, beégetve: a levélben CSS-változó nem él (elfogadott kivétel).

## Merge-függőség

A `.subject` fájlokat a `feat/family-onboarding` ág tölti be (sablonverzió `subject` oszlop + az importer
`.subject`-kezelése). Ennek ELŐBB kell élesedni, mint hogy ezeket a készleteket
(`ventmail:templates:import <workspace> <set>`) importálnánk — különben a tárgy nem kerül a sablonverzióba.
