<?php

declare(strict_types=1);

namespace App\Modules\Template\Http\Controllers;

use App\Modules\Template\Data\BlockSchema;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Services\BlockTreeValidator;
use App\Modules\Template\Services\MjmlCompiler;
use App\Modules\Template\Services\MjmlWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SABLONOK ÉS BLOKK-SZERKESZTŐ (58–61. kritérium).
 *
 * ## A SZERKESZTŐ a BLOCK-TREE-t szerkeszti, nem HTML-t
 *
 * A block-tree a ventStudióval KÖZÖS kontraktus (`BlockSchema::toJsonSchema()`), és a
 * levél HTML-je ebből fordul (MJML). Aki HTML-t szerkeszt, az a reszponzivitást és a
 * levélkliens-kompatibilitást is magára vállalja — pont azt, amiért az MJML van.
 *
 * ## A LEIRATKOZÓ BLOKK nem díszítés
 *
 * A marketing sablonnak KELL tartalmaznia leiratkozót (61. kritérium), és ezt a
 * `BlockTreeValidator` kényszeríti ki — a felület csak MEGMUTATJA a hibát, nem ő dönti el.
 * Egy „majd a küldésnél kiderül" viselkedés azt jelentené, hogy a marketinges a kampány
 * indításakor szembesül vele.
 */
final class TemplateUiController
{
    public function index(): Response
    {
        Gate::authorize('template.create');

        return Inertia::render('Template/Index', [
            'templates' => Template::query()->orderBy('name')->get()->map(fn (Template $template) => [
                'uuid' => (string) $template->uuid,
                'name' => (string) $template->name,
                'subject' => (string) $template->subject,
                'marketing' => (bool) $template->marketing,
                'blockCount' => count((array) $template->blocks),
                'updatedAt' => $template->updated_at?->toIso8601String(),
            ])->values()->all(),

            // A SÉMA a felületnek is kell: a szerkesztő ebből tudja, milyen blokk hova
            // tehető, és melyik mező kötelező. Egy külön, kézzel karbantartott lista a
            // felületen előbb-utóbb elcsúszna a validátortól.
            'schema' => BlockSchema::toJsonSchema(),
        ]);
    }

    public function show(string $uuid, MjmlWriter $writer, MjmlCompiler $compiler): Response
    {
        Gate::authorize('template.create');

        $template = Template::query()->where('uuid', $uuid)->firstOrFail();

        return Inertia::render('Template/Edit', [
            'template' => [
                'uuid' => (string) $template->uuid,
                'name' => (string) $template->name,
                'subject' => (string) $template->subject,
                'preheader' => $template->preheader,
                'marketing' => (bool) $template->marketing,
                'blocks' => (array) $template->blocks,
            ],
            'schema' => BlockSchema::toJsonSchema(),
            // AZ ELŐNÉZET a VALÓDI fordítón megy át: amit itt lát, az megy ki. Egy külön
            // „előnézet-renderer" a legelső eltérésnél hazuggá válna.
            'preview' => $this->preview($template, $writer, $compiler),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('template.create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:255'],
            'marketing' => ['boolean'],
        ]);

        $marketing = (bool) ($validated['marketing'] ?? true);

        /*
         | AZ ÚJ SABLON NEM ÜRES — és ez nem kényelmi döntés.
         |
         | A `Template` modell tiltja a leiratkozó blokk nélküli MARKETING sablont (61.
         | kritérium), tehát egy üres marketing sablon LÉTRE SEM JÖHET: a mentés kivétellel
         | állna meg, és a felhasználó egy hibaüzenettel találkozna az első kattintásra.
         |
         | Ezért indul egy minimális, ÉRVÉNYES fával: egy szakasz, benne egy szöveg-blokk és
         | a leiratkozó. Aki nem marketinget ír, kiveszi a jelölést — és akkor a leiratkozó
         | sem kötelező.
         */
        $blocks = $marketing
            ? [[
                'type' => 'section',
                'children' => [[
                    'type' => 'column',
                    'children' => [
                        // A MEZŐK az `attrs` alatt élnek — ez a block-tree kontraktus
                        // alakja (a ventStudio és a `@venture/sdk` is ezt olvassa).
                        ['type' => 'text', 'attrs' => ['content' => 'Írd ide a levél szövegét.']],
                        ['type' => 'unsubscribe'],
                    ],
                ]],
            ]]
            : [];

        $template = Template::create([
            'name' => $validated['name'],
            'subject' => $validated['subject'],
            'marketing' => $marketing,
            'blocks' => $blocks,
        ]);

        return redirect()->route('templates.show', ['uuid' => $template->uuid]);
    }

    public function update(Request $request, string $uuid, BlockTreeValidator $validator): RedirectResponse
    {
        Gate::authorize('template.create');

        $template = Template::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:255'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'blocks' => ['present', 'array'],
        ]);

        /*
         | A BLOKK-FA ELLENŐRZÉSE a MENTÉSNÉL, nem a küldésnél.
         |
         | A `BlockTreeValidator` ugyanaz, amit a küldő út használ — a hibát ott mutatjuk
         | meg, ahol keletkezett. A `errorsIn()` a teljes hibalistát adja, nem az elsőt: a
         | szerkesztőben az a hasznos, ha egyszerre látszik minden javítanivaló.
         */
        $errors = $validator->errorsIn($validated['blocks']);

        if ($errors !== []) {
            throw ValidationException::withMessages(['blocks' => $errors]);
        }

        try {
            $template->update([
                'name' => $validated['name'],
                'subject' => $validated['subject'],
                'preheader' => $validated['preheader'] ?? null,
                'blocks' => $validated['blocks'],
            ]);
        } catch (\InvalidArgumentException $e) {
            /*
             | A MODELL SAJÁT SZABÁLYA (marketing sablon leiratkozó blokk nélkül) kivétellel
             | áll meg — és ez helyes: a szabály nem a felületé, hanem az adaté. A
             | szerkesztőben viszont MEZŐ-HIBA a helyes alak, nem 500-as oldal: a
             | felhasználó ott javítja, ahol elrontotta.
             */
            throw ValidationException::withMessages(['blocks' => [$e->getMessage()]]);
        }

        return back()->with('success', __('ventmail.templates.saved'));
    }

    /** @return array{html: ?string, error: ?string} */
    private function preview(Template $template, MjmlWriter $writer, MjmlCompiler $compiler): array
    {
        $blocks = (array) $template->blocks;

        if ($blocks === []) {
            return ['html' => null, 'error' => null];
        }

        try {
            return ['html' => $compiler->compile($writer->write($blocks, $template->preheader)), 'error' => null];
        } catch (\Throwable $e) {
            // A HIBÁS FA nem 500: a szerkesztő épp azért van, hogy javítani lehessen.
            return ['html' => null, 'error' => $e->getMessage()];
        }
    }
}
