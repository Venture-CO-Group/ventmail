<?php

declare(strict_types=1);

namespace App\Modules\Template\Actions;

use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\StoredTemplates\Models\StoredTemplateVersion;
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
 * ## A MARKETING SABLON is publikálható
 *
 * …de az `unsubscribe` blokk helyőrzői (`{{ unsubscribe_url }}`) a tranzakciós úton nem
 * helyettesítődnek be, mert a `Message` csak consent-köteles csatornán ad leiratkozó
 * fejlécet és linket. Ezért a publikálás JELZI, ha a sablon marketing: a hívónak tudnia
 * kell, hogy ezt a verziót tranzakciós levélben nem érdemes használni.
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
                'body' => (string) $template->html,
                'body_text' => (string) $template->text,
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
