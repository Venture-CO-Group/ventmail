<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\StoredTemplates\Models\StoredTemplateVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/v1/templates` — tárolt sablonok API-ja (spec 15.).
 */
final class StoredTemplateController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'items' => StoredTemplate::query()->with('versions')->get()->map(fn (StoredTemplate $template) => [
                'name' => $template->name,
                'description' => $template->description,
                'versions' => $template->versions->pluck('version'),
                'active_version' => $template->activeVersion()?->version,
            ]),
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

        if ($activate) {
            // Egyszerre egyetlen aktív verzió lehet — különben a küldés nem determinisztikus.
            $template->versions()->where('is_active', true)->update(['is_active' => false]);
        }

        $version = StoredTemplateVersion::create([
            'stored_template_id' => $template->getKey(),
            'version' => $template->nextVersionNumber(),
            'body' => $validated['template'],
            'body_text' => $validated['text'] ?? null,
            'is_active' => $activate,
        ]);

        return response()->json([
            'version' => ['version' => $version->version, 'active' => $version->is_active],
        ], 201);
    }
}
