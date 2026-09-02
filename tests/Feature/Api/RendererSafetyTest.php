<?php

declare(strict_types=1);

use App\Modules\StoredTemplates\Services\TemplateRenderer;

/*
 | A renderer biztonsági tulajdonságai.
 |
 | A `%recipient.x%` behelyettesítés EREDMÉNYE korábban újra átment a `{{ }}` renderelésen:
 | a `htmlspecialchars` a `{` és a `%` karaktert nem escape-eli, tehát egy hívótól jövő
 | érték MÁSODSZOR is kiértékelődött. Cross-recipient szivárgás ma nem volt előállítható,
 | de ez kész gadget: amint a változó-kör nő (B1 kontakt-attribútumok), leakké válik.
 */

it('a behelyettesített érték NEM értékelődik ki újra', function () {
    $rendered = app(TemplateRenderer::class)->renderForRecipient(
        '<p>%recipient.nev%</p>',
        'a@example.test',
        ['a@example.test' => ['nev' => '{{ titok }}'], 'b@example.test' => ['titok' => 'MÁSIK-TENANT-ADATA']],
        ['titok' => 'MEGOSZTOTT-TITOK'],
    );

    // A `{{ titok }}` szövegként jelenik meg, nem behelyettesítve.
    expect($rendered)->not->toContain('MEGOSZTOTT-TITOK');
    expect($rendered)->toContain('titok');
});

it('egyik címzett nem látja a másik változóit', function () {
    $rendered = app(TemplateRenderer::class)->renderForRecipient(
        '<p>%recipient.nev%</p>',
        'a@example.test',
        [
            'a@example.test' => ['nev' => 'Anna'],
            'b@example.test' => ['nev' => 'Béla', 'titkos' => 'BÉLA-ADATA'],
        ],
    );

    expect($rendered)->toContain('Anna')->not->toContain('BÉLA-ADATA');
});

it('a címzett-kulcsok kis-nagybetűre érzéketlenek', function () {
    // A címzett a payloadban kisbetűsítve tárolódik, a `recipient-variables` kulcsait
    // viszont a hívó írja — a nem illeszkedő kulcs korábban ÜRES behelyettesítést adott,
    // és a levél mégis kimegy.
    $rendered = app(TemplateRenderer::class)->renderForRecipient(
        '<p>Szia %recipient.nev%</p>',
        'anna@example.test',
        ['Anna@Example.TEST' => ['nev' => 'Anna']],
    );

    expect($rendered)->toContain('Anna');
});

it('a sablon és a címzett-változók EGYÜTT működnek', function () {
    // A `{{ }}` szintaxis is a címzett változóit látja — korábban a sablon-renderelés a
    // megosztott változókkal lefutott, és a `{{ first_name }}` véglegesen üresre cserélődött.
    $rendered = app(TemplateRenderer::class)->renderForRecipient(
        '<p>Szia {{ nev }}, egyenleg: %recipient.egyenleg%</p>',
        'anna@example.test',
        ['anna@example.test' => ['nev' => 'Anna', 'egyenleg' => 100]],
    );

    expect($rendered)->toContain('Szia Anna')->toContain('100');
});

it('érvénytelen UTF-8 esetén sem tűnik el a behelyettesítés', function () {
    $rendered = app(TemplateRenderer::class)->render('<p>{{ nev }}</p>', ['nev' => "Anna\xC3\x28"]);

    expect($rendered)->toContain('Anna');
});
