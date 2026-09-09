<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * A HAT MÉRT DIMENZIÓ (75. kritérium) — a ventPay szerződése.
 *
 * ## Miért enum, és miért nem sztring
 *
 * Mert a dimenzió-név a SZÁMLA tétele. Egy elgépelt `'send_counter'` (egyes számban) új,
 * üres dimenziót hozna létre a fogyás-táblában, a valódi küldések pedig egy másik sorba
 * mennének — és a hónap végi kivonatból csendben kimaradna az egyik. A hibát nem a
 * rendszer, hanem a könyvelés venné észre, hónapokkal később.
 *
 * ## HÁROM VISELKEDÉS, nem egy
 *
 * A dimenziók nem egyneműek, és ez a `behaviour()`-ben van kimondva:
 *
 * - **SZÁMLÁLÓ** (`sends_counter`, `validations_counter`, `inbound_counter`): a periódus
 *   alatt ÖSSZEADÓDIK. Minden levél, minden ellenőrzés, minden beérkező levél +1.
 * - **CSÚCS** (`contacts_peak`): a periódus MAXIMUMA. Egy importált, majd kitisztított
 *   listánál a záró szám kevesebb — a rendszer terhelését viszont a csúcs adta (tárolás,
 *   indexelés, szegmens-lekérdezések). A csökkenés NEM írja felül.
 * - **PILLANATKÉP** (`dedicated_ips`, `log_retention_tier`): a jelenlegi állapot. A hónap
 *   közepén két dedikált IP-ből három lesz, és a periódus végén három van — nem öt.
 *
 * Ha ez a három viselkedés a hívási helyeken lenne, valahol összekeveredne: a
 * `dedicated_ips`-t valaki inkrementálná, és a tenant öt IP-t fizetne három helyett.
 */
enum UsageDimension: string
{
    case Sends = 'sends_counter';
    case ContactsPeak = 'contacts_peak';
    case Validations = 'validations_counter';
    case Inbound = 'inbound_counter';
    case DedicatedIps = 'dedicated_ips';
    case LogRetentionTier = 'log_retention_tier';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    public function behaviour(): UsageBehaviour
    {
        return match ($this) {
            self::Sends, self::Validations, self::Inbound => UsageBehaviour::Counter,
            self::ContactsPeak => UsageBehaviour::Peak,
            self::DedicatedIps, self::LogRetentionTier => UsageBehaviour::Snapshot,
        };
    }
}
