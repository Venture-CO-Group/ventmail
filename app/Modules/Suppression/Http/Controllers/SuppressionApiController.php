<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\Suppression\Enums\SuppressionReason;
use App\Modules\Suppression\Enums\SuppressionScope;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Suppression\Services\SuppressionStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * `/v1/suppressions` (v1.1 · 7. modul, D174).
 *
 * A `suppressions:read` / `suppressions:write` scope eddig kiállítható volt, de egyetlen
 * végpont sem kérte — ez a gépi párja a tiltólista-képernyőnek, UGYANAZZAL a
 * `SuppressionStore`-ral: a panasz és a leiratkozás NEM törölhető (403
 * `SUPPRESSION_IRREVOCABLE`), a törlés indoklást kér és auditált, platform-szintű tiltás nem
 * érinthető.
 */
final class SuppressionApiController
{
    private const MAX_LIMIT = 1000;

    public function index(Request $request, SuppressionStore $store): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'list' => ['sometimes', 'string', 'in:'.implode(',', array_keys(SuppressionReason::categories()))],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:512'],
            'search' => ['sometimes', 'string', 'max:320'],
        ]);

        if ($validator->fails()) {
            return ApiError::response('VALIDATION_ERROR', 'A lekérdezés paraméterei érvénytelenek.', 400, $validator->errors()->toArray());
        }

        $list = $request->string('list')->toString();
        $reasons = $list !== '' ? SuppressionReason::categories()[$list] : null;

        $page = $store->listForWorkspace(
            (int) $request->integer('limit', 100),
            $request->string('cursor')->toString() ?: null,
            $request->string('search')->toString() ?: null,
            $reasons,
        );

        return response()->json([
            'items' => collect($page->items())->map(fn (Suppression $suppression) => $this->present($suppression))->values(),
            'paging' => ['next' => $page->nextCursor()?->encode()],
        ]);
    }

    public function store(Request $request, SuppressionStore $store): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:320'],
            'reason' => ['sometimes', 'string', 'in:'.implode(',', SuppressionReason::tenantWritable())],
            'applies_to' => ['sometimes', 'string', 'in:'.implode(',', array_map(fn ($case) => $case->value, SuppressionScope::cases()))],
        ]);

        if ($validator->fails()) {
            return ApiError::response('VALIDATION_ERROR', 'A tiltás adatai érvénytelenek.', 400, $validator->errors()->toArray());
        }

        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        try {
            $suppression = $store->add(
                $key,
                (string) $request->input('email'),
                (string) $request->input('reason', 'manual'),
                (string) $request->input('applies_to', 'all'),
            );
        } catch (AuthorizationException) {
            // Csak hiányzó scope-nál (a middleware előtte megfogja) — a platform-szintű
            // tiltás NEM ad külön választ (D187).
            return ApiError::response('INSUFFICIENT_SCOPE', 'Az API-kulcshoz hiányzik a(z) suppressions:write scope.', 403);
        }

        return response()->json($this->present($suppression), 201);
    }

    public function destroy(Request $request, string $email, SuppressionStore $store): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            // Az INDOKLÁS kötelező: a törlés újra küldhetővé teszi a címet.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($validator->fails()) {
            return ApiError::response('VALIDATION_ERROR', 'A törléshez indoklás (`reason`) kötelező.', 400, $validator->errors()->toArray());
        }

        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        try {
            // A route-paramétert a router már dekódolta — még egy `rawurldecode` a `+`/`%`
            // karaktert tartalmazó címeket rontaná el.
            $removed = $store->removeWithJustification($email, (string) $request->input('reason'), 'api:'.$key->prefix);
        } catch (AuthorizationException) {
            return ApiError::response(
                'SUPPRESSION_IRREVOCABLE',
                'A panasz- és leiratkozás-alapú tiltás nem törölhető.',
                403,
            );
        } catch (InvalidArgumentException) {
            return ApiError::response('VALIDATION_ERROR', 'A törléshez indoklás (`reason`) kötelező.', 400);
        }

        if (! $removed) {
            return ApiError::response('UNKNOWN_SUPPRESSION', 'Ez a cím nem szerepel a tiltólistán.', 404);
        }

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(Suppression $suppression): array
    {
        return [
            'id' => (string) $suppression->uuid,
            'email' => (string) $suppression->email,
            // A legacy `hard_bounce`/`soft_bounce` a szerződés `bounce` értékeként megy ki (D206);
            // a hard/soft jelleg a `detail`-ben.
            'reason' => SuppressionReason::fromStored((string) $suppression->reason)?->value ?? (string) $suppression->reason,
            'detail' => self::detail($suppression),
            'applies_to' => (string) $suppression->applies_to,
            'source' => (string) $suppression->source,
            'deletable' => SuppressionReason::fromStored((string) $suppression->reason)?->isIrrevocableByTenant() !== true,
            'expires_at' => $suppression->expires_at?->toIso8601String(),
            'created_at' => $suppression->created_at?->toIso8601String(),
        ];
    }

    private static function detail(Suppression $suppression): ?string
    {
        if ($suppression->detail !== null) {
            return mb_substr((string) $suppression->detail, 0, 160);
        }

        return SuppressionReason::legacyDetail((string) $suppression->reason);
    }
}
