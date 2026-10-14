<?php

declare(strict_types=1);

namespace App\Modules\Template\Http\Controllers;

use App\Modules\Domains\Models\Domain;
use App\Modules\Template\Data\BlockSchema;
use App\Modules\Template\Data\EditorCatalog;
use App\Modules\Template\Data\StarterTemplates;
use App\Modules\Template\Models\SavedBlock;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Models\TemplateAsset;
use App\Modules\Template\Services\MjmlCompiler;
use App\Modules\Template\Services\MjmlWriter;
use App\Modules\Template\Services\TemplateEditorSaver;
use App\Modules\Template\Services\TemplatePublishLocked;
use App\Modules\Template\Services\TemplateTestSender;
use App\Modules\Template\Services\TemplateUsage;
use App\Modules\Template\Services\TemplateVersions;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SABLONKÖNYVTÁR ÉS SZERKESZTŐ (58–61. kritérium, v1.1 · 4. modul).
 *
 * ## A SZERKESZTŐ a BLOCK-TREE-t szerkeszti, nem HTML-t
 *
 * A block-tree a ventStudióval KÖZÖS kontraktus (`BlockSchema::toJsonSchema()`), és a
 * levél HTML-je ebből fordul (MJML). Az előnézet és a mentés a SZERVEREN fordít — a vászon
 * csak megjelenít, az igazság a fordító kimenete.
 *
 * ## A KÖTELEZŐ LÁBLÉC nem díszítés
 *
 * A marketing sablonnak KELL tartalmaznia a postai címet, a leiratkozót és a beállítás-
 * linket (`TemplateCompliance`), és ezt a mentési út kényszeríti ki — a felület csak
 * MEGMUTATJA a hibát, nem ő dönti el.
 */
final class TemplateUiController
{
    public function index(WorkspaceContext $context): Response
    {
        Gate::authorize('template.create');

        return Inertia::render('Template/Index', [
            // CSAK az önálló sablonok: a kampány helyben készült tartalma nem könyvtári (UI-polírozás · 6).
            'templates' => Template::query()->library()->withMax('versions', 'number')->orderByDesc('updated_at')->get()->map(fn (Template $template) => [
                'uuid' => (string) $template->uuid,
                'name' => (string) $template->name,
                'subject' => (string) $template->subject,
                'marketing' => (bool) $template->marketing,
                'blockCount' => count((array) $template->blocks),
                'version' => (int) $template->versions_max_number,
                'thumb' => self::thumb((array) $template->blocks),
                'updatedAt' => $template->updated_at?->toIso8601String(),
            ])->values()->all(),
            'savedBlocks' => self::savedBlocks(),
            'starters' => StarterTemplates::catalog(),

            // A SÉMA a felületnek is kell: a szerkesztő ebből tudja, milyen blokk hova
            // tehető, és melyik mező kötelező.
            'schema' => BlockSchema::toJsonSchema(),
        ]);
    }

    public function show(string $uuid, WorkspaceContext $context, TemplateTestSender $tests, MjmlWriter $writer, MjmlCompiler $compiler, TemplateUsage $usage): Response
    {
        Gate::authorize('template.create');

        $template = Template::query()->where('uuid', $uuid)->firstOrFail();
        $workspace = $context->workspace();

        return Inertia::render('Template/Edit', [
            // A KOMPAKT héj (a szerkesztő a teljes szélességet kéri) — `AppLayout` olvassa.
            'editorMode' => true,
            'template' => self::payload($template),
            'catalog' => EditorCatalog::for($workspace),
            'savedBlocks' => self::savedBlocks(),
            'assets' => self::assets(),
            'schema' => BlockSchema::toJsonSchema(),
            'testRecipients' => $tests->allowedRecipients(),
            'senderDomains' => Domain::query()->where('status', 'verified')->orderBy('name')->pluck('name')->values()->all(),
            // AZ ELŐNÉZET a VALÓDI fordítón megy át: amit itt lát, az megy ki.
            'preview' => $this->preview($template, $writer, $compiler),
            // CSAK OLVASHATÓ MÓD (review R4-S3): ha a sablon aktív kampányt / journey-t szolgál, és a
            // felhasználónak nincs publikálási joga, a szerkesztő ezt az okot mutatja, és csak
            // másolatot enged — nem engedi szerkeszteni azt, amit úgysem menthet el.
            'writeDenial' => $usage->writeDenial($template),
        ]);
    }

    public function store(Request $request, WorkspaceContext $context, TemplateEditorSaver $saver, TemplateVersions $versions): RedirectResponse
    {
        Gate::authorize('template.create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', $this->uniqueName()],
            'subject' => ['nullable', 'string', 'max:255'],
            'marketing' => ['boolean'],
            'starter' => ['nullable', 'string', Rule::in(StarterTemplates::KEYS)],
            'from_template' => ['nullable', 'uuid'],
        ]);

        $marketing = (bool) ($validated['marketing'] ?? true);
        $workspace = $context->workspace();

        if (($validated['starter'] ?? null) !== null) {
            $content = StarterTemplates::build($validated['starter'], $workspace);
        } elseif (($validated['from_template'] ?? null) !== null) {
            // A SAJÁT sablon másolata — a global scope miatt idegen workspace sablonja 404.
            $source = Template::query()->where('uuid', $validated['from_template'])->firstOrFail();
            $marketing = (bool) $source->marketing;
            $content = [
                'subject' => (string) $source->subject,
                'preheader' => (string) $source->preheader,
                'blocks' => (array) $source->blocks,
                'styles' => $source->styles,
            ];
        } else {
            $content = self::blankContent($workspace, $marketing);
        }

        $saver->assertValid($content['blocks'], $content['styles'], $marketing);

        $template = DB::transaction(function () use ($validated, $content, $marketing, $versions): Template {
            $template = Template::create([
                'name' => $validated['name'],
                'subject' => ($validated['subject'] ?? '') !== '' ? $validated['subject'] : $content['subject'],
                'preheader' => $content['preheader'],
                'marketing' => $marketing,
                'blocks' => $content['blocks'],
                'styles' => $content['styles'],
                'starter_key' => $validated['starter'] ?? null,
            ]);

            $versions->snapshot($template, 'create');

            return $template;
        });

        return redirect()->route('templates.show', ['uuid' => $template->uuid]);
    }

    /**
     * KÉZI mentés (Inertia űrlap). Mindig új verziót hoz, ha a tartalom változott.
     */
    public function update(Request $request, string $uuid, TemplateEditorSaver $saver, TemplateUsage $usage): RedirectResponse
    {
        Gate::authorize('template.create');

        $template = Template::query()->where('uuid', $uuid)->firstOrFail();

        // B1 · aktív kampány / journey sablonja csak publikálási joggal írható (lásd `TemplateUsage`).
        if (($denial = $usage->writeDenial($template)) !== null) {
            return back()->with('error', $denial);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', $this->uniqueName($template)],
            'subject' => ['nullable', 'string', 'max:255'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'blocks' => ['present', 'array'],
            'styles' => ['nullable', 'array'],
        ]);

        try {
            $saver->save($template, [
                'name' => $validated['name'],
                'subject' => $validated['subject'] ?? null,
                'preheader' => $validated['preheader'] ?? null,
                'blocks' => $validated['blocks'],
                'styles' => $request->has('styles') ? ($validated['styles'] ?? null) : $template->styles,
            ], 'save');
        } catch (TemplatePublishLocked $e) {
            // A zár alatti kapu (SEC4-S1): az előellenőrzés óta lett aktív a sablon.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('ventmail.templates.saved'));
    }

    public function destroy(string $uuid, TemplateUsage $usage): RedirectResponse
    {
        Gate::authorize('template.create');

        $template = Template::query()->where('uuid', $uuid)->firstOrFail();

        // A TÖRLÉS a sablon-sor ZÁRJA ALATT kérdezi a használatokat (review r5 N2): az élesítés
        // (kampány-indítás / ütemezés, journey-publikálás) ugyanezt a sort `FOR SHARE` zárolja, tehát
        // egy közben élesített kampány sablonja nem csúszhat át a törlésen.
        DB::transaction(function () use ($template, $usage): void {
            $locked = Template::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();

            // B2 · FUTÓ kampány / változat / aktív journey sablonja nem törölhető: a folytatás
            // `no_template`-re futna, és a címzettek levél nélkül maradnának.
            $blockers = $usage->blockers($locked);

            if ($blockers['campaigns'] !== [] || $blockers['journeys'] !== []) {
                throw ValidationException::withMessages(['template' => [__('ventmail.editor.inUse', [
                    'names' => implode(', ', [...$blockers['campaigns'], ...$blockers['journeys']]),
                ])]]);
            }

            // SOFT delete: a kampányok `template_id`-je nem lóg a levegőben, és az audit a tényt
            // (`deleted`) megőrzi.
            $locked->delete();
        });

        return redirect()->route('templates.index')->with('success', __('ventmail.editor.deleted'));
    }

    /**
     * AZ ÜRES SABLON SEM ÜRES — a marketing sablon kötelező láblécet kap (postai cím,
     * leiratkozó, beállítások), különben az első mentés elutasítana. Aki nem marketinget ír,
     * kiveszi a jelölést: akkor cégnév + cím láblécet kap, leiratkozó nélkül (D303). A kampány
     * helyben készülő tartalma (UI-polírozás · 6) is ezzel indul.
     *
     * @return array{subject: string, preheader: ?string, blocks: list<array<string, mixed>>, styles: null}
     */
    public static function blankContent(Workspace $workspace, bool $marketing): array
    {
        $paragraph = ['type' => 'section', 'children' => [['type' => 'column', 'children' => [
            ['type' => 'text', 'attrs' => ['content' => __('editor.defaults.paragraph')]],
        ]]]];

        return [
            'subject' => '',
            'preheader' => null,
            'blocks' => $marketing ? [
                $paragraph,
                ['type' => 'section', 'attrs' => ['background' => '#f1f5f9'], 'children' => [['type' => 'column', 'children' => [
                    ['type' => 'footer', 'attrs' => EditorCatalog::footerDefaults($workspace)],
                ]]]],
            ] : [$paragraph, EditorCatalog::transactionalFooter($workspace)],
            'styles' => null,
        ];
    }

    /** @return array<string, mixed> */
    public static function payload(Template $template): array
    {
        return [
            'uuid' => (string) $template->uuid,
            'name' => (string) $template->name,
            'subject' => (string) $template->subject,
            'preheader' => $template->preheader,
            'marketing' => (bool) $template->marketing,
            'blocks' => (array) $template->blocks,
            'styles' => $template->styles,
            'updatedAt' => $template->updated_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function savedBlocks(): array
    {
        return SavedBlock::query()->orderBy('name')->get()->map(fn (SavedBlock $block) => [
            'uuid' => (string) $block->uuid,
            'name' => (string) $block->name,
            'kind' => (string) $block->kind,
            'block' => (array) $block->block,
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public static function assets(): array
    {
        return TemplateAsset::query()->latest()->limit(60)->get()->map(fn (TemplateAsset $asset) => [
            'uuid' => (string) $asset->uuid,
            'url' => (string) $asset->url,
            'name' => (string) $asset->original_name,
            'width' => (int) $asset->width,
            'height' => (int) $asset->height,
            'bytes' => (int) $asset->size,
        ])->values()->all();
    }

    /**
     * A könyvtár-bélyeg: a gyökér-szakaszok háttere és oszlopszáma (legfeljebb 6). A felület
     * ebből rajzol vázlatot — a levél HTML-jét nem ágyazzuk be a listába.
     *
     * @param  array<int, mixed>  $blocks
     * @return list<array{background: ?string, columns: int, kinds: list<string>}>
     */
    public static function thumb(array $blocks): array
    {
        $out = [];

        foreach (array_slice($blocks, 0, 6) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $background = $section['attrs']['background'] ?? null;
            $kinds = [];

            foreach ((array) ($section['children'] ?? []) as $column) {
                foreach ((array) ($column['children'] ?? []) as $block) {
                    $kinds[] = (string) ($block['type'] ?? '');
                }
            }

            $out[] = [
                'background' => is_string($background) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $background) === 1 ? $background : null,
                'columns' => max(1, count((array) ($section['children'] ?? []))),
                'kinds' => array_values(array_unique(array_slice($kinds, 0, 6))),
            ];
        }

        return $out;
    }

    /** A név egyedi az ÉLŐ sablonok között, a saját workspace-en belül. */
    private function uniqueName(?Template $ignore = null): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail) use ($ignore): void {
            $exists = Template::query()
                ->where('name', (string) $value)
                ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->getKey()))
                ->exists();

            if ($exists) {
                $fail(__('ventmail.editor.nameTaken'));
            }
        };
    }

    /** @return array{html: ?string, error: ?string} */
    private function preview(Template $template, MjmlWriter $writer, MjmlCompiler $compiler): array
    {
        $blocks = (array) $template->blocks;

        if ($blocks === []) {
            return ['html' => null, 'error' => null];
        }

        try {
            return ['html' => $compiler->compile($writer->write($blocks, $template->preheader, $template->styles)), 'error' => null];
        } catch (\Throwable $e) {
            // A HIBÁS FA nem 500: a szerkesztő épp azért van, hogy javítani lehessen.
            return ['html' => null, 'error' => $e->getMessage()];
        }
    }
}
