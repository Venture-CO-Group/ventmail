<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * Hogyan viselkedik egy fogyás-dimenzió a periódus alatt.
 *
 * A három viselkedés SQL-ben más ütközési ágat jelent (`value + n`, `greatest`, felülírás),
 * és ez a különbség a `Metering`-ben egyetlen helyen dől el. Lásd a `UsageDimension`
 * docblockját arról, mi történne, ha a hívási helyeken keveredne össze.
 */
enum UsageBehaviour: string
{
    /** ÖSSZEADÓDIK: minden esemény +n. */
    case Counter = 'counter';

    /** A PERIÓDUS MAXIMUMA: a csökkenés nem írja felül. */
    case Peak = 'peak';

    /** A JELENLEGI állapot: felülír. */
    case Snapshot = 'snapshot';
}
