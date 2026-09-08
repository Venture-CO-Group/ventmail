<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Http\Controllers;

use App\Modules\Deliverability\Services\MtaStsPolicy;
use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Services\DomainNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /.well-known/mta-sts.txt` — az MTA-STS policy kiszolgálása (48. kritérium).
 *
 * A szabvány szerint a policy HTTPS-en, az `mta-sts.<domain>` hoszton érhető el, és épp az a
 * lényege, hogy HTTPS-en jön: a TANÚSÍTVÁNY igazolja, hogy tényleg a domain tulajdonosa
 * írta, nem egy útközbeni támadó. A DNS-rekord csak a policy LÉTEZÉSÉT és a verzióját jelzi.
 *
 * A HOSZTNÉV a bemenet, és ez a végpont EGYETLEN azonosítója — ezért a hoszthoz tartozó
 * domaint a saját, VERIFIKÁLT domainjeink között keressük. Ha nincs találat, 404: egy
 * tetszőleges hosztra kiszolgált policy azt állítaná, hogy a domain levélforgalmát mi
 * szabályozzuk, pedig nem.
 */
final class MtaStsController
{
    public function __invoke(Request $request, MtaStsPolicy $policy): Response
    {
        $host = strtolower((string) $request->getHost());

        if (! str_starts_with($host, 'mta-sts.')) {
            return $this->notFound();
        }

        $name = DomainNormalizer::canonical(substr($host, strlen('mta-sts.')));

        if ($name === '' || $name === null) {
            return $this->notFound();
        }

        // A `withoutGlobalScopes` SZÁNDÉKOS és dokumentált: a kérést egy IDEGEN MTA indítja,
        // bejelentkezés és tenant-kontextus nélkül — a hosztnév maga mondja meg, melyik
        // domainről van szó. A `limit(2)` őrzi az egyértelműséget: ha ugyanaz a név két
        // workspace-ben is szerepelne, inkább nem szolgálunk ki policy-t, mint hogy a rossz
        // tenant MX-listáját adjuk ki (ugyanaz a minta, mint a `VerifiedInboundResolver`-ben).
        $matches = Domain::query()
            ->withoutGlobalScopes()
            ->where('name', $name)
            ->where('status', 'verified')
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            return $this->notFound();
        }

        $body = $policy->policyFor($matches->first());

        if ($body === null) {
            // Nem tudtuk lekérdezni az MX-eket: a HIÁNYZÓ policy a helyes válasz. Egy üres
            // `mx:` listájú policy `enforce` módban a domain TELJES bejövő forgalmát
            // blokkolná a `max_age` hosszán — lásd `MtaStsPolicy::policyFor()`.
            return response('', 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            // A fogadók a `max_age` szerint cache-elik a policy-t; a HTTP-cache ennél ne
            // legyen hosszabb, különben a visszavonás nem érne el hozzájuk.
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function notFound(): Response
    {
        return response('', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
