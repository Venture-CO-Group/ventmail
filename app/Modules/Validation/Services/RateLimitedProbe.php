<?php

declare(strict_types=1);

namespace App\Modules\Validation\Services;

use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Contracts\DnsUnavailable;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Services\WorkspaceContext;
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
    public function __construct(
        private readonly MailboxProbe $inner,
        private readonly DnsResolver $dns,
        private readonly WorkspaceContext $context,
    ) {}

    public function probe(string $email): ProbeResult
    {
        $email = strtolower(trim($email));
        $domain = DomainNormalizer::ofEmail($email);

        // KULCSOLT hash, nem nyers SHA-256: a cache-kulcs a címről készül, és egy
        // címszótár a nyers hasht másodpercek alatt visszafejti. Ugyanaz a primitív, amit a
        // suppression- és a törlési lista is használ.
        $key = 'validation:probe:'.KeyedHash::of('validation', $email);

        $cached = Cache::get($key);

        if ($cached instanceof ProbeResult) {
            return $cached;
        }

        foreach ($this->limiters($domain) as $limiter => $max) {
            if (RateLimiter::tooManyAttempts($limiter, $max)) {
                // Nem hiba, hanem TUDATOS lemondás a pontosságról.
                return ProbeResult::unknown('a szonda-keret kimerült');
            }
        }

        foreach (array_keys($this->limiters($domain)) as $limiter) {
            RateLimiter::hit($limiter, 60);
        }

        $result = $this->inner->probe($email);

        // A BIZONYTALAN eredményt RÖVIDEBB ideig tartjuk: a greylisting öt perc múlva már
        // mást mondhat, és egy napig „unknown"-nak könyvelni egy jó címet kár lenne.
        //
        // A RÉSZLETET nem tesszük el: a fogadó nyers válasza rendszerint visszaidézi a
        // címet (`550 5.1.1 <anna@pelda.hu> Recipient address rejected`), és a cache sem a
        // retenciós, sem a törlési úton nem érhető el.
        Cache::put(
            $key,
            $result->withoutDetail(),
            $result->status === 'unknown' ? 300 : (int) config('validation.probe.cache_seconds'),
        );

        return $result;
    }

    /**
     * A FÉK vödrei — három, mert három különböző dolgot védenek.
     *
     * @return array<string, int> vödör → percenkénti keret
     */
    private function limiters(string $domain): array
    {
        $limiters = [];

        // 1. A FOGADÓ MX-HOSZTJA. A domain önmagában megkerülhető: mérve, négy aldomain ×
        //    öt cím = HÚSZ valódi szonda ugyanarra az `mx.aldozat.hu`-ra, wildcard DNS-sel
        //    korlátlanul. A fogadó a hosztot látja, nem a domaint — a keretnek is azt kell.
        $limiters['validation-probe:mx:'.$this->mailExchanger($domain)] = (int) config('validation.probe.per_domain_per_minute');

        // 2. A DOMAIN: a régi vödör megmarad, mert egy MX több domaint is kiszolgál.
        $limiters['validation-probe:domain:'.$domain] = (int) config('validation.probe.per_domain_per_minute');

        // 3. A TENANT. Enélkül az egyik ügyfél elhasználja a másik keretét: mérve, „A"
        //    tenant öt kérése után „B" első, FIZETETT kérése `unknown`-t kap. A globális
        //    keret közös erőforrás — a tenantonkénti a saját fogyasztást méri.
        if ($this->context->hasWorkspace()) {
            $limiters['validation-probe:tenant:'.$this->context->workspaceId().':'.$domain]
                = (int) config('validation.probe.per_tenant_per_minute');
        }

        return $limiters;
    }

    /** A fogadó elsődleges MX-hosztja — a keret VALÓDI címzettje. */
    private function mailExchanger(string $domain): string
    {
        try {
            $records = $this->dns->records($domain, 'MX');
        } catch (DnsUnavailable) {
            return $domain;
        }

        $best = null;
        $bestPriority = PHP_INT_MAX;

        foreach ($records as $record) {
            $priority = preg_match('/^\s*(\d+)\s+/', (string) $record, $match) === 1 ? (int) $match[1] : 50;

            if ($priority < $bestPriority) {
                $bestPriority = $priority;
                $best = strtolower(trim((string) preg_replace('/^\s*\d+\s+/', '', (string) $record), '. '));
            }
        }

        return $best ?? $domain;
    }
}
