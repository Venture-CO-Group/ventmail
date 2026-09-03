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
 * És nem csak a parancssorra. Az RFC 4954 SASL-párbeszéde TÖBB körös: az `AUTH LOGIN` után
 * két külön sorban jön a felhasználónév és a jelszó, az `AUTH PLAIN` initial-response nélküli
 * alakjánál egy sorban a teljes hitelesítő, a `CRAM-MD5`-nél a kihívásra adott válasz. Ezek
 * mind parancsszó NÉLKÜL, ártatlan base64-ként érkeznek.
 *
 * Ezért a szabály nem „az AUTH LOGIN után két sor", hanem: az `AUTH` után MINDEN kliens-sor
 * redaktálva, amíg a szerver egy VÉGLEGES válasszal (2xx vagy 5xx) le nem zárja a
 * párbeszédet. A `334` folytatás — az nem zár le semmit.
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
        $inAuth = false;
        $out = [];

        foreach ($lines as $entry) {
            $direction = (string) ($entry['direction'] ?? 'out');
            $line = (string) ($entry['line'] ?? '');
            $trimmed = trim($line);

            if ($direction === 'out' && preg_match('/^AUTH\b/i', $trimmed) === 1) {
                $keyword = strtoupper(explode(' ', $trimmed)[1] ?? '');

                $inAuth = true;

                $out[] = ['direction' => $direction, 'line' => trim('AUTH '.$keyword).' '.self::REDACTED];

                continue;
            }

            if ($inAuth && $direction === 'out') {
                // Parancsszó nélküli hitelesítő-sor: base64-nek látszik, de MAGA a jelszó.
                $out[] = ['direction' => $direction, 'line' => self::REDACTED];

                continue;
            }

            if ($inAuth && $direction === 'in' && preg_match('/^[25]\d\d/', $trimmed) === 1) {
                // A szerver LEZÁRTA a párbeszédet (siker vagy végleges hiba). A `334`
                // folytatás nem zár le semmit — ott a következő kliens-sor még hitelesítő.
                $inAuth = false;
            }

            $out[] = ['direction' => $direction, 'line' => $line];
        }

        return $out;
    }
}
