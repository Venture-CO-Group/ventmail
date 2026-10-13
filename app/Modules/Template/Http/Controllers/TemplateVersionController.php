<?php

declare(strict_types=1);

namespace App\Modules\Template\Http\Controllers;

use App\Modules\Template\Models\Template;
use App\Modules\Template\Models\TemplateVersion;
use App\Modules\Template\Services\TemplateEditorSaver;
use App\Modules\Template\Services\TemplatePublishLocked;
use App\Modules\Template\Services\TemplateUsage;
use App\Modules\Template\Services\TemplateVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * VERZIÓTÖRTÉNET (v1.1): lista és visszaállítás. A visszaállítás ÚJ verziót ír — a régiek
 * megmaradnak, tehát a visszaállítás maga is visszacsinálható.
 */
final class TemplateVersionController
{
    public function index(string $uuid): JsonResponse
    {
        Gate::authorize('template.create');

        $template = Template::query()->where('uuid', $uuid)->firstOrFail();

        $versions = TemplateVersion::query()
            ->with('author:id,name')
            ->where('template_id', $template->getKey())
            ->orderByDesc('number')
            ->limit(TemplateVersions::MAX_VERSIONS)
            ->get()
            ->map(fn (TemplateVersion $version) => [
                'number' => $version->number,
                'reason' => $version->reason,
                'restoredFrom' => $version->restored_from,
                'author' => $version->author?->name,
                'createdAt' => $version->created_at?->toIso8601String(),
                'blockCount' => count((array) $version->blocks),
            ])->values()->all();

        return response()->json(['versions' => $versions]);
    }

    public function restore(string $uuid, int $number, TemplateVersions $versions, TemplateEditorSaver $saver, TemplateUsage $usage): JsonResponse
    {
        Gate::authorize('template.create');

        $template = Template::query()->where('uuid', $uuid)->firstOrFail();

        // B1 · a visszaállítás is írás: aktív sablonon publikálási jogot kér.
        if (($denial = $usage->writeDenial($template)) !== null) {
            return response()->json(['message' => $denial], 403);
        }
        $version = TemplateVersion::query()
            ->where('template_id', $template->getKey())
            ->where('number', $number)
            ->firstOrFail();

        // A RÉGI verzió is a mai szabályon megy át: egy v1.1 előtti, lábléc nélküli
        // pillanatkép nem állítható vissza marketing sablonra úgy, hogy a szabály csendben
        // sérüljön.
        $saver->assertValid((array) $version->blocks, $version->styles, (bool) $template->marketing);

        try {
            $restored = $versions->restore($template, $version);
        } catch (TemplatePublishLocked $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json([
            'template' => TemplateUiController::payload($template->fresh() ?? $template),
            'version' => $restored->number,
        ]);
    }
}
