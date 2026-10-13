<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Controllers;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Consent\Services\PreferenceTokens;
use App\Modules\Domains\Services\DemoDomain;
use App\Modules\Template\Data\EditorCatalog;
use App\Modules\Template\Models\Template;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * KIZÁRÓLAG fejlesztői / teszt-környezetben (v1.1 · 5. modul): a Studio böngésző-tesztjeinek adata.
 *
 * A Playwright nem tud megerősítő levelet olvasni, ezért a checklist-flow (ami MEGERŐSÍTETT
 * consentet igényel) és a preference center (ami a levélben lévő tokenből nyílik) itt kap
 * kiinduló állapotot: egy futásonként egyedi lista, három kontakt (egy megerősített, egy
 * függő, egy hozzájárulás nélküli), egy valódi postai címes és egy helyőrző-címes marketing
 * sablon, a `pelda.hu` hitelesített domain, és a megerősített kontakt preference-linkje.
 *
 * Ugyanaz a két független kapcsoló védi, mint a dev-belépést (`DevSeedController`): a route
 * regisztrációja környezethez kötött, ÉS a kontroller maga is ellenőriz. Valódi levél nem
 * megy ki: a megerősítés a `ConsentRecorder`-en át, levél nélkül történik.
 */
final class DevStudioSeedController
{
    public function __invoke(
        Request $request,
        ConsentRecorder $consents,
        PreferenceTokens $tokens,
        WorkspaceContext $context,
    ): JsonResponse {
        abort_unless(
            config('identity.dev_login_enabled') && ! app()->isProduction() && app()->environment('local', 'testing'),
            404
        );

        $run = preg_replace('/[^a-z0-9]/', '', strtolower((string) $request->query('run', 'x'))) ?: 'x';
        $run = substr($run, 0, 16);

        DemoDomain::ensure();

        $list = ContactList::firstOrCreate(['name' => "E2E Studio {$run}"]);

        $emails = [
            'confirmed' => "studio-{$run}-ok@pelda.hu",
            'pending' => "studio-{$run}-fugg@pelda.hu",
            'none' => "studio-{$run}-nincs@pelda.hu",
        ];

        foreach ($emails as $kind => $email) {
            $contact = Contact::firstOrCreate(
                ['email' => $email],
                ['status' => $kind === 'confirmed' ? 'subscribed' : 'pending', 'first_name' => ucfirst($kind), 'source' => 'manual'],
            );

            $contact->lists()->syncWithoutDetaching([$list->getKey() => ['status' => 'subscribed', 'subscribed_at' => now()]]);

            if ($kind !== 'none') {
                $consent = $consents->request($email, 'marketing', 'form');

                if ($kind === 'confirmed') {
                    $consents->confirm($consent);
                }
            }
        }

        $workspace = $context->workspace();

        $template = fn (string $name, string $address) => Template::firstOrCreate(['name' => $name], [
            'subject' => 'E2E Studio',
            'marketing' => true,
            'blocks' => [
                ['type' => 'section', 'children' => [['type' => 'column', 'children' => [
                    ['type' => 'text', 'attrs' => ['content' => 'E2E Studio levél.']],
                ]]]],
                ['type' => 'section', 'children' => [['type' => 'column', 'children' => [
                    ['type' => 'footer', 'attrs' => array_merge(EditorCatalog::footerDefaults($workspace), ['address' => $address])],
                ]]]],
            ],
        ]);

        $compliant = $template("E2E Studio sablon {$run}", 'Nádor utca 1., 1051 Budapest');
        $placeholder = $template("E2E helyőrző sablon {$run}", (string) __('editor.defaults.footerAddress'));

        return response()->json([
            'run' => $run,
            'list' => ['uuid' => (string) $list->uuid, 'name' => (string) $list->name],
            'emails' => $emails,
            'templates' => [
                'compliant' => ['uuid' => (string) $compliant->uuid, 'name' => (string) $compliant->name],
                'placeholder' => ['uuid' => (string) $placeholder->uuid, 'name' => (string) $placeholder->name],
            ],
            'preferenceUrl' => '/p/'.$tokens->issue((int) $workspace->getKey(), $emails['confirmed']),
        ]);
    }
}
