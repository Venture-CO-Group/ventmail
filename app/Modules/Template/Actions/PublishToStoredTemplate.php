<?php

declare(strict_types=1);

namespace App\Modules\Template\Actions;

use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\StoredTemplates\Models\StoredTemplateVersion;
use App\Modules\StoredTemplates\Services\ConditionalContent;
use App\Modules\Template\Models\Template;
use Illuminate\Support\Facades\DB;

/**
 * PUBLIKÁLÁS TÁROLT SABLONNÁ (67. kritérium).
 *
 * ## Miért két külön fogalom
 *
 * A `templates` (block editor) a STUDIO sablonja: szerkeszthető fa, amiből HTML és plaintext
 * származik. A `stored_templates` (A1) a PLATFORM sablonja: az API-hívó `template=nev` +
 * `vars[]` alapján küld vele tranzakciós levelet.
 *
 * A kettő nem ugyanaz, és nem is olvasztható össze: a Studio sablonja a marketingesé és
 * folyamatosan változik, a tárolt sablon verziója viszont a FEJLESZTŐ szerződése — egy
 * futó integráció nem törhet el attól, hogy valaki a szerkesztőben átírt egy szöveget.
 *
 * Ezért a publikálás ÚJ VERZIÓT hoz létre, és a régiek érintetlenül maradnak. A tárolt
 * sablon verziói egyébként megváltoztathatatlanok (adatbázis-trigger, A1): a publikálás
 * SOHA nem írhat át egy meglévő verziót — az egy már működő integráció alól húzná ki a
 * szőnyeget.
 *
 * ## A MARKETING SABLON is publikálható — és a LINKJEI MŰKÖDNEK
 *
 * Ez a docblock korábban azt állította, hogy „a publikálás JELZI, ha a sablon marketing" —
 * a kód viszont nem jelzett semmit (nincs kivétel, flag, audit-megjegyzés). A mért
 * következmény: a publikált marketing sablon tranzakciós rendereléskor `href=""`-t adott,
 * azaz `<a href="">Leiratkozás</a>` állt a levélben. HALOTT leiratkozó link, miközben a
 * golden rule 8 szerint a leiratkozás nem alku.
 *
 * A javítás nem itt van, hanem a KÜLDÉSI ÚTON: a `Message::bodyLinkVariables()` MINDEN
 * csatornára kiadja a címzettenkénti `unsubscribe_url`-t és `preferences_url`-t, és a
 * `MessageDispatcher` fenntartott (a hívó által nem felülírható) változóként helyettesíti
 * be. Egy tranzakciós levélben így sincs KÖTELEZŐ leiratkozó fejléc — de ha a törzsben ott
 * a link, az MŰKÖDIK.
 *
 * Ezért itt nincs mit jelezni, és a `marketing` flag megmarad annak, ami: a sablon
 * SZERKESZTŐI szabálya (leiratkozó blokk nélkül nem mentődik), nem a küldési út feltétele.
 */
final class PublishToStoredTemplate
{
    public function __invoke(Template $template, ?string $name = null): StoredTemplateVersion
    {
        return DB::transaction(function () use ($template, $name): StoredTemplateVersion {
            $stored = $template->storedTemplate
                ?? StoredTemplate::query()->firstOrCreate(
                    ['name' => $name ?? (string) $template->name],
                    ['description' => 'A block editorból publikálva: '.$template->uuid],
                );

            // AZ ÚJ VERZIÓ mindig a következő szám: a régiek megmaradnak, és egy futó
            // integráció a saját verzióján tovább működik.
            $version = StoredTemplateVersion::query()->create([
                'stored_template_id' => $stored->getKey(),
                'version' => $stored->nextVersionNumber(),
                // A FELTÉTELES BLOKKOK a tárolt sablonban NEM élnek (D58): a `/v1` hívó adata
                // nem a mi kontaktunké, tehát a feltételes rész a publikáláskor kiesik —
                // a „csak VIP" tartalom így senkihez nem kerül ki.
                'body' => ConditionalContent::strip((string) $template->html),
                'body_text' => ConditionalContent::strip((string) $template->text),
                // A `engine` a behelyettesítés nyelvét mondja meg. A block editor kimenete
                // `{{ valtozo }}` alakú merge-tageket tartalmaz — ugyanazt, amit az A1
                // `TemplateRenderer`-e ért, escape-eléssel és fallbackkel.
                'engine' => 'mustache',
                // AZ AKTÍVVÁ TÉTEL SZÁNDÉKOS DÖNTÉS, nem a publikálás mellékhatása: egy
                // félkész sablon azonnali aktiválása minden `template=nev` hívást átállítana.
                'is_active' => $stored->activeVersion() === null,
            ]);

            $template->forceFill([
                'stored_template_id' => $stored->getKey(),
                'published_at' => now(),
            ])->save();

            return $version;
        });
    }
}
