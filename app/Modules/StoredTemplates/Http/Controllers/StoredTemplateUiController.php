<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Http\Controllers;

use App\Modules\StoredTemplates\Http\Requests\StoreVersionRequest;
use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\StoredTemplates\Models\StoredTemplateVersion;
use App\Modules\StoredTemplates\Services\VersionDiff;
use Illuminate\Database\UniqueConstraintViolationException;
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
                // Az új-verzió űrlap ELŐTÖLTÉSE: az aktív verzió tárgya (különben a törzs
                // szerkesztése közben a tárgy elveszne).
                'activeSubject' => $versions->firstWhere('is_active', true)?->subject,
                'versions' => $versions->map(fn (StoredTemplateVersion $version) => [
                    'version' => (int) $version->version,
                    'active' => (bool) $version->is_active,
                    'createdAt' => $version->created_at?->toIso8601String(),
                    'bytes' => strlen((string) $version->body),
                    'subject' => $version->subject,
                ])->values()->all(),
            ],

            'comparison' => $left === null || $right === null ? null : [
                'left' => (int) $left->version,
                'right' => (int) $right->version,
                ...$diff->compare((string) $left->body, (string) $right->body),
            ],

            // A KORLÁT a `comparison.limit`-ben jön, MERT AZT A DIFF SZÁMOLTA.
            //
            // Egy MÁSODIK `regionLimit()` hívás itt kisebb számot adna: a diff futása után
            // a `memory_get_usage(true)` már megnőtt. Mérve egyetlen kérésen belül: a
            // `compare()` 1305-tel dolgozott, a felületnek 1007 ment volna ki — vagyis a
            // „nem fut le X sor fölött" üzenet olyan számot írt volna ki, amivel a
            // felhasználó nem tud számolni (1200 sorra újrapróbálva működne).
            'maxLines' => VersionDiff::MAX_REGION_LINES,
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

    /**
     * ÚJ VERZIÓ (tárggyal). A meglévő verzió tartalma nem írható át — ez mindig új sor.
     * Ugyanaz a zárolás + deaktiválás, mint az API-oldali verzió-létrehozásnál.
     */
    public function storeVersion(StoreVersionRequest $request, string $uuid): RedirectResponse
    {
        $template = StoredTemplate::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validated();
        $activate = (bool) ($data['activate'] ?? false);

        try {
            DB::transaction(function () use ($template, $data, $activate): void {
                $locked = StoredTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();

                if ($activate) {
                    $locked->versions()->where('is_active', true)->get()
                        ->each(fn (StoredTemplateVersion $current) => $current->update(['is_active' => false]));
                }

                StoredTemplateVersion::create([
                    'stored_template_id' => $locked->getKey(),
                    'version' => $locked->nextVersionNumber(),
                    'body' => $data['body'],
                    'body_text' => $data['body_text'] ?? null,
                    'subject' => ($data['subject'] ?? null) === '' ? null : ($data['subject'] ?? null),
                    'is_active' => $activate,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return back()->with('error', __('ventmail.storedTemplates.versionConflict'));
        }

        return back()->with('success', __('ventmail.storedTemplates.versionCreated'));
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
