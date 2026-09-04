<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\Inbound\Models\InboundRoute;
use App\Modules\Inbound\Services\SafeRegex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Bejövő route-szabályok kezelése (30. kritérium).
 *
 * A KIFEJEZÉST a MENTÉSKOR is ellenőrizzük, nem csak futásidőben: az ügyfél azonnal tudja meg,
 * hogy elgépelte a regexét, ne három nap múlva abból, hogy „nem jön a levél".
 */
final class InboundRouteController
{
    public function __construct(private readonly SafeRegex $regex) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'items' => InboundRoute::query()->orderBy('priority')->orderBy('id')->get()
                ->map(fn (InboundRoute $route) => $this->present($route))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = $this->validator($request->all());

        if ($validator->fails()) {
            return ApiError::response('VALIDATION_ERROR', 'A route-szabály érvénytelen.', 400, $validator->errors()->toArray());
        }

        $error = $this->semanticError($validator->validated());

        if ($error !== null) {
            return ApiError::badRequest('INVALID_ROUTE', $error);
        }

        return response()->json($this->present(InboundRoute::query()->create($validator->validated())), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $route = InboundRoute::query()->where('uuid', $uuid)->first();

        if ($route === null) {
            return ApiError::response('UNKNOWN_ROUTE', 'Ismeretlen route-szabály.', 404);
        }

        $validator = $this->validator($request->all(), partial: true);

        if ($validator->fails()) {
            return ApiError::response('VALIDATION_ERROR', 'A route-szabály érvénytelen.', 400, $validator->errors()->toArray());
        }

        $merged = array_merge($route->only(['match_type', 'match_field', 'expression', 'actions']), $validator->validated());

        $error = $this->semanticError($merged);

        if ($error !== null) {
            return ApiError::badRequest('INVALID_ROUTE', $error);
        }

        $route->fill($validator->validated())->save();

        return response()->json($this->present($route->fresh()));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $route = InboundRoute::query()->where('uuid', $uuid)->first();

        if ($route === null) {
            return ApiError::response('UNKNOWN_ROUTE', 'Ismeretlen route-szabály.', 404);
        }

        $route->delete();

        return response()->json(null, 204);
    }

    /** @param array<string, mixed> $data */
    private function validator(array $data, bool $partial = false): \Illuminate\Validation\Validator
    {
        $required = $partial ? 'sometimes' : 'required';

        return Validator::make($data, [
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'match_type' => [$required, 'string', 'in:'.implode(',', InboundRoute::MATCH_TYPES)],
            'match_field' => ['sometimes', 'nullable', 'string', 'max:120'],
            // A HOSSZ-LIMIT nem kozmetika: egy 100 kB-os regex önmagában DoS.
            'expression' => ['sometimes', 'nullable', 'string', 'max:'.SafeRegex::MAX_PATTERN_BYTES],
            'actions' => [$required, 'array', 'min:1', 'max:10'],
            'actions.*.type' => ['required', 'string', 'in:'.implode(',', InboundRoute::ACTION_TYPES)],
            'actions.*.target' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function semanticError(array $data): ?string
    {
        $matchType = (string) ($data['match_type'] ?? '');
        $expression = (string) ($data['expression'] ?? '');

        if ($matchType !== 'catch_all' && $expression === '') {
            return 'A `'.$matchType.'` szabályhoz kifejezés kell.';
        }

        if ($expression !== '' && ! $this->regex->valid($expression)) {
            return 'A kifejezés nem érvényes reguláris kifejezés.';
        }

        if ($matchType === 'match_header' && (string) ($data['match_field'] ?? '') === '') {
            return 'A `match_header` szabályhoz meg kell adni a fejléc nevét.';
        }

        foreach ((array) ($data['actions'] ?? []) as $action) {
            $type = (string) ($action['type'] ?? '');
            $target = (string) ($action['target'] ?? '');

            if ($type === 'forward' && $target === '') {
                return 'A `forward` akcióhoz cél kell (URL vagy e-mail-cím).';
            }

            // A `http`-vel kezdődő cél URL: az `https` kényszerítése a küldés előtt is
            // megtörténik (SSRF-guard), de a hibát ITT kell jelezni, ahol az ügyfél beírja.
            if ($type === 'forward' && str_starts_with($target, 'http') && ! str_starts_with($target, 'https://')) {
                return 'A `forward` URL-je csak https lehet.';
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function present(InboundRoute $route): array
    {
        return [
            'id' => $route->uuid,
            'description' => $route->description,
            'priority' => $route->priority,
            'match_type' => $route->match_type,
            'match_field' => $route->match_field,
            'expression' => $route->expression,
            'actions' => $route->actions,
            'active' => $route->active,
            'last_matched_at' => $route->last_matched_at?->toIso8601String(),
        ];
    }
}
