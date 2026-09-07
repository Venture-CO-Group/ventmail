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
 *
 * A TLD-t KÜLÖN kezeljük, és SOSEM javasoljuk átírni. Egy országkód-váltás nem elgépelés,
 * hanem másik ország — mérve, ezek mind „javaslatot" kaptak a naiv változatban:
 * `yahoo.co.jp → yahoo.co.uk`, `t-online.de → t-online.hu` (Németország legnagyobb ISP-je),
 * `freemail.de → freemail.hu`, `protonmail.ch → protonmail.com`, `vipmail.ru → vipmail.hu`.
 * Ezek VALÓDI, működő domainek; a „javaslat" elfogadása után a levél sehova nem menne.
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

        [$name, $tld] = $this->split($domain);

        if ($name === '') {
            return null;
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            [$candidateName, $candidateTld] = $this->split((string) $candidate);

            // AZONOS TLD nélkül nincs javaslat: az országkód-váltás nem elgépelés.
            if ($candidateTld !== $tld) {
                continue;
            }

            $distance = levenshtein($name, $candidateName);

            // A küszöb a HOSSZTÓL függ: egy négybetűs névnél a 2 távolság már teljesen más
            // szó, egy tizenkét betűsnél viszont még elgépelés.
            $limit = strlen($name) <= 4 ? 1 : 2;

            if ($distance > 0 && $distance <= $limit && $distance < $bestDistance) {
                $best = (string) $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * A domain NÉV és TLD részre bontva.
     *
     * A `yahoo.co.uk`-nál a TLD `co.uk` — a kétszintű országkódos végződéseket együtt kell
     * kezelni, különben a `yahoo.co.jp` neve „yahoo.co" lenne, és a `.uk` verzióhoz képest
     * távolság 0.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $domain): array
    {
        $parts = explode('.', $domain);

        if (count($parts) < 2) {
            return ['', ''];
        }

        // Kétszintű végződés (`co.uk`, `com.au`): a második szint rövid és ismert.
        $twoLevel = count($parts) >= 3 && in_array($parts[count($parts) - 2], ['co', 'com', 'net', 'org', 'ac', 'gov'], true);

        $tldLength = $twoLevel ? 2 : 1;

        return [
            implode('.', array_slice($parts, 0, count($parts) - $tldLength)),
            implode('.', array_slice($parts, -$tldLength)),
        ];
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
