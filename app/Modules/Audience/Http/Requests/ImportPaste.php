<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Requests;

/**
 * A BEILLESZTETT lista értelmezése: soronként, vesszővel, pontosvesszővel vagy szóközzel
 * elválasztott címek; a `Kiss Anna <anna@pelda.hu>` alakból a csúcsos zárójeles cím kerül be.
 *
 * Ami nem cím-szerű (nincs benne `@`), azt IS soronként beviszi — hibás sorként jelenik meg
 * az előnézetben, nem tűnik el csendben.
 */
final class ImportPaste
{
    /** @return list<string> */
    public static function entries(string $text): array
    {
        $entries = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Egy sorban TÖBB `Név <cím>` is lehet: mindegyik csúcsos zárójeles cím kell.
            if (preg_match_all('/<([^<>]+)>/', $line, $matches) > 0) {
                foreach ($matches[1] as $address) {
                    $entries[] = trim($address);
                }

                continue;
            }

            foreach (preg_split('/[\s,;]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $entries[] = trim($token, "\"'");
            }
        }

        return array_values(array_filter($entries, static fn (string $entry): bool => $entry !== ''));
    }
}
