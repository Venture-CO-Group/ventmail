<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

use App\Modules\Domains\Models\Domain;
use RuntimeException;
use Symfony\Component\Mime\Crypto\DkimSigner as SymfonyDkimSigner;
use Symfony\Component\Mime\Email;

/**
 * DKIM-aláírás a küldésnél (21. kritérium).
 *
 * A KRIPTÓT NEM MI ÍRJUK: a Symfony `DkimSigner`-je végzi (RFC 6376, rsa-sha256,
 * relaxed/relaxed). Egy saját kanonikalizálás pontosan ott hibázna, ahol nem látjuk — a
 * fogadó oldalán, ahol a levél már spam-be került. A mi felelősségünk a KULCS-VÁLASZTÁS:
 *
 * - a `signingSelector()` (M0-4) dönt, és rotáció alatt SZÁNDÉKOSAN a RÉGI kulcsot adja:
 *   az új selector DNS-rekordja ekkor még nem propagált, tehát az új kulccsal aláírt levél
 *   verifikálhatatlan lenne a fogadónál (golden rule 8);
 * - kulcs nélküli domainről NINCS aláíratlan küldés — hangos hiba, nem csendes kihagyás.
 *
 * Az aláírt fejlécek közt ott a `From`, `To`, `Subject`, `Date` és a `Message-ID`: enélkül
 * a levél útközben ÁTÍRHATÓ lenne úgy, hogy az aláírás érvényes marad.
 */
final class DkimSigner
{
    /** Ezek a fejlécek aláírva utaznak. A `From` a legfontosabb: az a feladó identitása. */
    private const SIGNED_HEADERS = ['from', 'to', 'subject', 'date', 'message-id'];

    public function sign(Email $email, Domain $domain): string
    {
        $selector = $domain->signingSelector();
        $privateKey = $this->privateKeyFor($domain, $selector);

        if ($selector === null || $privateKey === null || $privateKey === '') {
            throw new RuntimeException(sprintf(
                'A(z) %s domainhez nincs DKIM-kulcs — aláíratlan levelet nem küldünk ki '
                .'(golden rule 8).',
                $domain->name
            ));
        }

        $signer = new SymfonyDkimSigner($privateKey, (string) $domain->name, $selector);

        $signed = $signer->sign($email, [
            'algorithm' => SymfonyDkimSigner::ALGO_SHA256,
            'headers_to_ignore' => [],
            'header_canon' => 'relaxed',
            'body_canon' => 'relaxed',
            'headers' => self::SIGNED_HEADERS,
        ]);

        return $signed->toString();
    }

    /**
     * A selectorhoz TARTOZÓ privát kulcs.
     *
     * Rotáció alatt az aktív selector az ELŐZŐ kulcspárhoz tartozik — ha itt a friss
     * privát kulcsot adnánk a régi selectorral, minden levél aláírása érvénytelen lenne.
     */
    private function privateKeyFor(Domain $domain, ?string $selector): ?string
    {
        if ($selector !== null && $selector === $domain->dkim_previous_selector) {
            return $domain->dkim_previous_private_key;
        }

        return $domain->dkim_private_key;
    }
}
