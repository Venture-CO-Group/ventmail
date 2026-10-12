<?php

declare(strict_types=1);

namespace App\Modules\Template\Providers;

use App\Modules\Support\ModuleServiceProvider;
use App\Modules\Template\Contracts\AssetStore;
use App\Modules\Template\Services\Assets\LocalPublicAssetStore;
use App\Modules\Template\Services\Assets\NullAssetStore;

/**
 * Template modul — Studio block-tree (közös JSON Schema) → MJML → reszponzív HTML + plaintext.
 */
final class TemplateServiceProvider extends ModuleServiceProvider
{
    /**
     * A „megnyitás böngészőben" nézet (`/w/{token}`) a LEVÉL OLVASÓJÁÉ, nem a bejelentkezett
     * felhasználóé — ezért a modul route-jai hitelesítés előttiek, és a sablon-felület a
     * `routes/web.php`-ban EXPLICIT `auth` + `workspace` csoportban áll (ugyanaz a minta,
     * mint a Suppression modul leiratkozó végpontjánál).
     */
    protected bool $routesPrecedeAuthentication = true;

    public function register(): void
    {
        /*
         | A KÉPTÁR a konfigurációból (D10). A ventVault driver itt kerül majd be — a hívók
         | (feltöltő, szerkesztő) az interfészt kérik, a cseréről nem tudnak.
         | TODO ventVault: 'ventvault' => VentVaultAssetStore (HUMAN-QUEUE).
         */
        $this->app->bind(AssetStore::class, static fn () => match (config('template.assets.driver')) {
            'null' => new NullAssetStore,
            default => new LocalPublicAssetStore,
        });
    }
}
