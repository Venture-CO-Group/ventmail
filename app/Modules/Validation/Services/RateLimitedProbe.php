<?php

declare(strict_types=1);

namespace App\Modules\Validation\Services;

use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Validation\Contracts\MailboxProbe;
use App\Modules\Validation\Data\ProbeResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A SZONDA elé tett fék: cache + per-domain keret (40. kritérium).
 *
 * Minden szonda egy IDEGEN szerverhez intézett SMTP-kérés a MI IP-nkről. Száz cím
 * ellenőrzése ugyanazon a domainen száz szondát jelentene — a fogadó ezt (joggal)
 * címfelderítésnek veszi, és blokkolja az IP-nket. A blokkolt IP-ről pedig nem csak
 * ellenőrizni nem tudunk: KÜLDENI sem.
 *
 * KÉT fék, mert külön dolgot védenek:
 *  - a CACHE az ISMÉTLŐDÉST fogja (ugyanaz a cím többször) — a postafiókok léte lassan
 *    változik, a napos cache nagyságrenddel csökkenti a forgalmat;
 *  - a KERET a SZÉLESSÉGET (sok KÜLÖNBÖZŐ cím ugyanazon a domainen) — ezt a cache nem fogja,
 *    és épp ez néz ki címfelderítésnek.
 *
 * A keret túllépése NEM hiba: `unknown` eredmény. Inkább mondjuk azt, hogy „nem tudjuk",
 * mint hogy az IP-nk jó hírét kockáztassuk egy pontosabb válaszért.
 */
final class RateLimitedProbe implements MailboxProbe
{
    public function __construct(private readonly MailboxProbe $inner) {}

    public function probe(string $email): ProbeResult
    {
        $email = strtolower(trim($email));
        $domain = DomainNormalizer::ofEmail($email);

        $key = 'validation:probe:'.hash('sha256', $email);

        $cached = Cache::get($key);

        if ($cached instanceof ProbeResult) {
            return $cached;
        }

        $limiter = 'validation-probe:'.$domain;

        if (RateLimiter::tooManyAttempts($limiter, (int) config('validation.probe.per_domain_per_minute'))) {
            // Nem hiba, hanem TUDATOS lemondás a pontosságról.
            return ProbeResult::unknown('a domain szonda-kerete kimerült');
        }

        RateLimiter::hit($limiter, 60);

        $result = $this->inner->probe($email);

        // A BIZONYTALAN eredményt RÖVIDEBB ideig tartjuk: a greylisting öt perc múlva már
        // mást mondhat, és egy napig „unknown"-nak könyvelni egy jó címet kár lenne.
        Cache::put(
            $key,
            $result,
            $result->status === 'unknown' ? 300 : (int) config('validation.probe.cache_seconds'),
        );

        return $result;
    }
}
