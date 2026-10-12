<?php

declare(strict_types=1);

use App\Modules\StoredTemplates\Services\ConditionalContent;
use App\Modules\StoredTemplates\Services\TemplateRenderer;
use App\Modules\Template\Data\BlockSchema;
use App\Modules\Template\Data\StarterTemplates;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Services\BlockTreeValidator;
use App\Modules\Template\Services\HtmlSanitizer;
use App\Modules\Template\Services\MjmlCompiler;
use App\Modules\Template\Services\MjmlWriter;
use App\Modules\Template\Services\PlainTextWriter;
use App\Modules\Template\Services\TemplateCompliance;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | v1.1 · 4. modul — a BLOKKMOTOR bővítése (D2): új blokkok, feltétel, globális stílus,
 | kötelező lábléc, indulósablonok. A golden fixture (BlockEditorTest) őrzi, hogy a régi fa
 | kimenete bitre azonos maradt.
 */

beforeEach(function () {
    $this->workspace = Workspace::factory()->create(['name' => 'Nádor Kávéház']);
    app(WorkspaceContext::class)->set($this->workspace);
});

/** @param list<array<string, mixed>> $children */
function v11Tree(array $children, array $sectionAttrs = []): array
{
    return [['type' => 'section', 'attrs' => $sectionAttrs, 'children' => [['type' => 'column', 'children' => $children]]]];
}

function v11Footer(array $attrs = []): array
{
    return ['type' => 'footer', 'attrs' => array_merge(['address' => "1000 Budapest\nMinta utca 1.", 'company' => 'Nádor Kft.'], $attrs)];
}

function v11Compile(array $blocks, ?array $styles = null): string
{
    return app(MjmlCompiler::class)->compile(app(MjmlWriter::class)->write($blocks, null, $styles));
}

it('az új blokktípusok a sémában vannak, és a gépi alakban is látszanak', function () {
    $schema = BlockSchema::toJsonSchema();

    foreach (['logo', 'video', 'product', 'survey', 'footer'] as $type) {
        expect($schema['definitions'])->toHaveKey($type);
        expect($schema['definitions']['column']['x-allowed-children'])->toContain($type);
    }

    expect($schema['definitions']['footer']['x-required-attrs'])->toBe(['address'])
        // A KÖTELEZŐ elemeket hordozó blokk NEM lehet feltételes.
        ->and($schema['definitions']['footer']['x-optional-attrs'])->not->toContain('condition')
        ->and($schema['definitions']['unsubscribe']['x-optional-attrs'])->not->toContain('condition');
});

it('az új blokkok érvényes fája átmegy, és mindegyik megjelenik a HTML-ben és a plaintextben', function () {
    $blocks = v11Tree([
        ['type' => 'logo', 'attrs' => ['src' => 'https://pelda.hu/logo.png', 'alt' => 'Nádor']],
        ['type' => 'video', 'attrs' => ['thumbnail' => 'https://pelda.hu/v.png', 'href' => 'https://pelda.hu/video', 'play_label' => 'Lejátszás']],
        ['type' => 'product', 'attrs' => ['name' => 'Single origin', 'price' => '3 490 Ft', 'old_price' => '4 290 Ft', 'image' => 'https://pelda.hu/p.png', 'href' => 'https://pelda.hu/p', 'button_label' => 'Megveszem']],
        ['type' => 'survey', 'attrs' => ['question' => 'Tetszett?', 'base_url' => 'https://pelda.hu/kerdoiv', 'answers' => ['Igen', 'Nem']]],
        ['type' => 'social', 'attrs' => ['mode' => 'share', 'share_url' => 'https://pelda.hu/hir', 'networks' => ['facebook', 'linkedin']]],
        v11Footer(),
    ]);

    expect(app(BlockTreeValidator::class)->errorsIn($blocks))->toBe([]);

    $html = v11Compile($blocks);
    $text = app(PlainTextWriter::class)->write($blocks);

    expect($html)
        ->toContain('src="https://pelda.hu/logo.png"')
        ->toContain('href="https://pelda.hu/video"')
        ->toContain('▶ Lejátszás') // a lejátszás-jel SZÖVEG, képblokkolásnál is látszik
        ->toContain('<s>4 290 Ft</s>')
        ->toContain('https://pelda.hu/kerdoiv?answer=1')
        ->toContain('https://pelda.hu/kerdoiv?answer=2')
        ->toContain('https://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Fpelda.hu%2Fhir')
        ->toContain('https://www.linkedin.com/sharing/share-offsite/?url=')
        // A LÁBLÉC: cím (sortöréssel), leiratkozó és beállítás-link — nem kapcsolható ki.
        ->toContain('1000 Budapest<br>Minta utca 1.')
        ->toContain('href="{{ unsubscribe_url }}"')
        ->toContain('href="{{ preferences_url }}"');

    expect($text)
        ->toContain('Single origin')
        ->toContain('- Igen: https://pelda.hu/kerdoiv?answer=1')
        ->toContain(': {{ unsubscribe_url }}')
        ->toContain('Minta utca 1.');
});

it('a negatív esetek megnevezett hibát adnak: kérdőív, megosztás, szín, feltétel', function () {
    $errors = app(BlockTreeValidator::class)->errorsIn(v11Tree([
        ['type' => 'survey', 'attrs' => ['question' => 'X', 'base_url' => 'javascript:alert(1)', 'answers' => []]],
        ['type' => 'social', 'attrs' => ['mode' => 'share', 'networks' => ['instagram']]],
        ['type' => 'text', 'attrs' => ['content' => 'x', 'color' => 'url(evil)']],
        ['type' => 'text', 'attrs' => ['content' => 'x', 'condition' => ['kind' => 'field', 'field' => 'password', 'op' => 'equals', 'value' => 'a']]],
        ['type' => 'text', 'attrs' => ['content' => 'x', 'hide_on_mobile' => 'igen']],
    ]));

    $joined = implode("\n", $errors);

    expect($joined)
        ->toContain('base_url')
        ->toContain('answers')
        ->toContain('share_url')
        ->toContain('ismeretlen hálózat')
        ->toContain('hex szín')
        ->toContain('condition')
        ->toContain('hide_on_mobile');
});

it('a lábléc / leiratkozó blokkot tartalmazó szakasz NEM lehet feltételes', function () {
    $condition = ['kind' => 'segment', 'segment' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', 'op' => 'in'];

    $errors = app(BlockTreeValidator::class)->errorsIn(v11Tree([v11Footer()], ['condition' => $condition]));

    expect(implode(' ', $errors))->toContain('nem lehet feltételes');
});

it('a FELTÉTELES blokk címzettenként dől el — mező és szegmens szerint', function () {
    $segment = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

    $html = v11Compile(v11Tree([
        ['type' => 'text', 'attrs' => ['content' => 'Mindenkinek']],
        ['type' => 'text', 'attrs' => ['content' => 'Csak VIP', 'condition' => ['kind' => 'segment', 'segment' => $segment, 'op' => 'in']]],
        ['type' => 'text', 'attrs' => ['content' => 'Budapestieknek', 'condition' => ['kind' => 'field', 'field' => 'contact.attributes.city', 'op' => 'equals', 'value' => 'Budapest']]],
    ]));

    $renderer = app(TemplateRenderer::class);

    $vip = $renderer->renderForRecipient($html, 'a@pelda.hu', [], ['contact' => ['segments' => [$segment], 'attributes' => ['city' => 'Szeged']]], conditions: true);
    $local = $renderer->renderForRecipient($html, 'b@pelda.hu', [], ['contact' => ['segments' => [], 'attributes' => ['city' => 'budapest']]], conditions: true);
    $nobody = $renderer->render($html, [], conditions: true);

    expect($vip)->toContain('Csak VIP')->not->toContain('Budapestieknek')->toContain('Mindenkinek');
    expect($local)->toContain('Budapestieknek')->not->toContain('Csak VIP');
    // ADAT NÉLKÜL a feltételes tartalom rejtve marad, és jelölő sem marad a kimenetben.
    expect($nobody)->toContain('Mindenkinek')->not->toContain('Csak VIP')->not->toContain('vm:if');
});

it('a sérült vagy hamisított feltétel-jelölő ELREJT, nem mutat', function () {
    $content = 'A'.ConditionalContent::openEncoded(base64_encode('{"kind":"nope"}')).'Titok'.ConditionalContent::END.'B'
        .'<!--vm:if:!!!-->X<!--vm:endif-->';

    // A sérült vagy nem értelmezhető jelölő mögötti tartalom ELTŰNIK (fail-closed), és a
    // jelölő maga sem marad a címzettnél.
    expect(ConditionalContent::apply($content, []))->toBe('AB');
});

it('a behelyettesített érték nem tud feltétel-jelölőt becsempészni (a feltétel ELŐBB dől el)', function () {
    $marker = ConditionalContent::open(['kind' => 'field', 'field' => 'contact.first_name', 'op' => 'is_set']);

    $out = app(TemplateRenderer::class)->render('Szia {{ contact.first_name }}', [
        'contact' => ['first_name' => $marker.'x'.ConditionalContent::END],
    ], escape: false);

    expect($out)->toBe('Szia '.$marker.'x'.ConditionalContent::END);
});

it('a {{ current_year }} beépített merge-tag, a hívó felülírhatja', function () {
    $renderer = app(TemplateRenderer::class);

    expect($renderer->render('© {{ current_year }}', []))->toBe('© '.now()->format('Y'))
        ->and($renderer->render('© {{ current_year }}', ['current_year' => '1999']))->toBe('© 1999');
});

it('a GLOBÁLIS stílus hat (betű, szín, gomb, szélesség), és a null stílus a régi kimenet', function () {
    $blocks = v11Tree([
        ['type' => 'text', 'attrs' => ['content' => 'Szöveg <a href="https://pelda.hu">link</a>']],
        ['type' => 'button', 'attrs' => ['content' => 'Gomb', 'href' => 'https://pelda.hu']],
    ]);

    $plain = v11Compile($blocks);
    $styled = v11Compile($blocks, [
        'font' => 'serif', 'text_color' => '#112233', 'link_color' => '#aa0000', 'button_background' => '#00aa00',
        'button_radius' => 20, 'width' => 640, 'background' => '#eeeeee',
    ]);

    expect($plain)->toContain('width="600"')->toContain('Helvetica,Arial,sans-serif')->not->toContain('vm-hide-mobile');
    expect($styled)
        ->toContain('width="640"')
        ->toContain('Georgia, Times, serif')
        ->toContain('color:#112233')
        ->toContain('style="color:#aa0000;"')
        ->toContain('background-color:#00aa00')
        ->toContain('border-radius:20px')
        ->toContain('background-color:#eeeeee');
});

it('az „elrejtés mobilon" osztályt és @media szabályt kap — csak ha használják', function () {
    $html = v11Compile(v11Tree([['type' => 'text', 'attrs' => ['content' => 'Asztali', 'hide_on_mobile' => true]]]));

    expect($html)->toContain('<div class="vm-hide-mobile">')->toContain('.vm-hide-mobile{display:none !important');
});

it('a FORMÁZOTT szöveg listát és igazítást tarthat, a tiltott elem eltűnik', function () {
    $out = app(HtmlSanitizer::class)->rich('<p style="text-align:center">Közép</p><ul><li><b>Egy</b></li></ul><img src=x onerror=alert(1)><script>x</script>');

    expect($out)->toContain('<p style="text-align:center">')->toContain('<ul><li><b>Egy</b></li></ul>')
        ->not->toContain('<img')->not->toContain('script');
});

it('a KÖTELEZŐ lábléc-elemek: postai cím, leiratkozó, beállítás', function () {
    $compliance = app(TemplateCompliance::class);

    expect($compliance->missing(v11Tree([v11Footer()])))->toBe([])
        ->and($compliance->missing(v11Tree([['type' => 'unsubscribe']])))->toBe(['postal_address'])
        ->and($compliance->missing(v11Tree([['type' => 'text', 'attrs' => ['content' => 'x']]])))
        ->toBe(['postal_address', 'unsubscribe', 'preferences']);
});

it('a lábléc blokk a modell ÉS a DB-trigger szerint is leiratkozó elem', function () {
    $template = Template::create(['name' => 'Láblécces', 'marketing' => true, 'blocks' => v11Tree([v11Footer()])]);

    expect($template->exists)->toBeTrue()
        ->and(Template::containsUnsubscribe((array) $template->blocks))->toBeTrue();
});

it('MIND A HAT indulósablon érvényes, marketing-kész, és minden nyelven összeáll', function () {
    foreach (['hu', 'en', 'de', 'fr', 'it', 'es', 'ru'] as $locale) {
        app()->setLocale($locale);

        foreach (StarterTemplates::KEYS as $key) {
            $content = StarterTemplates::build($key, $this->workspace);

            expect(app(BlockTreeValidator::class)->errorsIn($content['blocks']))->toBe([], "$locale/$key")
                // A helyőrző cím (S1) mentéskor elfogadott, kampányra NEM: szigorúan hiányzik.
                ->and(app(TemplateCompliance::class)->missing($content['blocks'], strict: false))->toBe([], "$locale/$key")
                ->and(app(TemplateCompliance::class)->missing($content['blocks']))->toBe(['postal_address'], "$locale/$key")
                ->and($content['subject'])->not->toStartWith('editor.');

            $template = Template::create([
                'name' => "$locale-$key",
                'subject' => $content['subject'],
                'marketing' => true,
                'blocks' => $content['blocks'],
                'styles' => $content['styles'],
            ]);

            // A FORDÍTOTT levélben nincs fordítatlan kulcs és nincs harmadik fél képe.
            expect($template->html)->not->toContain('editor.starters')->toContain('/editor/placeholder-');
        }
    }

    expect(StarterTemplates::KEYS)->toHaveCount(6);
});

it('a globális stílus típusos — ismeretlen kulcs vagy szabad CSS a mentést megállítja', function () {
    expect(fn () => Template::create([
        'name' => 'Rossz stílus', 'marketing' => false, 'blocks' => [],
        'styles' => ['font' => 'Comic Sans; background:url(x)'],
    ]))->toThrow(InvalidArgumentException::class, 'styles.font');
});
