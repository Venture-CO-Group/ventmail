<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Http\Controllers;

use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\StoredTemplates\Models\StoredTemplateVersion;
use App\Modules\StoredTemplates\Services\VersionDiff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TÁROLT SABLONOK képernyője (spec 15.) — a `/v1/templates` API felületi párja.
 *
 * ## Miért kell felület egy fejlesztői API-hoz
 *
 * Mert a sablon-verzió AKTIVÁLÁSA üzemeltetői döntés, nem kódolási lépés: az `active`
 * verzió az, amit a `template=` paraméterrel küldött LEVELEK használnak. Eddig ezt csak
 * API-hívással lehetett átkapcsolni — vagyis egy elrontott verzió visszavonásához a
 * fejlesztőt kellett megkeresni, éles incidens közben.
 *
 * ## Az ÖSSZEHASONLÍTÁS a képernyő lényege
 *
 * A verziók megváltoztathatatlanok, tehát a kérdés sosem az, hogy „mit írjak át", hanem
 * hogy „mi változott az előzőhöz képest" — és ezt egy MEGABÁJTOS HTML-törzsön szemmel
 * összevetni nem lehet. A diffet a `VersionDiff` számolja, a szerveren.
 */
final class StoredTemplateUiController
{
    public function index(Request $request, VersionDiff $diff): Response
    {
        Gate::authorize('storedTemplate.manage');

        // A VERZIÓSZÁM `withCount`-tal: sablononkénti `count()` egy húsz sablonos
        // workspace-en húsz külön kört jelentett.
        $templates = StoredTemplate::query()->withCount('versions')->orderBy('name')->get();

        $selected = $request->string('template')->toString();

        $template = $selected === ''
            ? $templates->first()
            : $templates->firstWhere('name', $selected);

        $versions = $template === null
            ? collect()
            : $template->versions()->get();

        // AZ ALAPÉRTELMEZETT ÖSSZEHASONLÍTÁS: a két legutóbbi verzió. Ez az a kérdés,
        // amit a felhasználó tízből kilencszer feltesz — üres diffel indulni azt jelentené,
        // hogy minden látogatáskor kézzel kell kiválasztania ugyanazt.
        $left = $this->version($versions, $request->integer('left') ?: ($versions->get(1)?->version));
        $right = $this->version($versions, $request->integer('right') ?: ($versions->first()?->version));

        return Inertia::render('StoredTemplates/Index', [
            'templates' => $templates->map(fn (StoredTemplate $item) => [
                'uuid' => (string) $item->uuid,
                'name' => (string) $item->name,
                'description' => $item->description,
                'versions' => (int) $item->versions_count,
            ])->values()->all(),

            'selected' => $template === null ? null : [
                'name' => (string) $template->name,
                'description' => $template->description,
                'versions' => $versions->map(fn (StoredTemplateVersion $version) => [
                    'version' => (int) $version->version,
                    'active' => (bool) $version->is_active,
                    'createdAt' => $version->created_at?->toIso8601String(),
                    'bytes' => strlen((string) $version->body),
                ])->values()->all(),
            ],

            'comparison' => $left === null || $right === null ? null : [
                'left' => (int) $left->version,
                'right' => (int) $right->version,
                ...$diff->compare((string) $left->body, (string) $right->body),
            ],

            // A KORLÁT FUTÁSIDŐBEN dől el (a szabad memóriából), ezért a felület a
            // ténylegesen érvényes számot kapja — nem egy konstanst, ami éles környezetben
            // már nem igaz.
            'maxLines' => $diff->regionLimit(),
        ]);
    }

    /**
     * VERZIÓ AKTIVÁLÁSA.
     *
     * A deaktiválás + aktiválás EGY tranzakcióban és `lockForUpdate`-tel — ugyanaz az
     * indok, mint az API-oldali verzió-létrehozásnál: enélkül két párhuzamos kattintás
     * után a sablon AKTÍV VERZIÓ NÉLKÜL maradhat, és onnantól minden `template=` küldés
     * 400-cal bukik, amíg valaki kézzel nem aktivál.
     */
    public function activate(Request $request, string $uuid, int $version): RedirectResponse
    {
        Gate::authorize('storedTemplate.manage');

        $template = StoredTemplate::query()->where('uuid', $uuid)->firstOrFail();

        $activated = DB::transaction(function () use ($template, $version): bool {
            $locked = StoredTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();

            $target = $locked->versions()->where('version', $version)->first();

            // NEM LÉTEZŐ VERZIÓ: a csendes visszatérés mellett a felület „A verzió aktív
            // lett" üzenetet írt — éles incidens közben az üzemeltető azt hitte volna,
            // hogy visszaállt, miközben semmi nem történt.
            if ($target === null) {
                return false;
            }

            // Modell-példányonként, nem tömeges update-tel: a mass-update kikerülné az
            // auditot (golden rule 11), és utólag nem lenne rekonstruálható, melyik verzió
            // volt élő, amikor egy levél kiment.
            $locked->versions()->where('is_active', true)->get()
                ->each(fn (StoredTemplateVersion $current) => $current->update(['is_active' => false]));

            $target->update(['is_active' => true]);

            return true;
        });

        if (! $activated) {
            return back()->with('error', __('ventmail.storedTemplates.unknownVersion'));
        }

        return back()->with('success', __('ventmail.storedTemplates.activated'));
    }

    /** @param Collection<int, StoredTemplateVersion> $versions */
    private function version($versions, ?int $number): ?StoredTemplateVersion
    {
        if ($number === null || $number === 0) {
            return null;
        }

        return $versions->firstWhere('version', $number);
    }
}
