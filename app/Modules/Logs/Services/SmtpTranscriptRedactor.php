<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

/**
 * Az SMTP-párbeszéd sorainak REDAKCIÓJA, mielőtt naplóba kerülnek (24. kritérium).
 *
 * Az `AUTH PLAIN dXNlcgB1c2VyAHRpdGtvcw==` sorban a base64 MAGA a jelszó — nem elrejtve,
 * csak átkódolva. Ha a nyers párbeszédet naplóznánk, a kimenő relay jelszava ott ülne a
 * `smtp_transactions` táblában, ahonnan a support-felület ki is írja a képernyőre.
 *
 * Ugyanez igaz az `AUTH LOGIN` KÖVETKEZŐ sorára: ott a felhasználónév és a jelszó külön
 * sorban, base64-ben érkezik, parancsszó nélkül. Ezért a redakció ÁLLAPOTOT tart: az
 * `AUTH LOGIN` után jövő két kliens-sor akkor is kiesik, ha önmagában ártatlan base64-nek
 * látszik.
 *
 * A parancs NEVE marad — a support épp azt akarja látni, hogy a hitelesítés megtörtént-e.
 */
final class SmtpTranscriptRedactor
{
    private const REDACTED = '<redacted>';

    /**
     * @param  list<array{direction: string, line: string}>  $lines
     * @return list<array{direction: string, line: string}>
     */
    public function redact(array $lines): array
    {
        $expectCredentials = 0;
        $out = [];

        foreach ($lines as $entry) {
            $direction = (string) ($entry['direction'] ?? 'out');
            $line = (string) ($entry['line'] ?? '');

            if ($direction === 'out' && $expectCredentials > 0) {
                $expectCredentials--;
                $out[] = ['direction' => $direction, 'line' => self::REDACTED];

                continue;
            }

            $upper = strtoupper(ltrim($line));

            if ($direction === 'out' && str_starts_with($upper, 'AUTH ')) {
                $keyword = explode(' ', trim($line))[1] ?? '';

                // LOGIN: a felhasználónév és a jelszó KÜLÖN sorban jön utána.
                $expectCredentials = strtoupper($keyword) === 'LOGIN' ? 2 : 0;

                $out[] = ['direction' => $direction, 'line' => 'AUTH '.strtoupper($keyword).' '.self::REDACTED];

                continue;
            }

            $out[] = ['direction' => $direction, 'line' => $line];
        }

        return $out;
    }
}
