<?php

declare(strict_types=1);

namespace App\Modules\Validation\Services;

/**
 * ELGÉPELÉS-JAVASLAT: `gmial.com` → `gmail.com` (39. kritérium).
 *
 * Ez a modul legnagyobb üzleti értékű funkciója. Egy elgépelt domainű cím a listán ÖRÖKRE
 * bounce-ol: soha nem kézbesíthető, viszont minden kampánynál rontja a mutatókat és a
 * reputációt. Egy kattintással javítható — ha egyáltalán szólunk róla.
 *
 * Levenshtein-távolság, KÜSZÖBBEL. A küszöb nem önkényes: távolság 1–2 az igazi elgépelés
 * (`gmial`, `gmai`, `gnail`), 3 fölött viszont már MÁS domainek is beleférnek, és egy hibás
 * javaslat rosszabb, mint a hallgatás — az ügyfél átírná a JÓ címet rosszra.
 */
final class TypoSuggester
{
    public function suggest(string $domain): ?string
    {
        $domain = strtolower(trim($domain));

        if ($domain === '') {
            return null;
        }

        $candidates = (array) config('validation.common_domains');

        // PONTOS egyezés: nincs mit javasolni.
        if (in_array($domain, $candidates, true)) {
            return null;
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $distance = levenshtein($domain, (string) $candidate);

            // A küszöb a HOSSZTÓL függ: egy négybetűs domainnél a 2 távolság már teljesen
            // más szó, egy tizenkét betűsnél viszont még elgépelés.
            $limit = strlen($domain) <= 6 ? 1 : 2;

            if ($distance <= $limit && $distance < $bestDistance) {
                $best = (string) $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /** A teljes címre: a helyi rész változatlan, a domain javítva. */
    public function suggestFor(string $email): ?string
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return null;
        }

        $suggestion = $this->suggest(substr($email, $at + 1));

        return $suggestion === null ? null : substr($email, 0, $at + 1).$suggestion;
    }
}
