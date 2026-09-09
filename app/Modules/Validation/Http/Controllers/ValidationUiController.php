<?php

declare(strict_types=1);

namespace App\Modules\Validation\Http\Controllers;

use App\Modules\Billing\Enums\UsageDimension;
use App\Modules\Billing\Services\Metering;
use App\Modules\Validation\Models\ValidationJob;
use App\Modules\Validation\Services\AddressValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CÍMELLENŐRZÉS képernyője (39–42. kritérium).
 *
 * ## Miért kell felület, ha van API
 *
 * Mert a legtöbb elgépelt cím ott keletkezik, ahol egy EMBER begépeli — és a
 * `gmial.com` javaslat egy kattintással megmenti azt a címet, ami a listán örökre
 * bounce-olna. Az API az integrációé; ez a képernyő az emberé.
 *
 * ## A SZONDA nem ingyenes, és ezt kimondjuk
 *
 * A mailbox-szonda RBL-kockázatot hordoz (a fogadók a gyakori próbálkozást büntetik),
 * ezért fékezett, cache-elt, és MÉRVE van (`validations_counter`). A képernyő ezért nem
 * ad „ellenőrizz mindent" gombot, és a futtatás külön képességhez kötött.
 */
final class ValidationUiController
{
    public function index(): Response
    {
        Gate::authorize('validation.run');

        return $this->page();
    }

    /**
     * A KÉPERNYŐ — verdikttel vagy anélkül.
     *
     * @param  array<string, mixed>|null  $verdict
     */
    private function page(?array $verdict = null): Response
    {
        return Inertia::render('Validation/Index', [
            'verdict' => $verdict,
            // A BULK-FUTÁSOK állapota: a feltöltött lista az ügyfél TELJES címlistája
            // lehet, ezért rövid retencióval él — a képernyő a lejáratot is mutatja.
            'jobs' => ValidationJob::query()
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (ValidationJob $job) => [
                    'uuid' => (string) $job->uuid,
                    'name' => (string) $job->name,
                    'status' => (string) $job->status,
                    'total' => (int) $job->total,
                    'processed' => (int) $job->processed,
                    'reason' => $job->reason,
                    'finishedAt' => $job->finished_at?->toIso8601String(),
                    'expiresAt' => $job->expires_at?->toIso8601String(),
                ])->values()->all(),

            // A HAVI FOGYÁS: az ellenőrzés számlázott dimenzió, tehát a felhasználónak
            // látnia kell, mennyit használt el — utólag magyarázni a számlát rossz üzlet.
            'usage' => app(Metering::class)->current(UsageDimension::Validations),
        ]);
    }

    public function check(Request $request, AddressValidator $validator): Response
    {
        Gate::authorize('validation.run');

        $validated = $request->validate([
            'email' => ['required', 'string', 'max:320'],
        ]);

        $verdict = $validator->validate($validated['email']);

        /*
         | A VÁLASZ MAGA A KÉPERNYŐ, nem átirányítás.
         |
         | Az Inertia a POST válaszát oldalként jeleníti meg, tehát az eredmény ott van,
         | ahol a kérdést feltették — a session-t nem kell terhelni vele. Egy frissítés
         | pedig GET-et küld ugyanarra az URL-re, tehát a verdikt (benne a megadott
         | e-mail-címmel) NEM ragad be a böngésző history-jába.
         */
        return $this->page($verdict->toArray());
    }
}
