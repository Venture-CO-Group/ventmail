<?php

declare(strict_types=1);

namespace App\Modules\Messages\Data;

use App\Modules\Sending\Data\OutboundAttachment;
use InvalidArgumentException;
use Normalizer;

/**
 * A `/v1/messages` `attachments` mezőjének értelmezése és ellenőrzése (H123).
 *
 * Alak: `[{filename, content_type?, content (base64)}]`. Minden hiba
 * `InvalidArgumentException('KÓD|üzenet')` — a kontroller ebből strukturált 400-at ad.
 *
 * A SORREND számít, és mindegyik lépés egy támadási osztályt zár:
 *
 * 1. darabszám — a ciklus előtt;
 * 2. méret a DEKÓDOLÁS ELŐTT, a base64 hosszából becsülve — egy 15 MB-os sztring
 *    dekódolása memóriát foglal, akkor is, ha utána elutasítjuk;
 * 3. szigorú base64-dekódolás;
 * 4. pontos méret (darabonként, összesen, × címzett);
 * 5. típus: a deklarált (normalizált) típus az allowlisten, ÉS a tartalom mágikus bájtjai
 *    ugyanazt mondják — a `content_type` a hívó állítása, a bájtok a tény;
 * 6. fájlnév-tisztítás: útvonal, vezérlő- és irányjelző karakterek, hossz, kiterjesztés.
 */
final class AttachmentParser
{
    /**
     * A KATALÓGUS: kanonikus típus → elfogadott kiterjesztések (az első a kanonikus). Ami
     * ténylegesen engedett, azt a `sending.attachments.allowed_types` szűkíti (`enabled()`).
     */
    public const ALLOWED = [
        'image/jpeg' => ['jpg', 'jpeg', 'jpe'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
    ];

    /** Elterjedt, nem kanonikus nevek. */
    private const ALIASES = [
        'image/jpg' => 'image/jpeg',
        'image/pjpeg' => 'image/jpeg',
        'application/x-pdf' => 'application/pdf',
    ];

    private const MAX_FILENAME_CHARS = 100;

    /**
     * @return list<OutboundAttachment>
     *
     * @throws InvalidArgumentException
     */
    public static function parse(mixed $input, int $recipientCount): array
    {
        if ($input === null || $input === []) {
            return [];
        }

        if (! is_array($input) || ! array_is_list($input)) {
            throw new InvalidArgumentException(
                'INVALID_ATTACHMENT|Az `attachments` lista legyen: [{filename, content_type, content}].'
            );
        }

        $maxCount = (int) config('sending.attachments.max_count');

        if (count($input) > $maxCount) {
            throw new InvalidArgumentException(sprintf(
                'TOO_MANY_ATTACHMENTS|Üzenetenként legfeljebb %d csatolmány adható meg (%d érkezett).',
                $maxCount,
                count($input)
            ));
        }

        $maxFile = (int) config('sending.attachments.max_file_bytes');
        $maxTotal = (int) config('sending.attachments.max_total_bytes');

        // 2. A BECSLÉS a dekódolás előtt: a base64 4 karaktere 3 bájt. A whitespace-t nem
        // számoljuk le (az még egy teljes másolás lenne) — a becslés felülről korlátos, a
        // pontos ellenőrzés a dekódolás után jön.
        $estimated = 0;

        foreach ($input as $index => $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException(sprintf(
                    'INVALID_ATTACHMENT|A(z) %d. csatolmány objektum legyen: {filename, content_type, content}.',
                    $index + 1
                ));
            }

            $content = $item['content'] ?? null;

            if (! is_string($content) || $content === '') {
                throw new InvalidArgumentException(sprintf(
                    'INVALID_ATTACHMENT|A(z) %d. csatolmány `content` mezője kötelező (base64 sztring).',
                    $index + 1
                ));
            }

            $size = intdiv(strlen($content), 4) * 3;

            // Toleráns a sortörésekre: 76 karakteres soronként CRLF ≈ 2,6% ráhagyás.
            if ($size > (int) ceil($maxFile * 1.03) + 3) {
                throw self::tooLarge($index, $size, $maxFile);
            }

            $estimated += $size;
        }

        if ($estimated > (int) ceil($maxTotal * 1.03) + 3 * count($input)) {
            throw self::totalTooLarge($estimated, $maxTotal);
        }

        $attachments = [];
        $total = 0;

        foreach ($input as $index => $item) {
            // 3. SZIGORÚ dekódolás: a nem-base64 karakter hiba, nem csendes eldobás. A
            // MIME-stílusú sortörést (és a szóközt) előtte kivesszük — a strict mód azt is
            // elutasítaná, pedig a legtöbb kliens-könyvtár így kódol.
            $bytes = base64_decode((string) preg_replace('/[\r\n\t ]+/', '', (string) $item['content']), true);

            if ($bytes === false || $bytes === '') {
                throw new InvalidArgumentException(sprintf(
                    'INVALID_ATTACHMENT|A(z) %d. csatolmány `content` mezője nem érvényes base64.',
                    $index + 1
                ));
            }

            // 4. Pontos méret.
            if (strlen($bytes) > $maxFile) {
                throw self::tooLarge($index, strlen($bytes), $maxFile);
            }

            $total += strlen($bytes);

            if ($total > $maxTotal) {
                throw self::totalTooLarge($total, $maxTotal);
            }

            // 5. Típus: deklarált ∩ szippantott.
            $sniffed = self::sniff($bytes);
            $declared = self::declaredType($item['content_type'] ?? null, $index);

            $enabled = self::enabled();

            if ($declared !== null && ! in_array($declared, $enabled, true)) {
                throw self::unsupported($index, $declared);
            }

            // Ismeretlen tartalom DEKLARÁLT, engedett típussal = hamisítás (pl. HTML
            // `image/jpeg`-nek álcázva); deklaráció nélkül egyszerűen nem engedett típus.
            if ($sniffed === null && $declared === null) {
                throw self::unsupported($index, 'ismeretlen tartalom');
            }

            // Deklaráció nélkül a SZIPPANTOTT típusnak is engedettnek kell lennie (egy
            // típus nélkül küldött PDF ne kerülje meg a kikapcsolt PDF-et).
            if ($declared === null && ! in_array($sniffed, $enabled, true)) {
                throw self::unsupported($index, (string) $sniffed);
            }

            if ($sniffed === null || ($declared !== null && $declared !== $sniffed)) {
                throw new InvalidArgumentException(sprintf(
                    'ATTACHMENT_TYPE_MISMATCH|A(z) %d. csatolmány deklarált típusa %s, a tartalma viszont %s.',
                    $index + 1,
                    $declared,
                    $sniffed
                ));
            }

            // 6. Fájlnév.
            $attachments[] = new OutboundAttachment(
                filename: self::filename($item['filename'] ?? null, $sniffed, $index),
                contentType: $sniffed,
                content: $bytes,
            );
        }

        // A MIME címzettenként épül (és címzettenként kerül a driver elé): a csatolmány
        // bájtja annyiszor él a memóriában, ahány címzett van. Ezer címzett × 10 MB egy
        // worker-memória-DoS lenne, egyetlen kéréssel.
        $maxBatch = (int) config('sending.attachments.max_batch_bytes');

        if ($total * max(1, $recipientCount) > $maxBatch) {
            throw new InvalidArgumentException(sprintf(
                'ATTACHMENT_TOO_LARGE|A csatolmányok mérete × a címzettek száma (%d × %d bájt) '
                .'meghaladja a %d bájtos korlátot. Csatolmánnyal kevesebb címzettre küldj egy hívásban.',
                max(1, $recipientCount),
                $total,
                $maxBatch
            ));
        }

        return $attachments;
    }

    /**
     * Az engedett típusok: a konfiguráció ∩ a katalógus.
     *
     * @return list<string>
     */
    public static function enabled(): array
    {
        return array_values(array_intersect(
            array_keys(self::ALLOWED),
            array_map('strtolower', (array) config('sending.attachments.allowed_types', []))
        ));
    }

    /**
     * A tartalom típusa a MÁGIKUS BÁJTOK alapján — csak az allowlist típusaira.
     *
     * Saját, szűk felismerő (nem `finfo`): a libmagic adatbázisa platformonként más, és egy
     * HTML-poliglott `text/plain`-t vagy `image/jpeg`-et is adhat a tartalom elejétől függően.
     * Itt csak az számít, hogy a bájtsor a deklarált formátum fejlécével kezdődik-e.
     */
    public static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            default => null,
        };
    }

    private static function declaredType(mixed $value, int $index): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'INVALID_ATTACHMENT|A(z) %d. csatolmány `content_type` mezője egyetlen sztring.',
                $index + 1
            ));
        }

        // `Image/JPEG; name="x"` → `image/jpeg`: a paraméter a hívóé, a MIME-ot mi írjuk.
        $type = strtolower(trim(explode(';', $value, 2)[0]));

        return self::ALIASES[$type] ?? $type;
    }

    /**
     * A KIMENŐ fájlnév — tisztítva.
     *
     * - csak az utolsó útvonal-elem (`../../x`, `C:\x`);
     * - vezérlő-, formázó- (pl. U+202E jobbról-balra felülírás: `kep‮gpj.exe`) és a
     *   fájlrendszerekben tiltott karakterek (`"<>:|?*`) ki — a CRLF különösen: a név a
     *   `Content-Disposition` fejlécbe kerül;
     * - legfeljebb 100 karakter, a kiterjesztés megtartásával;
     * - a kiterjesztés a TARTALOMHOZ illik: ha nem, a kanonikus kiterjesztés hozzáfűződik
     *   (`szamla.exe` + JPEG → `szamla.exe.jpg`). Nem cseréljük: a hívó neve olvasható marad,
     *   de a fogadó a valódi típus szerint nyitja meg.
     */
    private static function filename(mixed $value, string $type, int $index): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf(
                'INVALID_ATTACHMENT|A(z) %d. csatolmány `filename` mezője kötelező sztring.',
                $index + 1
            ));
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException(sprintf(
                'INVALID_ATTACHMENT|A(z) %d. csatolmány fájlneve nem érvényes UTF-8.',
                $index + 1
            ));
        }

        $name = class_exists(Normalizer::class) ? (string) Normalizer::normalize($value, Normalizer::FORM_C) : $value;

        // A vezérlő- és a formázó-karakter (Cc, Cf: NUL, CRLF, U+202E, U+200B…) ELŐBB megy
        // ki, mint az útvonal-vágás: a `kep\r\nBcc: x` sortörése se maradjon a névben.
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name);
        $name = (string) preg_replace('#^.*[/\\\\]#u', '', $name);
        $name = (string) preg_replace('/["<>:|?*]/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");

        $extensions = self::ALLOWED[$type];
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($name === '') {
            $name = 'attachment';
            $extension = '';
        }

        if (! in_array($extension, $extensions, true)) {
            $extension = $extensions[0];
            $name .= '.'.$extension;
        }

        if (mb_strlen($name) > self::MAX_FILENAME_CHARS) {
            $suffix = '.'.mb_substr($name, -mb_strlen($extension));
            $name = rtrim(mb_substr($name, 0, self::MAX_FILENAME_CHARS - mb_strlen($suffix)), ' .').$suffix;
        }

        return $name;
    }

    private static function tooLarge(int $index, int $size, int $max): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'ATTACHMENT_TOO_LARGE|A(z) %d. csatolmány mérete (~%d bájt) meghaladja a darabonkénti %d bájtos korlátot.',
            $index + 1,
            $size,
            $max
        ));
    }

    private static function totalTooLarge(int $size, int $max): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'ATTACHMENT_TOO_LARGE|A csatolmányok együttes mérete (~%d bájt) meghaladja az üzenetenkénti %d bájtos korlátot.',
            $size,
            $max
        ));
    }

    private static function unsupported(int $index, string $type): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'UNSUPPORTED_ATTACHMENT_TYPE|A(z) %d. csatolmány típusa (%s) nem engedett. Engedett: %s.',
            $index + 1,
            $type,
            implode(', ', self::enabled()) ?: '—'
        ));
    }
}
