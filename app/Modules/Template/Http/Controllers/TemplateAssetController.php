<?php

declare(strict_types=1);

namespace App\Modules\Template\Http\Controllers;

use App\Modules\Template\Contracts\AssetStore;
use App\Modules\Template\Models\TemplateAsset;
use App\Modules\Template\Services\Assets\AssetUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * KÉPFELTÖLTÉS a szerkesztőből (D10). Az ellenőrzés az `AssetUploader`-é, a tárolás az
 * `AssetStore`-é (ma a `public` diszk, később a ventVault — HUMAN-QUEUE).
 */
final class TemplateAssetController
{
    public function index(): JsonResponse
    {
        Gate::authorize('template.create');

        return response()->json(['assets' => TemplateUiController::assets()]);
    }

    public function store(Request $request, AssetUploader $uploader): JsonResponse
    {
        Gate::authorize('template.create');

        $request->validate(['file' => ['required', 'file']]);

        $asset = $uploader->upload($request->file('file'));

        return response()->json([
            'uuid' => (string) $asset->uuid,
            'url' => (string) $asset->url,
            'name' => (string) $asset->original_name,
            'width' => (int) $asset->width,
            'height' => (int) $asset->height,
            'bytes' => (int) $asset->size,
        ], 201);
    }

    public function destroy(string $uuid, AssetStore $store): JsonResponse
    {
        Gate::authorize('template.create');

        $asset = TemplateAsset::query()->where('uuid', $uuid)->firstOrFail();

        $store->delete((string) $asset->path);
        $asset->delete();

        return response()->json(['deleted' => true]);
    }
}
