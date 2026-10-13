<?php

declare(strict_types=1);

namespace App\Modules\Template\Http\Controllers;

use App\Modules\Template\Contracts\AssetStore;
use App\Modules\Template\Models\SavedBlock;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Models\TemplateAsset;
use App\Modules\Template\Models\TemplateVersion;
use App\Modules\Template\Services\Assets\AssetUploader;
use App\Modules\Template\Services\TemplateUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function destroy(string $uuid, AssetStore $store, TemplateUsage $usage): JsonResponse
    {
        Gate::authorize('template.create');

        $asset = TemplateAsset::query()->where('uuid', $uuid)->firstOrFail();

        // AZ AKTÍV SABLON által (közvetve is) hivatkozott kép törlése a kiküldendő levelet változtatja
        // (review N-d → r5 N3): ugyanaz a publikálási kapu, mint a sablon írásánál — a zárak alatt.
        $denial = DB::transaction(function () use ($asset, $usage): ?string {
            $locked = TemplateAsset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();
            $needle = '%'.addcslashes((string) $locked->url, '%_\\').'%';
            $refers = static fn ($query) => $query->whereRaw('blocks::text like ?', [$needle])
                ->orWhereRaw('coalesce(styles::text, \'\') like ?', [$needle]);

            // A hivatkozó sablonok: a mostani tartalom ÉS a korábbi verziók (egy visszaállítás a képet
            // visszahozná). A global scope a tenant-határ.
            //
            // A LISTA a sablon-zárak ELŐTT olvasódik (review r6 N1, dokumentált): a zárolandó sorokat csak
            // így ismerjük. Ami a zár alatt dönt, az a használat (`writeDenial()` — aktív kampány /
            // journey), tehát egy közben ÉLESÍTETT hivatkozó sablon nem csúszik át. Egy sablon, amely a
            // lista után KEZDI hivatkozni a képet, nincs a listán — a mentés a kép létezését nem ellenőrzi;
            // ez a maradék ablak a kép-hivatkozás természetéből (URL a blokkfában) adódik, és a küldés
            // legfeljebb egy törött képet hozhat, jóváhagyatlan tartalmat nem.
            $ids = Template::query()->where($refers)->pluck('id')
                ->merge(TemplateVersion::query()->where($refers)->pluck('template_id'))
                ->unique()->sort()->values()->all();

            // A sablon-sorok zárja (FOR UPDATE, id-sorrendben): az élesítés (FOR SHARE) addig vár, és a
            // `writeDenial()` a zár alatt látott aktív használatot nézi.
            $templates = $ids === [] ? collect() : Template::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            foreach ($templates as $template) {
                if (($denial = $usage->writeDenial($template)) !== null) {
                    return $denial;
                }
            }

            // A MENTETT BLOKK a workspace közös tartalma: a hivatkozott képe csak publikálási joggal törölhető.
            if (Gate::denies('template.publish')
                && SavedBlock::query()->whereRaw('block::text like ?', [$needle])->exists()) {
                return __('studio.templates.assetInSavedBlock');
            }

            $locked->delete();

            return null;
        });

        if ($denial !== null) {
            return response()->json(['message' => $denial], 403);
        }

        // A tárhely-fájl a COMMIT UTÁN törlődik (a sor törlése visszagörgethető, a fájlé nem).
        $store->delete((string) $asset->path);

        return response()->json(['deleted' => true]);
    }
}
