<?php

declare(strict_types=1);

namespace App\Modules\Validation\Services;

use App\Modules\Sending\Contracts\AddressRiskScorer;
use App\Modules\Validation\Data\ValidationVerdict;
use Illuminate\Support\Facades\Cache;

/**
 * A PreSendGate 6. kapujának pontszáma — a VALÓDI láncból (42. kritérium).
 *
 * Ez váltja le a `FakeAddressRiskScorer`-t. A különbség nem csak az implementációban van: a
 * kapu ezentúl VALÓDI hálózati műveletet is végezhet (MX-lekérdezés, szonda) a küldési úton,
 * és ez a küldés LATENCIÁJA.
 *
 * Ezért a gate-úton a szonda KI VAN KAPCSOLVA: a küldés előtti pillanat nem a szondázás
 * helye. Ami olcsón eldönthető (szintaxis, MX, szerepcím, eldobható domain, elgépelés), az
 * itt is lefut; a szonda a `POST /v1/validate` és a bulk dolga, ahol az ügyfél TUDJA, hogy
 * ellenőrzést kért, és megvárja.
 *
 * A pontszám cache-elt: egy 1000 címzettes batch különben 1000 DNS-lekérdezést jelentene a
 * küldési kérésen BELÜL.
 */
final class ValidationRiskScorer implements AddressRiskScorer
{
    public function __construct(private readonly AddressValidator $validator) {}

    public function riskFor(string $email): float
    {
        $key = 'validation:risk:'.hash('sha256', strtolower(trim($email)));

        $cached = Cache::get($key);

        if (is_float($cached)) {
            return $cached;
        }

        // A SZONDA kikapcsolva — PARAMÉTERREL, nem globális konfig-átállítással. Az utóbbi
        // egy megosztott állapotot írt át a kérés közben; itt a hívás mondja meg, mit akar.
        $verdict = $this->validator->validate($email, withProbe: false);

        // A BIZONYTALAN eredményt RÖVIDEN tartjuk. Egy DNS-kimaradás alatt számolt kockázat
        // különben egy órával TÚLÉLNÉ a kiváltó okot: a DNS már rég visszaállt, mi meg még
        // mindig elutasítanánk.
        $ttl = $verdict->classification === ValidationVerdict::UNKNOWN ? 60 : 3600;

        Cache::put($key, $verdict->risk, $ttl);

        return $verdict->risk;
    }
}
