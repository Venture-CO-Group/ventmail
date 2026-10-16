<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

/**
 * A RENDERELT tárgy tisztítása — ugyanaz a szabály, mint a kampányé (`SendCampaignChunk::subjectFor`):
 * minden C0/DEL, C1 (köztük a NEL) és a sor-/bekezdés-elválasztó (U+2028/2029) szóköz, a
 * többszörös szóköz egy, a vége 255 karakternél vágva (`messages.subject` varchar(255)).
 * A `HeaderSafetyGate` csak a renderelés ELŐTTI (sablon-)tárgyat látja, ezért a kimenő
 * tárgynak itt kell biztonságossá válnia.
 */
final class SubjectSanitizer
{
    public static function clean(string $subject): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{2028}\x{2029}]+/u', ' ', $subject));

        return mb_substr((string) preg_replace('/ {2,}/', ' ', $clean), 0, 255);
    }
}
