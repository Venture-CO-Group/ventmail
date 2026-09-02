<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Middleware;

use App\Modules\Identity\Models\ApiKey;
use App\Modules\Sending\Enums\Channel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A csatorna a KULCSBÓL jön, nem a kérés törzséből.
 *
 * Miért: a `channel` korábban a hívó önbevallása volt. Bárki, akinek `messages:send`
 * kulcsa volt, marketing tartalmat küldhetett `transactional` címkével — ekkor a
 * ConsentGate azonnal átengedte, és a SuppressionGate a marketing-leiratkozásokat és
 * panaszokat NEM IS NÉZTE. Ez a golden rule 4 („transactional ≠ marketing", keverés =
 * instant blokk) megkerülése volt.
 *
 * Mostantól a consent-köteles csatorna külön scope-ot igényel (`messages:send:marketing`),
 * és a `journey`/`campaign` a publikus API-ról egyáltalán nem érhető el — azok a
 * Journey/Campaign motor belső csatornái (sor-prioritás!).
 */
final class AuthorizeChannel
{
    /** A publikus API-ról egyáltalán nem választható csatornák. */
    private const INTERNAL_ONLY = [Channel::Journey, Channel::Campaign];

    public function handle(Request $request, Closure $next): Response
    {
        $requested = strtolower(trim((string) $request->input('o:channel', Channel::Transactional->value)));

        $channel = Channel::tryFrom($requested);

        // Az ismeretlen értéket a payload-parser fordítja 400-ra; itt csak a jogosultság.
        if ($channel === null) {
            return $next($request);
        }

        if (in_array($channel, self::INTERNAL_ONLY, true)) {
            return $this->forbidden(
                "A(z) {$channel->value} csatorna a Journey/Campaign motor belső csatornája, "
                .'a publikus API-ról nem választható (sor-prioritás).'
            );
        }

        if ($channel->requiresConsent()) {
            $key = $request->attributes->get('api_key');

            if (! $key instanceof ApiKey || ! $key->hasScope('messages:send:marketing')) {
                return $this->forbidden(
                    'Consent-köteles csatornához `messages:send:marketing` scope kell. '
                    .'Marketing küldéshez megerősített (double opt-in) consent is szükséges.'
                );
            }
        }

        return $next($request);
    }

    private function forbidden(string $message): Response
    {
        return response()->json([
            'error' => ['code' => 'CHANNEL_NOT_ALLOWED', 'message' => $message],
        ], 403);
    }
}
