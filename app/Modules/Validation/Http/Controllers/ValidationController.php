<?php

declare(strict_types=1);

namespace App\Modules\Validation\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Billing\Enums\UsageDimension;
use App\Modules\Billing\Services\Metering;
use App\Modules\Logs\Models\ErasedRecipient;
use App\Modules\Validation\Jobs\ProcessValidationJob;
use App\Modules\Validation\Models\ValidationJob;
use App\Modules\Validation\Models\ValidationResult;
use App\Modules\Validation\Services\AddressValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * `POST /v1/validate` — egyedi és bulk címellenőrzés (41. kritérium).
 *
 * A SZINKRON válasz azért kell, mert az űrlap-beiratkozás közben fut: a látogató ott áll a
 * képernyő előtt. A BULK viszont sosem lehet szinkron — százezer cím ellenőrzése percek, és
 * a szonda per-domain kerete amúgy is lassítja.
 */
final class ValidationController
{
    public function __construct(private readonly AddressValidator $validator) {}

    public function validateOne(Request $request): JsonResponse
    {
        $check = Validator::make($request->all(), [
            // A séma `varchar(255)`: a 320-as korlát 500-at adott a hosszabb címre. Egy
            // címellenőrző szolgáltatásnak a hosszú szemét a BEMENETE, nem a hibája.
            'address' => ['required', 'string', 'max:254'],
        ]);

        if ($check->fails()) {
            return ApiError::response('VALIDATION_ERROR', 'A cím megadása kötelező.', 400, $check->errors()->toArray());
        }

        $verdict = $this->validator->validate((string) $request->input('address'));

        /*
         | A FOGYÁS MÉRÉSE (C1 · 75. kritérium): egy ELLENŐRZÖTT cím egy fogyás.
         |
         | A mérés az ellenőrzés UTÁN van: egy formai hibán elhasalt kérés (`400`) nem
         | fogyás, mert nem is futott ellenőrzés. A hiba nem buktatja el a választ — a hívó
         | a verdiktet kérte, és azt meg is kapta; a mérés elmaradása bevétel-kiesés, a
         | visszatartott válasz viszont ügyfél-probléma.
         */
        try {
            app(Metering::class)->increment(UsageDimension::Validations, 1);
        } catch (Throwable $e) {
            Log::warning('A címellenőrzés fogyása nem került a meteringbe', ['exception' => $e::class]);
        }

        return response()->json($verdict->toArray());
    }

    /**
     * BULK: a címlista feltöltése.
     *
     * A CSV-t soronként olvassuk, nem egyben: egy százezer soros fájl memóriában
     * megabájtokat jelentene, és a feltöltés a legkiszámíthatatlanabb bemenet.
     */
    public function bulk(Request $request, AuditLogger $audit): JsonResponse
    {
        $check = Validator::make($request->all(), [
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'addresses' => ['required_without:file', 'array', 'max:'.config('validation.bulk.max_rows')],
            'addresses.*' => ['string', 'max:254'],
            'file' => ['required_without:addresses', 'file', 'mimes:csv,txt', 'max:20480'],
        ]);

        if ($check->fails()) {
            return ApiError::response('VALIDATION_ERROR', 'A lista nem értelmezhető.', 400, $check->errors()->toArray());
        }

        $addresses = $request->hasFile('file')
            ? $this->readCsv($request->file('file')->getRealPath())
            : array_map('strval', (array) $request->input('addresses'));

        $addresses = array_values(array_unique(array_filter(array_map('trim', $addresses))));

        if ($addresses === []) {
            return ApiError::badRequest('EMPTY_LIST', 'A lista egyetlen címet sem tartalmaz.');
        }

        if (count($addresses) > (int) config('validation.bulk.max_rows')) {
            return ApiError::badRequest('LIST_TOO_LARGE', 'A lista több sort tartalmaz, mint az engedélyezett maximum.');
        }

        $job = ValidationJob::query()->create([
            'name' => $request->string('name')->toString() ?: null,
            'total' => count($addresses),
        ]);

        $audit->log('validation.bulk_created', context: [
            'job' => $job->uuid,
            'total' => count($addresses),
        ]);

        // A CÍMEK `pending` SORKÉNT az adatbázisba, nem a job payloadjába.
        //
        // Százezer cím a payloadban ~6 MB serializálva, és minden chunk újra sorba tenné —
        // több száz megabájt queue-forgalom egyetlen listára. A sorokból dolgozva a job csak
        // a futás azonosítóját viszi, és az újrapróbálás sem duplikál.
        // A TÖRLÉSI KÉRÉS alatt álló címek KI: a modell `creating` hookja itt nem fut le
        // (az `insertOrIgnore` nem vált ki modell-eseményt), tehát a védelemnek ITT kell
        // lennie. Enélkül a hook halott kód lenne azon az egyetlen úton, ami használja.
        $addresses = array_values(array_filter(
            $addresses,
            fn (string $address) => ! ErasedRecipient::erased($address),
        ));

        foreach (array_chunk($addresses, 1000) as $slice) {
            ValidationResult::query()->insertOrIgnore(array_map(fn (string $address) => [
                'workspace_id' => $job->workspace_id,
                'validation_job_id' => $job->getKey(),
                'address' => $address,
                'result' => 'pending',
                'risk' => 0,
                'reasons' => '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ], $slice));
        }

        ProcessValidationJob::dispatch((int) $job->workspace_id, (string) $job->uuid);

        return response()->json($this->presentJob($job), 202);
    }

    public function show(string $uuid): JsonResponse
    {
        $job = ValidationJob::query()->where('uuid', $uuid)->first();

        if ($job === null) {
            return ApiError::response('UNKNOWN_JOB', 'Ismeretlen ellenőrzési futás.', 404);
        }

        return response()->json($this->presentJob($job));
    }

    /**
     * EXPORT — streamelve.
     *
     * Százezer sor egyben összeállítva megabájtokat tartana a memóriában; a stream soronként
     * adja ki. A `Content-Disposition` szándékos: ez fájl, nem oldal.
     */
    public function export(string $uuid, AuditLogger $audit): mixed
    {
        $job = ValidationJob::query()->where('uuid', $uuid)->first();

        if ($job === null) {
            return ApiError::response('UNKNOWN_JOB', 'Ismeretlen ellenőrzési futás.', 404);
        }

        // AUDITÁLT: ez az ügyfél TELJES címlistájának kimenő útja. Exfiltráció-észlelési
        // szempontból az esemény maga a jel — nem a sorok tartalma.
        $audit->log('validation.exported', context: ['job' => $job->uuid, 'rows' => $job->processed]);

        return new StreamedResponse(function () use ($job): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['address', 'result', 'risk', 'reasons', 'did_you_mean']);

            ValidationResult::query()
                ->where('validation_job_id', $job->getKey())
                ->orderBy('id')
                ->chunk(1000, function ($rows) use ($out): void {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            self::csvSafe((string) $row->address),
                            $row->result,
                            $row->risk,
                            implode('|', (array) $row->reasons),
                            self::csvSafe((string) $row->suggestion),
                        ]);
                    }
                });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="validation-'.$job->uuid.'.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * CSV-FORMULA-INJEKCIÓ elleni védelem.
     *
     * Az Excel és a LibreOffice a `=`, `+`, `-` és `@` kezdetű mezőt KÉPLETKÉNT értékeli ki —
     * akkor is, ha idézőjelben van. A támadási út teljes: egy idegen feliratkozó beírja a
     * `=cmd|' /C calc'!A0@pelda.hu` „címet" a tenant űrlapjába, a tenant bulk-validálja,
     * letölti és megnyitja a CSV-t, és a parancs az Ő gépén fut le.
     *
     * A védekezés a szabvány szerinti: aposztróf-prefix, ami a táblázatkezelőnek azt jelenti,
     * „ez szöveg". A cím maga ettől olvasható marad.
     */
    private static function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /** @return list<string> */
    private function readCsv(string $path): array
    {
        $addresses = [];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        $limit = (int) config('validation.bulk.max_rows');

        while (($row = fgetcsv($handle)) !== false && count($addresses) < $limit) {
            // AZ ELSŐ oszlop a cím. A fejlécsort felismerjük és kihagyjuk — az ügyfél
            // exportált fájlt tölt fel, nem kézzel gépelt listát.
            $value = trim((string) ($row[0] ?? ''));

            if ($value === '' || strtolower($value) === 'address' || strtolower($value) === 'email') {
                continue;
            }

            // A TÚL HOSSZÚ sort VÁGJUK, nem utasítjuk el a fájlt: a lánc úgyis
            // `SYNTAX_INVALID`-ot ad rá, és az ügyfél az exportban látja, melyik sor volt
            // hibás. Az egész feltöltés eldobása egy elgépelt sor miatt aránytalan.
            $addresses[] = mb_substr($value, 0, 254);
        }

        fclose($handle);

        return $addresses;
    }

    /** @return array<string, mixed> */
    private function presentJob(ValidationJob $job): array
    {
        return [
            'id' => $job->uuid,
            'name' => $job->name,
            'status' => $job->status,
            'total' => $job->total,
            'processed' => $job->processed,
            'summary' => [
                'deliverable' => $job->deliverable,
                'risky' => $job->risky,
                'undeliverable' => $job->undeliverable,
                'unknown' => $job->unknown,
            ],
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'expires_at' => $job->expires_at?->toIso8601String(),
        ];
    }
}
