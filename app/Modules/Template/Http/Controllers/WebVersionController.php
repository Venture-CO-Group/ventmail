<?php

declare(strict_types=1);

namespace App\Modules\Template\Http\Controllers;

use App\Modules\Sending\Models\Message;
use App\Modules\StoredTemplates\Services\TemplateRenderer;
use App\Modules\Template\Services\WebVersionTokens;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\Response;

/**
 * A LEVÉL WEBES NÉZETE — „Megnyitás böngészőben" (v1.1 · `{{ browser_url }}`).
 *
 * ## Nem személyre szabott, és ez szándékos
 *
 * A személyre szabott törzs SEHOL nem perzisztál (`SendMessage`: a 90 napos naplóba írt,
 * kirenderelt levél credential-szivárgás lenne). A webes nézet ezért a KANONIKUS törzset
 * mutatja, a merge-tagek az ALAPÉRTÉKÜKKEL, a feltételes blokkok a „nincs adat" ágon. A
 * leiratkozó link itt nem működik — a levélben lévő igen.
 *
 * ## A mi originünkön idegen HTML: a CSP zár
 *
 * A törzs lehet API-n beküldött ügyfél-HTML is. A válasz `default-src 'none'` CSP-vel megy
 * (nincs szkript, nincs űrlap, nincs keretezés), `noindex`-szel és referrer nélkül.
 *
 * Ha a törzs már nincs meg (napló-retenció, tárgy-törlés), 410 — nem egy üres oldal.
 */
final class WebVersionController
{
    private const CSP = "default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; "
        ."font-src https:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    public function show(string $token, WebVersionTokens $tokens, WorkspaceContext $context, TemplateRenderer $renderer): Response
    {
        $claim = $tokens->parse($token);
        $workspace = $claim === null ? null : Workspace::query()->whereKey($claim['workspaceId'])->first();

        if ($claim === null || $workspace === null) {
            return $this->respond('', 404);
        }

        // A TENANT-KONTEXTUS a TOKENBŐL jön (mint a leiratkozásnál): a lekérdezés a token
        // workspace-ére szűkül, és a kérés végén a korábbi állapot áll vissza.
        $previous = $context->hasWorkspace() ? $context->workspace() : null;
        $context->set($workspace);

        try {
            $message = Message::query()->where('uuid', $claim['messageUuid'])->first();
            $body = $message?->options['body_html'] ?? null;

            $html = ! is_string($body) || $body === '' ? null : $renderer->render($body, [
                'workspace' => [
                    'name' => (string) $workspace->name,
                    'address' => (string) (($workspace->settings ?? [])['postal_address'] ?? ''),
                ],
            ]);
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        return $html === null ? $this->respond('', 410) : $this->respond($html, 200);
    }

    private function respond(string $html, int $status): Response
    {
        return response($html, $status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => self::CSP,
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
