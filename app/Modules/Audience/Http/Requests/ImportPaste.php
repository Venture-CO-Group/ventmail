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

            if (preg_match('/<([^<>]+)>/', $line, $match) === 1) {
                $entries[] = trim($match[1]);

                continue;
            }

            foreach (preg_split('/[\s,;]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $entries[] = trim($token, "\"'");
            }
        }

        return array_values(array_filter($entries, static fn (string $entry): bool => $entry !== ''));
    }
}
