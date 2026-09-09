<?php

declare(strict_types=1);

use App\Modules\StoredTemplates\Models\StoredTemplateVersion;
use App\Modules\StoredTemplates\Services\TemplateRenderer;
use App\Modules\Template\Actions\PublishToStoredTemplate;
use App\Modules\Template\Data\BlockSchema;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Services\BlockTreeValidator;
use App\Modules\Template\Services\HtmlSanitizer;
use App\Modules\Template\Services\MergeTag;
use App\Modules\Template\Services\MjmlWriter;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 | B2 · 58–61., 67. kritérium — block editor.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());
});

/** Egy érvényes, marketing-kész fa (leiratkozó blokkal). */
function validTree(array $extra = []): array
{
    return [[
        'type' => 'section',
        'attrs' => ['background' => '#ffffff', 'padding' => '24px 24px'],
        'children' => [[
            'type' => 'column',
            'children' => array_merge([
                ['type' => 'heading', 'attrs' => ['content' => 'Üdv, {{ nev }}!', 'level' => 1]],
                ['type' => 'text', 'attrs' => ['content' => 'Ez a <strong>hírlevél</strong>.']],
                ['type' => 'button', 'attrs' => ['content' => 'Megnyitom', 'href' => 'https://pelda.hu/x']],
                ['type' => 'unsubscribe', 'attrs' => []],
            ], $extra),
        ]],
    ]];
}

/*
 | 58. A SÉMA — pozitív ÉS negatív esetek
 */

it('az ÉRVÉNYES fa átmegy a validáláson', function () {
    expect(app(BlockTreeValidator::class)->errorsIn(validTree()))->toBe([]);
});

it('a séma GÉPI alakja is elérhető — a ventStudio és az SDK ezt olvassa', function () {
    // A block-tree séma KÖZÖS: ha a két app kódjában két külön értelmezés élne, a
    // szerkesztőben összeállított levél a másik oldalon máshogy néznee ki, és a hibát
    // csak a címzett venné észre.
    $schema = BlockSchema::toJsonSchema();

    expect($schema['$schema'])->toContain('json-schema.org');
    expect($schema['definitions'])->toHaveKeys(['section', 'column', 'unsubscribe', 'root', 'block']);
    expect($schema['definitions']['button']['x-required-attrs'])->toBe(['content', 'href']);
});

it('a NEGATÍV esetek MEGNEVEZETT hibát adnak, nem csendes eldobást', function () {
    // A csendes eldobás azt jelentené, hogy a szerkesztőből eltűnik egy szakasz, a
    // felhasználó viszont a mentés után „sikert" lát — és a hibát a címzett fedezi fel,
    // amikor egy fél levelet kap.
    $validator = app(BlockTreeValidator::class);

    $cases = [
        'ismeretlen típus' => [['type' => 'karusszel']],
        'hiányzó type' => [['attrs' => []]],
        'gyökérben nem section' => [['type' => 'text', 'attrs' => ['content' => 'x']]],
        'kötelező mező hiányzik' => [['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'button', 'attrs' => ['content' => 'Klikk']],   // nincs href
        ]]]]],
        'ismeretlen mező' => [['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'text', 'attrs' => ['content' => 'x', 'aling' => 'center']],
        ]]]]],
        'gyermek ott, ahol nem lehet' => [['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'divider', 'attrs' => [], 'children' => [['type' => 'text', 'attrs' => ['content' => 'x']]]],
        ]]]]],
        'nem szám' => [['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'heading', 'attrs' => ['content' => 'x', 'level' => 'nagy']],
        ]]]]],
    ];

    foreach ($cases as $label => $tree) {
        $errors = $validator->errorsIn($tree);

        expect($errors)->not->toBeEmpty($label);
        // A HIBA MEGNEVEZI A HELYET: a felület rá tud mutatni.
        expect($errors[0])->toBeString();
    }
});

it('a LINKEK csak https, mailto vagy merge-tag lehetnek', function () {
    // A `javascript:` séma egy levélkliensben ritkán fut le, de a WEBES ELŐNÉZETBEN igen —
    // és az a MI originünk. A `http://` pedig a kattintó címzettet titkosítatlan csatornára
    // viszi.
    $validator = app(BlockTreeValidator::class);

    foreach (['javascript:alert(1)', 'http://pelda.hu', 'data:text/html,x', '//evil.example'] as $href) {
        $tree = [['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'button', 'attrs' => ['content' => 'Klikk', 'href' => $href]],
        ]]]]];

        expect($validator->errorsIn($tree))->not->toBeEmpty($href);
    }

    // A MERGE-TAG viszont legitim: a link célja lehet személyre szabott.
    $tree = [['type' => 'section', 'children' => [['type' => 'column', 'children' => [
        ['type' => 'button', 'attrs' => ['content' => 'Fiókom', 'href' => '{{ portal_url }}']],
    ]]]]];

    expect($validator->errorsIn($tree))->toBe([]);
});

it('a NYERS HTML blokk nem tartalmazhat scriptet és eseménykezelőt', function () {
    $validator = app(BlockTreeValidator::class);

    foreach (['<script>alert(1)</script>', '<img src=x onerror="alert(1)">'] as $html) {
        $tree = [['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'html', 'attrs' => ['content' => $html]],
        ]]]]];

        expect($validator->errorsIn($tree))->not->toBeEmpty($html);
    }
});

it('a MÉLYSÉG és a BLOKKSZÁM korlátos', function () {
    // Egy 50 szintű fa a fordítót és a szerkesztőt is megállítja.
    $deep = ['type' => 'column', 'children' => []];

    for ($i = 0; $i < BlockSchema::MAX_DEPTH + 2; $i++) {
        $deep = ['type' => 'column', 'children' => [$deep]];
    }

    expect(app(BlockTreeValidator::class)->errorsIn([['type' => 'section', 'children' => [$deep]]]))
        ->not->toBeEmpty();
});

/*
 | 59. A FORDÍTÁS — MJML → reszponzív HTML + plaintext, STABIL snapshot
 */

it('a block-tree MJML-re, majd reszponzív HTML-re fordul', function () {
    $template = Template::create([
        'name' => 'Hírlevél',
        'subject' => 'Szia',
        'preheader' => 'Előnézeti szöveg',
        'blocks' => validTree(),
    ]);

    // MJML: a `mj-section`/`mj-column` páros a reszponzivitás hordozója.
    expect($template->mjml)->toContain('<mjml>');
    expect($template->mjml)->toContain('<mj-section');
    expect($template->mjml)->toContain('<mj-column');
    expect($template->mjml)->toContain('<mj-preview>Előnézeti szöveg</mj-preview>');

    // HTML: TÁBLÁZATOS elrendezés + `@media` a mobilra. Nem nosztalgia: ez az egyetlen,
    // amit minden levélkliens ugyanúgy ért.
    expect($template->html)->toContain('<!doctype html>');
    expect($template->html)->toContain('role="presentation"');
    expect($template->html)->toContain('@media only screen and (max-width:620px)');
    expect($template->html)->toContain('.vm-col{display:block !important');
    // A PREHEADER rejtett szövegként: a postafiók-lista olvassa, a törzsben nem látszik.
    expect($template->html)->toContain('mso-hide:all');
    // A GOMB táblázat, nem `<a>` paddinggal: az Outlook a linken lévő paddinget eldobja.
    expect($template->html)->toContain('display:inline-table');
});

it('a plaintext a FÁBÓL épül, nem a HTML-ből lecsupaszítva', function () {
    // `strip_tags(html)`-lel a gombok szövege elszakadna a linkjüktől, a bekezdések
    // összefolynának, és az alt-szöveg helyén a fájlnév maradna.
    $template = Template::create([
        'name' => 'Sima',
        'blocks' => validTree([
            ['type' => 'image', 'attrs' => ['src' => 'https://pelda.hu/kep.png', 'alt' => 'Termékfotó']],
        ]),
    ]);

    $text = (string) $template->text;

    // A CÍMSOR aláhúzva: a plaintextben ez az egyetlen módja jelezni, hogy címsor.
    expect($text)->toContain("Üdv, {{ nev }}!\n=");
    // A LINK a szöveg MELLETT: „Kattints ide" önmagában használhatatlan.
    expect($text)->toContain('Megnyitom: https://pelda.hu/x');
    // A KÉP az alt-szövegével létezik, fájlnév nélkül.
    expect($text)->toContain('[Termékfotó]');
    expect($text)->not->toContain('kep.png');
    // A MERGE-TAGEK érintetlenül mennek át: a behelyettesítés a küldéskor történik.
    expect($text)->toContain('{{ unsubscribe_url }}');
});

it('a SNAPSHOT STABIL: ugyanaz a fa ugyanazt a kimenetet adja', function () {
    // Ez a determinizmus a kampány megismételhetőségének alapja — és egy fordító-változás
    // itt bukik el, nem az ügyfél postafiókjában.
    $first = Template::create(['name' => 'A', 'preheader' => 'P', 'blocks' => validTree()]);
    $second = Template::create(['name' => 'B', 'preheader' => 'P', 'blocks' => validTree()]);

    expect($second->render_hash)->toBe($first->render_hash);
    expect($second->html)->toBe($first->html);
    expect($second->text)->toBe($first->text);

    // …és az ÚJRAFORDÍTÁS is ugyanazt adja.
    $hash = $first->render_hash;
    $first->recompile();

    expect($first->render_hash)->toBe($hash);
});

it('a GOLDEN KIMENET egyezik a commitolt fixture-rel', function () {
    /*
     | Miért kell EZ a teszt a fenti mellé.
     |
     | A fenti két renderelést hasonlít össze UGYANABBAN A PROCESSZBEN — definíció szerint
     | nem tud kimutatni fordító-, libxml- vagy PHP-verzió-változást. Az 59. kritérium
     | „stabil snapshot"-ot kér, és ahhoz a várt kimenetnek a REPÓBAN kell lennie.
     |
     | Ha ez a teszt elhasal, két dolog lehet: (a) szándékosan változott a fordító — akkor a
     | fixture-t a diffben LÁTHATÓAN frissítjük, és a review megnézi, mi lett más a levélben;
     | (b) nem szándékosan változott valami a környezetben — és pont ezt akarjuk megtudni a
     | CI-ban, nem az ügyfél postafiókjából.
     |
     | A fixture frissítése: `VM_UPDATE_GOLDEN=1 php artisan test --filter=GOLDEN`.
     */
    $template = Template::create([
        'name' => 'Golden',
        'subject' => 'Szia',
        'preheader' => 'Előnézeti szöveg',
        'blocks' => validTree(),
    ]);

    $path = base_path('tests/Fixtures/template-golden.html');

    if (getenv('VM_UPDATE_GOLDEN') === '1') {
        file_put_contents($path, $template->html);
    }

    expect(file_exists($path))->toBeTrue('Hiányzik a golden fixture: '.$path)
        ->and($template->html)->toBe(file_get_contents($path));

    $textPath = base_path('tests/Fixtures/template-golden.txt');

    if (getenv('VM_UPDATE_GOLDEN') === '1') {
        file_put_contents($textPath, $template->text);
    }

    expect($template->text)->toBe(file_get_contents($textPath));
});

it('a FULL-WIDTH szekció KILÉP a 600 pixeles táblából — a beállítás HAT', function () {
    // Mérve a `full_width: true` és a nélküli fa fordított HTML-je BITRE AZONOS volt: a
    // felhasználó beállított valamit, ami nem hatott. Pontosan az a hibaosztály, amiért a
    // validátorban az ismeretlen-mező szabály létezik.
    $normal = Template::create(['name' => 'Normál', 'blocks' => validTree()]);

    $full = validTree();
    $full[0]['attrs']['full_width'] = true;
    $full = Template::create(['name' => 'Teljes', 'blocks' => $full]);

    expect($full->html)->not->toBe($normal->html)
        ->and($full->mjml)->toContain('full-width="full-width"')
        // A teljes szélességű sáv 100%-os táblát kap, nem a 600 pixeles belsőt.
        ->and($full->html)->toContain('style="width:100%;"');
});

/*
 | 60. MERGE-TAG — auto-escape és fallback
 */

it('a MERGE-TAG határolói átmennek a fordításon — az ÉRTÉK escape-elődik a küldéskor', function () {
    // Ha a fordító escape-elné a kapcsos zárójeleket, a merge-tag SOHA nem
    // helyettesítődne be: a címzett `{{ nev }}`-et látna a levélben.
    $template = Template::create([
        'name' => 'Merge',
        'blocks' => validTree([
            ['type' => 'text', 'attrs' => ['content' => 'Kedves {{ nev || Vásárló }}!']],
        ]),
    ]);

    expect($template->html)->toContain('{{ nev || Vásárló }}');

    // Az ÉRTÉK escape-elése az A1 `TemplateRenderer`-e dolga — egy képesség, egy gazda.
    $renderer = app(TemplateRenderer::class);

    expect($renderer->render('Szia {{ nev }}!', ['nev' => '<script>alert(1)</script>']))
        ->not->toContain('<script>');

    // …és a HIÁNYZÓ változó a fallbackre esik, nem üresre.
    expect($renderer->render('Kedves {{ nev || Vásárló }}!', []))->toBe('Kedves Vásárló!');
});

it('a SZÖVEGES tartalom TILTOTT eleme ELTŰNIK — a merge-tag átmegy', function () {
    // A KONTRAKTUS: a `content` szűk inline HTML (`HtmlSanitizer::inline`). A tiltott elem
    // ezért nem escape-elődik LÁTHATÓ SZEMÉTTÉ (`&lt;script&gt;alert(1)…`, amit a címzett
    // elolvas), hanem tartalmával együtt eltűnik. A `<script>` szövegtartalma KÓD: kibontva
    // a forráskód jelenne meg a levélben.
    $template = Template::create([
        'name' => 'XSS',
        'blocks' => validTree([
            ['type' => 'heading', 'attrs' => ['content' => '<script>alert(1)</script> {{ nev }}']],
        ]),
    ]);

    expect($template->html)->not->toContain('<script')
        ->and($template->html)->not->toContain('alert(1)')
        ->and($template->html)->not->toContain('&lt;script&gt;')
        ->and($template->html)->toContain('{{ nev }}');
});

it('a FORMÁZOTT SZÖVEG ugyanazt mondja a HTML-ben és a plaintextben', function () {
    /*
     | Ez a KONTRAKTUS-teszt. A két író korábban MÁST értett ugyanazon a mezőn: az MJML-író
     | `htmlspecialchars`-olt, a plaintext-író HTML-ként értelmezte. A packet saját
     | fixture-ével mérve a HTML törzsben a címzett a NYERS `<strong>` taget LÁTTA
     | (`&lt;strong&gt;`), a plaintextben pedig a formázás helyesen eltűnt.
     |
     | A `<` mint MATEMATIKAI JEL a másik irány: `Az ár < 1000 Ft` a HTML-ben escape-elve
     | jelenik meg, a plaintextben nyersen — mindkettő helyes, de csak akkor, ha a két író
     | UGYANAZT a bemenetet ugyanúgy értelmezi.
     */
    $template = Template::create([
        'name' => 'Formázás',
        'blocks' => validTree([
            ['type' => 'text', 'attrs' => ['content' => 'Ez a <strong>hírlevél</strong> és az ár &lt; 1000 Ft.']],
        ]),
    ]);

    // HTML: az engedett inline tag MEGMARAD tagként, nem látható szövegként.
    expect($template->html)->toContain('<strong>hírlevél</strong>')
        ->and($template->html)->not->toContain('&lt;strong&gt;')
        // Az `&lt;` mint karakter-entitás megmarad entitásnak (a `<` jel jelenik meg).
        ->and($template->html)->toContain('&lt; 1000 Ft');

    // Plaintext: a formázás eltűnik, a szöveg megmarad, a `<` valódi karakterként.
    expect($template->text)->toContain('Ez a hírlevél és az ár < 1000 Ft.')
        ->and($template->text)->not->toContain('<strong>');
});

it('a NYERS HTML blokk allowlistje kizárja a kitörést és az adathalász elemeket', function () {
    /*
     | Mind a hét alak MÉRVE átment a korábbi két regexen (`<script` és `\son[a-z]+=`), és
     | nyersen benne volt a fordított HTML-ben. A `<base>`/`<form>`/refresh a MI DKIM-ünkkel
     | aláírt levélben adathalászat a saját domainünkön; a `</td></tr></table>` kitör a
     | levél elrendezés-táblájából; az `<img/onerror>` a webes előnézetben (a mi originünk)
     | végrehajtódik.
     |
     | A validátor HANGOSAN elutasítja őket (`ContentScanner`), de a garanciát a KIMENET
     | allowlistje adja — ezért itt a blokkot közvetlenül a fordítón engedjük át.
     */
    $sanitizer = app(HtmlSanitizer::class);

    $cases = [
        '<img/onerror=alert(1) src=x>' => ['onerror', 'alert(1)'],
        '<a href="javascript:alert(1)">Kattints</a>' => ['javascript:'],
        '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>' => ['iframe', 'srcdoc'],
        '<style>a{background:url(javascript:alert(1))}</style>' => ['style>', 'javascript'],
        '<base href="https://evil.example/">' => ['base'],
        '<meta http-equiv="refresh" content="0;url=https://evil.example">' => ['http-equiv'],
        '<form action="http://evil.example"><input name="jelszo"></form>' => ['form', 'input'],
        '<div style="background:url(javascript:alert(1))">x</div>' => ['javascript', 'url('],
    ];

    foreach ($cases as $input => $forbidden) {
        $out = $sanitizer->block($input);

        foreach ($forbidden as $needle) {
            expect($out)->not->toContain($needle, sprintf('„%s" → „%s" benne maradt', $input, $needle));
        }
    }

    // A KITÖRÉS: a korai záró tagek nem tudják elhagyni a befoglaló táblát, mert a
    // kimenetet a DOM szerializálja újra.
    expect($sanitizer->block('</td></tr></table><h1>Átvettem</h1>'))
        ->not->toContain('</table>')
        ->and($sanitizer->block('</td></tr></table><h1>Átvettem</h1>'))->toContain('Átvettem');

    // Amit MEGTART: a levél-elrendezéshez való HTML és a jó link.
    $kept = $sanitizer->block('<table role="presentation"><tr><td align="center">'
        .'<a href="https://pelda.hu/x" target="_blank">Link</a></td></tr></table>');

    expect($kept)->toContain('<table')->and($kept)->toContain('href="https://pelda.hu/x"')
        // A `target="_blank"` `rel` nélkül `window.opener`-t ad a megnyíló lapnak.
        ->and($kept)->toContain('noopener');
});

it('a MERGE-TAG FALLBACK nem csempészhet be végrehajtható vagy titkosítatlan sémát', function () {
    /*
     | A MÉRT RÉS: a `href` séma-allowlistje a merge-tagot egészben engedte, a fallbacket
     | pedig `[^}]*`-gal — tehát a `{{ cta || javascript:alert(1) }}` ÁTMENT a validátoron,
     | beépült a sablonba, és a kimenet `href="javascript:alert(1)"` lett. A nyers
     | `http://`-t a validátor explicit tiltja; a fallbackben mégis átjutott.
     */
    $validator = app(BlockTreeValidator::class);

    foreach (['javascript:alert(1)', 'http://evil.example/phish', 'data:text/html,<script>alert(1)</script>', 'vbscript:x'] as $payload) {
        $errors = $validator->errorsIn(validTree([
            ['type' => 'button', 'attrs' => ['content' => 'Kattints', 'href' => '{{ cta || '.$payload.' }}']],
        ]));

        expect($errors)->not->toBeEmpty(sprintf('a „%s" fallback átment a validátoron', $payload));
    }

    // A JÓ fallback átmegy — a szabály nem tiltja le a személyre szabott linket.
    expect($validator->errorsIn(validTree([
        ['type' => 'button', 'attrs' => ['content' => 'Kattints', 'href' => '{{ cta || https://pelda.hu/akcio }}']],
    ])))->toBe([]);

    // ÉS a kimeneten is: ha a fa mégis megkerülte a validátort (`DB::table()->update()`),
    // a fordító ÜRES `href`-et ír, nem `javascript:`-et.
    $html = app(MjmlWriter::class)->write(validTree([
        ['type' => 'button', 'attrs' => ['content' => 'Kattints', 'href' => '{{ cta || javascript:alert(1) }}']],
    ]));

    expect($html)->not->toContain('javascript:');
});

/*
 | 61. A LEIRATKOZÓ BLOKK KÖTELEZŐ
 */

it('MARKETING sablon leiratkozó blokk NÉLKÜL nem mentődik', function () {
    // A mentésnél, nem a küldésnél: a küldéskori ellenőrzés a kapu dolga, és az a FEJLÉCET
    // nézi. A törzsben lévő link ettől független követelmény — és ha csak a küldés
    // utasítaná el, a felhasználó egy elkészült, ütemezett kampányról tudná meg, hogy
    // használhatatlan.
    $without = [[
        'type' => 'section',
        'children' => [['type' => 'column', 'children' => [
            ['type' => 'text', 'attrs' => ['content' => 'Hírlevél leiratkozás nélkül']],
        ]]],
    ]];

    expect(fn () => Template::create(['name' => 'Hibás', 'marketing' => true, 'blocks' => $without]))
        ->toThrow(InvalidArgumentException::class, 'leiratkozó blokk');

    // A TRANZAKCIÓS sablonban viszont NEM kötelező: egy jelszó-visszaállítóról nem lehet
    // leiratkozni, és a link ott félrevezető lenne.
    $transactional = Template::create(['name' => 'Jelszó', 'marketing' => false, 'blocks' => $without]);

    expect($transactional->exists)->toBeTrue();
});

it('a leiratkozó blokk MÉLYEN beágyazva is felismerődik', function () {
    // A `unsubscribe` KÜLÖN blokktípus, nem „egy text blokk linkkel" — pontosan azért, hogy
    // ez a szabály kikényszeríthető legyen. Egy szöveges link URL-minta szerinti keresése
    // ugyanabba a csapdába vezetne, amibe az A6 leiratkozó fejléce négy körön át beleesett.
    $nested = [[
        'type' => 'section',
        'children' => [['type' => 'column', 'children' => [
            ['type' => 'text', 'attrs' => ['content' => 'Szöveg']],
            ['type' => 'unsubscribe', 'attrs' => ['content' => 'Nem kérem többé']],
        ]]],
    ]];

    expect(Template::create(['name' => 'Mély', 'marketing' => true, 'blocks' => $nested])->exists)->toBeTrue();
});

it('a MARKETING jelölés utólagos bekapcsolása is ellenőrződik', function () {
    // Enélkül a szabály megkerülhető lenne: mentés tranzakcióskánt, majd átjelölés
    // marketingre.
    $template = Template::create([
        'name' => 'Átjelölt',
        'marketing' => false,
        'blocks' => [['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'text', 'attrs' => ['content' => 'x']],
        ]]]]],
    ]);

    expect(fn () => $template->update(['marketing' => true]))->toThrow(InvalidArgumentException::class);
});

/*
 | 67. PUBLIKÁLÁS TÁROLT SABLONNÁ
 */

it('a sablon TÁROLT SABLONNÁ publikálható, és a régi verziók megmaradnak', function () {
    // A Studio sablonja a marketingesé és folyamatosan változik; a tárolt sablon verziója
    // viszont a FEJLESZTŐ szerződése — egy futó integráció nem törhet el attól, hogy valaki
    // a szerkesztőben átírt egy szöveget.
    $template = Template::create(['name' => 'Publikált', 'blocks' => validTree()]);

    $first = app(PublishToStoredTemplate::class)($template);

    expect($first->version)->toBe(1);
    expect($first->is_active)->toBeTrue();
    expect($first->body)->toContain('role="presentation"');
    expect($first->body_text)->toContain('Megnyitom');
    expect($template->fresh()->published_at)->not->toBeNull();

    // ÚJ verzió: a régi érintetlen marad.
    $template->update(['blocks' => validTree([
        ['type' => 'text', 'attrs' => ['content' => 'Új bekezdés']],
    ])]);

    $second = app(PublishToStoredTemplate::class)($template->fresh());

    expect($second->version)->toBe(2);
    // AZ AKTÍVVÁ TÉTEL SZÁNDÉKOS DÖNTÉS, nem a publikálás mellékhatása: egy félkész sablon
    // azonnali aktiválása minden `template=nev` hívást átállítana.
    expect($second->is_active)->toBeFalse();
    expect(StoredTemplateVersion::query()->count())->toBe(2);
    expect(StoredTemplateVersion::query()->where('version', 1)->firstOrFail()->body)
        ->not->toContain('Új bekezdés');
});

it('a KÉT SZABÁLY az ADATBÁZISBAN is áll — a builder-út sem kerüli meg', function () {
    /*
     | Mérve MIND ÁTMENT, mielőtt a trigger megszületett:
     |   Template::query()->update(['marketing' => true])
     |   $template->forceFill(['marketing' => true])->saveQuietly()
     |   DB::table('templates')->update([...])
     |   a `sending` kampányon builder-`update()` átírta a `subject`-et és a `target_lists`-et
     |
     | Ma nincs más író, tehát nem volt kihasználható — de semmi nem tartotta zárva. Ebben a
     | projektben ez a hibaosztály többször visszatért: ami a fejlesztő emlékezetén múlik
     | minden hívási helyen, azt valahol elfelejtjük.
     */
    // A `marketing` alapértéke TRUE (a sablon alapból hírlevél), ezért itt kimondjuk.
    $template = Template::create(['name' => 'Nem marketing', 'marketing' => false, 'blocks' => [[
        'type' => 'section',
        'children' => [['type' => 'column', 'children' => [
            ['type' => 'text', 'attrs' => ['content' => 'Semmi leiratkozás.']],
        ]]],
    ]]]);

    /*
     | MINDEN DB-SZINTŰ elutasítás SAVEPOINTBAN.
     |
     | A trigger kivétele a Postgresben ABORTÁLJA a tranzakciót, a `RefreshDatabase` pedig
     | egy tranzakcióban futtatja a tesztet: a következő utasítás különben
     | `SQLSTATE[25P02] … current transaction is aborted`-dal hasal el, és a teszt a második
     | esetet meg sem tudná nézni. A beágyazott `DB::transaction` savepointot nyit, amire
     | vissza lehet gördülni.
     */
    $savepoint = static fn (callable $write): callable => static fn () => DB::transaction($write);

    // 1. A BUILDER-ÚT: `update()` a modell hookjait megkerüli — a trigger nem.
    expect($savepoint(fn () => Template::query()->whereKey($template->getKey())->update(['marketing' => true])))
        ->toThrow(QueryException::class, 'leiratkozó blokk');

    // 2. A `saveQuietly()` sem: az az ESEMÉNYEKET hallgattatja el (tehát a modell `saving`
    //    hookját is), nem az adatbázist. Pontosan ez az az út, amit a review mérve
    //    átjutónak talált.
    expect($savepoint(fn () => $template->forceFill(['marketing' => true])->saveQuietly()))
        ->toThrow(QueryException::class, 'leiratkozó blokk');

    // 3. A NYERS `DB::table()` sem.
    expect($savepoint(fn () => DB::table('templates')->where('id', $template->getKey())->update(['marketing' => true])))
        ->toThrow(QueryException::class, 'leiratkozó blokk');

    // …és a LEIRATKOZÓ BLOKKAL rendelkező sablon marketingként MENTHETŐ: a szabály nem
    // tiltja a legitim esetet.
    $rendben = Template::create(['name' => 'Marketing', 'marketing' => true, 'blocks' => validTree()]);

    expect($rendben->marketing)->toBeTrue();
});

it('a MERGE-TAG HATÁROLÓJA MINDKÉT libxml-kódolásból helyreáll', function () {
    /*
     | Ez a teszt a golden fixture ELSŐ CI-futásából született.
     |
     | A libxml az URI-attribútumokban percent-kódol, és hogy MIT, az a VERZIÓJÁTÓL függ:
     |   lokális: href="{{%20unsubscribe_url%20}}"
     |   CI:      href="%7B%7B%20unsubscribe_url%20%7D%7D"
     | A visszaállítás korábban két helyen, két külön mintával élt, és mindkettő csak az
     | egyik alakot ismerte. A CI-ban ezért a leiratkozó link
     | `href="%7B%7B%20unsubscribe_url%20%7D%7D"` lett — amit a behelyettesítő minta már nem
     | illeszt, tehát a címzett HALOTT linket kapott volna. Minden „sikeresnek" látszott.
     |
     | Egy környezet SOSEM tudja mindkét alakot előállítani, ezért itt közvetlenül a
     | helyreállítót hívjuk — a golden fixture a másik oldalról fogja meg ugyanezt.
     */
    expect(MergeTag::restore('<a href="%7B%7B%20unsubscribe_url%20%7D%7D">L</a>'))
        ->toBe('<a href="{{ unsubscribe_url }}">L</a>')
        ->and(MergeTag::restore('<a href="{{%20unsubscribe_url%20}}">L</a>'))
        ->toBe('<a href="{{ unsubscribe_url }}">L</a>')
        // Az ÉRINTETLEN alak sem sérül.
        ->and(MergeTag::restore('<a href="{{ unsubscribe_url }}">L</a>'))
        ->toBe('<a href="{{ unsubscribe_url }}">L</a>');

    // A FALLBACK is helyreáll — a `||` kódolt alakjából is.
    expect(MergeTag::restore('href="%7B%7B%20cta%20%7C%7C%20https://pelda.hu/x%20%7D%7D"'))
        ->toBe('href="{{ cta || https://pelda.hu/x }}"');

    // …de a fallback BELSEJÉBEN a `%20` MEGMARAD: az egy jogosan URL-kódolt link, és a
    // dekódolás mérve `https://a.hu/x y`-ra rontotta el.
    expect(MergeTag::restore('href="{{ url || https://a.hu/x%20y }}"'))
        ->toBe('href="{{ url || https://a.hu/x%20y }}"');
});
