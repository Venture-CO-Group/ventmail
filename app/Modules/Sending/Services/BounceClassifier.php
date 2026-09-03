<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

use App\Modules\Sending\Data\BounceClass;

/**
 * SMTP-válasz → bounce-osztály (20. kritérium).
 *
 * Az ENHANCED kód (X.Y.Z) ELŐZI a nyers kódot: az „550 5.2.2 over quota" NEM hard bounce —
 * a postafiók csak tele van, holnap kézbesíthető. Aki ezt hardnak veszi, élő vevőket zár ki.
 */
final class BounceClassifier
{
    public function classify(int $code, string $text, ?string $enhanced = null): BounceClass
    {
        $permanent = $code >= 500;
        $parts = $this->enhancedParts($enhanced);

        // 1. X.2.2 = a postafiók TELE VAN. Ez permanens kód mellett is átmeneti probléma —
        //    ha hardnak vennénk, egy nyaraló ügyfél véglegesen kiesne a listáról.
        if ($parts !== null && $parts['subject'] === '2' && $parts['detail'] === '2') {
            return BounceClass::MailboxFull;
        }

        // 2. A fogadók SZABADSZÖVEGE pontosabb, mint a kód: ugyanaz az 5.7.1 jelenthet
        //    blocklistát, spam-minősítést és DMARC-elutasítást — háromféle teendővel.
        $byText = $this->fromText(strtolower($text), $permanent);

        if ($byText !== null) {
            return $byText;
        }

        // 3. Enhanced kód, szöveg nélkül.
        if ($parts !== null) {
            return match (true) {
                $parts['subject'] === '7' => BounceClass::Policy,
                $parts['subject'] === '6' => BounceClass::Spam,
                $parts['subject'] === '1' && in_array($parts['detail'], ['1', '2', '3'], true) => $permanent ? BounceClass::Hard : BounceClass::Soft,
                $parts['class'] === '4' => BounceClass::Soft,
                default => $permanent ? BounceClass::Hard : BounceClass::Soft,
            };
        }

        // 4. Végül a nyers kód: 5xx permanens, 4xx átmeneti.
        return $permanent ? BounceClass::Hard : BounceClass::Soft;
    }

    /** @return array{class: string, subject: string, detail: string}|null */
    private function enhancedParts(?string $enhanced): ?array
    {
        if ($enhanced === null || preg_match('/^([245])\.(\d+)\.(\d+)$/', trim($enhanced), $match) !== 1) {
            return null;
        }

        return ['class' => $match[1], 'subject' => $match[2], 'detail' => $match[3]];
    }

    private function fromText(string $text, bool $permanent): ?BounceClass
    {
        return match (true) {
            str_contains($text, 'quota') || str_contains($text, 'mailbox full') || str_contains($text, 'over quota') => BounceClass::MailboxFull,
            str_contains($text, 'blocklist') || str_contains($text, 'blacklist') || str_contains($text, 'blocked')
            || str_contains($text, 'reputation') => BounceClass::Blocked,
            str_contains($text, 'spam') || str_contains($text, 'content rejected') || str_contains($text, 'bulk') => BounceClass::Spam,
            str_contains($text, 'policy') || str_contains($text, 'dmarc') || str_contains($text, 'spf')
            || str_contains($text, 'dkim') => BounceClass::Policy,
            str_contains($text, 'user unknown') || str_contains($text, 'no such user')
            || str_contains($text, 'unknown user') || str_contains($text, 'does not exist')
            || str_contains($text, 'recipient rejected') || str_contains($text, 'host unknown') => $permanent ? BounceClass::Hard : BounceClass::Soft,
            str_contains($text, 'greylist') || str_contains($text, 'try again') || str_contains($text, 'too many') => BounceClass::Soft,
            default => null,
        };
    }
}
