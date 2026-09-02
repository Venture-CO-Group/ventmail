<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\StoredTemplates\Models\StoredTemplateVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/v1/templates` — tárolt sablonok API-ja (spec 15.).
 */
final class StoredTemplateController
{
    public function index(Request $request): JsonResponse
    {
        // Lapozás KÖTELEZŐ, és a verzió-relációból CSAK a metaadat: a `body` verziónként
        // 1 MB is lehet, és a válasz csak a verziószámokat adja vissza — a teljes törzsek
        // betöltése memóriára futna egy publikus GET-en.
        $templates = StoredTemplate::query()
            ->with(['versions' => fn ($query) => $query->select('id', 'stored_template_id', 'version', 'is_active')])
            ->orderBy('name')
            ->cursorPaginate(min((int) $request->integer('limit', 50), 200));

        return response()->json([
            'items' => $templates->getCollection()->map(fn (StoredTemplate $template) => [
                'name' => $template->name,
                'description' => $template->description,
                'versions' => $template->versions->pluck('version'),
                'active_version' => $template->versions->firstWhere('is_active', true)?->version,
            ]),
            'next_cursor' => $templates->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9][a-z0-9._-]*$/i'],
            'description' => ['nullable', 'string', 'max:255'],
            'template' => ['required', 'string', 'max:1048576'],
            'text' => ['nullable', 'string', 'max:1048576'],
        ]);

        if (StoredTemplate::query()->where('name', $validated['name'])->exists()) {
            return ApiError::badRequest('TEMPLATE_EXISTS', "Ilyen nevű sablon már van: {$validated['name']}");
        }

        $template = StoredTemplate::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        $version = StoredTemplateVersion::create([
            'stored_template_id' => $template->getKey(),
            'version' => 1,
            'body' => $validated['template'],
            'body_text' => $validated['text'] ?? null,
            // Az első verzió AZONNAL aktív: különben a `template=` küldés némán elbukna.
            'is_active' => true,
        ]);

        return response()->json([
            'template' => [
                'name' => $template->name,
                'version' => $version->version,
                'active' => true,
            ],
        ], 201);
    }

    public function storeVersion(Request $request, string $name): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['required', 'string', 'max:1048576'],
            'text' => ['nullable', 'string', 'max:1048576'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $template = StoredTemplate::query()->where('name', $name)->first();

        if ($template === null) {
            return ApiError::badRequest('UNKNOWN_TEMPLATE', "Ismeretlen sablon: {$name}");
        }

        $activate = (bool) ($validated['active'] ?? false);

        try {
            // TRANZAKCIÓ: enélkül a deaktiválás lefutott, az insert viszont unique-ütközésre
            // szállt, és a sablon AKTÍV VERZIÓ NÉLKÜL maradt — onnantól minden `template=`
            // küldés 400-cal bukott, amíg valaki kézzel nem aktivál.
            $version = DB::transaction(function () use ($template, $validated, $activate) {
                $locked = StoredTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();

                if ($activate) {
                    // Modell-példányonként: a mass-update kikerülné az auditot (rule 11),
                    // és utólag nem lenne rekonstruálható, melyik verzió volt élő.
                    $locked->versions()->where('is_active', true)->get()
                        ->each(fn (StoredTemplateVersion $current) => $current->update(['is_active' => false]));
                }

                return StoredTemplateVersion::create([
                    'stored_template_id' => $locked->getKey(),
                    'version' => $locked->nextVersionNumber(),
                    'body' => $validated['template'],
                    'body_text' => $validated['text'] ?? null,
                    'is_active' => $activate,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return ApiError::response(
                'TEMPLATE_VERSION_CONFLICT',
                'Egy párhuzamos kérés épp új verziót hozott létre — próbáld újra.',
                409
            );
        }

        return response()->json([
            'version' => ['version' => $version->version, 'active' => $version->is_active],
        ], 201);
    }
}
