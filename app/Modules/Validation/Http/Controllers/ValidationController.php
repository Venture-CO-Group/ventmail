<?php

declare(strict_types=1);

namespace App\Modules\Validation\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\Validation\Jobs\ProcessValidationJob;
use App\Modules\Validation\Models\ValidationJob;
use App\Modules\Validation\Models\ValidationResult;
use App\Modules\Validation\Services\AddressValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'address' => ['required', 'string', 'max:320'],
        ]);

        if ($check->fails()) {
            return ApiError::response('VALIDATION_ERROR', 'A cím megadása kötelező.', 400, $check->errors()->toArray());
        }

        return response()->json($this->validator->validate((string) $request->input('address'))->toArray());
    }

    /**
     * BULK: a címlista feltöltése.
     *
     * A CSV-t soronként olvassuk, nem egyben: egy százezer soros fájl memóriában
     * megabájtokat jelentene, és a feltöltés a legkiszámíthatatlanabb bemenet.
     */
    public function bulk(Request $request): JsonResponse
    {
        $check = Validator::make($request->all(), [
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'addresses' => ['required_without:file', 'array', 'max:'.config('validation.bulk.max_rows')],
            'addresses.*' => ['string', 'max:320'],
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

        ProcessValidationJob::dispatch((int) $job->workspace_id, (string) $job->uuid, $addresses);

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
    public function export(string $uuid): mixed
    {
        $job = ValidationJob::query()->where('uuid', $uuid)->first();

        if ($job === null) {
            return ApiError::response('UNKNOWN_JOB', 'Ismeretlen ellenőrzési futás.', 404);
        }

        return new StreamedResponse(function () use ($job): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['address', 'result', 'risk', 'reasons', 'did_you_mean']);

            ValidationResult::query()
                ->where('validation_job_id', $job->getKey())
                ->orderBy('id')
                ->chunk(1000, function ($rows) use ($out): void {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            $row->address,
                            $row->result,
                            $row->risk,
                            implode('|', (array) $row->reasons),
                            $row->suggestion,
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

    /** @return list<string> */
    private function readCsv(string $path): array
    {
        $addresses = [];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        $limit = (int) config('validation.bulk.max_rows');

        while (($row = fgetcsv($handle)) !== false && count($addresses) <= $limit) {
            // AZ ELSŐ oszlop a cím. A fejlécsort felismerjük és kihagyjuk — az ügyfél
            // exportált fájlt tölt fel, nem kézzel gépelt listát.
            $value = trim((string) ($row[0] ?? ''));

            if ($value === '' || strtolower($value) === 'address' || strtolower($value) === 'email') {
                continue;
            }

            $addresses[] = $value;
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
