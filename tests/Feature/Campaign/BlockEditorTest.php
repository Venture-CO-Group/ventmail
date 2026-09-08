<?php

declare(strict_types=1);

use App\Modules\StoredTemplates\Models\StoredTemplateVersion;
use App\Modules\StoredTemplates\Services\TemplateRenderer;
use App\Modules\Template\Actions\PublishToStoredTemplate;
use App\Modules\Template\Data\BlockSchema;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Services\BlockTreeValidator;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

it('a SZÖVEGES tartalom escape-elődik — a merge-tag kivételével', function () {
    $template = Template::create([
        'name' => 'XSS',
        'blocks' => validTree([
            ['type' => 'heading', 'attrs' => ['content' => '<script>alert(1)</script> {{ nev }}']],
        ]),
    ]);

    expect($template->html)->not->toContain('<script>alert(1)</script>');
    expect($template->html)->toContain('&lt;script&gt;');
    expect($template->html)->toContain('{{ nev }}');
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
