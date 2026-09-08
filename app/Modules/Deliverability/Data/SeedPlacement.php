<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Data;

use InvalidArgumentException;

/** Egy seed-postafiók eredménye: hova érkezett a levél. */
final readonly class SeedPlacement
{
    public const INBOX = 'inbox';

    public const SPAM = 'spam';

    public const PROMOTIONS = 'promotions';

    /** Sehova nem érkezett meg — ez a legrosszabb eset, és a leggyakrabban elhallgatott. */
    public const MISSING = 'missing';

    /** @var array<string, string> a szolgáltatók mappaneveinek leképezése a mi vödreinkre */
    private const ALIASES = [
        'inbox' => self::INBOX,
        'primary' => self::INBOX,          // Gmail: az elsődleges fül A beérkező
        'spam' => self::SPAM,
        'junk' => self::SPAM,              // Outlook
        'bulk' => self::SPAM,              // Yahoo
        'promotions' => self::PROMOTIONS,
        'updates' => self::PROMOTIONS,     // Gmail-fülek: megérkezett, de NEM a beérkezőbe
        'forums' => self::PROMOTIONS,
        'social' => self::PROMOTIONS,
        'other' => self::PROMOTIONS,       // Outlook „Egyéb"
        'missing' => self::MISSING,
    ];

    public function __construct(
        public string $address,
        public string $provider,
        public string $folder,
    ) {
        // A mappanév KÍVÜLRŐL jön. Ellenőrizetlenül átvéve egy ismeretlen név (`updates` — a
        // Gmail valódi fülneve) `Undefined array key` figyelmeztetést adott, a levél pedig
        // ELTŰNT a nevezőből: két seed-címből egy `inbox`, egy `updates` → 100% beérkező.
        // Pont az a szépítés, ami ellen az egész modul íródott.
        if (! in_array($folder, [self::INBOX, self::SPAM, self::PROMOTIONS, self::MISSING], true)) {
            throw new InvalidArgumentException(sprintf(
                'Ismeretlen seed-mappa: %s. A nyers szolgáltatói nevet a `bucket()` fordítja le.',
                $folder,
            ));
        }
    }

    /**
     * A NYERS szolgáltatói mappanév a mi vödreinkre fordítva.
     *
     * Ismeretlen névre `null` — a hívó dolga eldönteni, mit kezd vele. A csendes „inbox"
     * alapérték itt a lehető legrosszabb választás lenne: az ismeretlen fül nem beérkező.
     */
    public static function bucket(string $folder): ?string
    {
        return self::ALIASES[strtolower(trim($folder))] ?? null;
    }
}
