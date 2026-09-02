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
 * Mostantól a kérésben megadott csatorna csak azon a halmazon belül mozoghat, amit a KULCS
 * kapott (`api_keys.channels`, alapértelmezés: csak `transactional`), a consent-köteles
 * csatorna ezen felül külön scope-ot igényel (`messages:send:marketing`), a
 * `journey`/`campaign` pedig a publikus API-ról egyáltalán nem érhető el — azok a
 * Journey/Campaign motor belső csatornái (sor-prioritás!).
 *
 * Amit ez NEM old meg, és amit ezért ki kell mondani: azt, hogy valaki MARKETING TARTALMAT
 * küld tranzakciósnak jelölve, egyetlen kapu sem tudja megállapítani a tartalomból. Az a
 * jelölés a küldő felelőssége; a védelem ott a panasz- és bounce-arány figyelése (A6),
 * nem ez a middleware.
 */
final class AuthorizeChannel
{
    /** A publikus API-ról egyáltalán nem választható csatornák. */
    private const INTERNAL_ONLY = [Channel::Journey, Channel::Campaign];

    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->input('o:channel', Channel::Transactional->value);

        // Tömb-bemenet (`o:channel[]=marketing`) korábban „Array to string conversion"
        // warningon át 500-at adott az egységes hibaformátum helyett.
        if (! is_scalar($raw)) {
            return $next($request);
        }

        $requested = strtolower(trim((string) $raw));

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

        $key = $request->attributes->get('api_key');

        // A KULCS a jogosultság forrása. Fail-closed: kulcs nélkül nincs csatorna.
        if (! $key instanceof ApiKey) {
            return $this->forbidden('A csatorna-jogosultság a kulcsból dől el; hitelesítés nélkül nincs küldés.');
        }

        if (! $key->mayUseChannel($channel->value)) {
            return $this->forbidden(sprintf(
                'Ez a kulcs a(z) %s csatorná(k)ra lett kiállítva, a(z) %s nincs köztük. '
                .'A csatorna a kulcs joga, nem a kérés önbevallása.',
                implode(', ', $key->allowedChannels()),
                $channel->value
            ));
        }

        if ($channel->requiresConsent() && ! $key->hasScope('messages:send:marketing')) {
            return $this->forbidden(
                'Consent-köteles csatornához `messages:send:marketing` scope kell. '
                .'Marketing küldéshez megerősített (double opt-in) consent is szükséges.'
            );
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
