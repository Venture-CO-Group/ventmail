<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * A CSOMAG-FUNKCIÓK (76. kritérium) — amit a ventPay engedélyez vagy nem.
 *
 * ## Miért enum, és mit mértünk nélküle
 *
 * A funkció-nevek eddig SZABAD SZTRINGEK voltak a `config('sending.fake_entitlement.features')`
 * listájában és a hívási helyeken. A spec öt funkciót nevez meg — a config viszont csak
 * NÉGYET tartalmazott: a `validation_bulk` kimaradt. Vagyis a bulk-címellenőrzés
 * jogosultsága SOSEM teljesült volna, és a hibát nem a rendszer, hanem egy ügyfél
 * jelentette volna („nem működik a bulk"). Egy elgépelt `'journey'` (egyes számban)
 * ugyanígy csendben megtagadna egy megfizetett funkciót.
 *
 * ## A HTTP-KÓD: 402, nem 403
 *
 * A `403 Forbidden` azt jelenti, hogy „nincs jogod" — a `402 Payment Required` azt, hogy
 * „a csomagod nem tartalmazza". A kettő KÜLÖNBÖZŐ művelet a hívó oldalán: a 403-ra
 * jogosultságot kérünk, a 402-re csomagot váltunk. Egy integráció ezt automatikusan is
 * kezelheti, ha megmondjuk neki, melyikről van szó.
 */
enum Feature: string
{
    /** Customer Journey motor (B3). */
    case Journeys = 'journeys';

    /** Saját küldő domain (a megosztott helyett). */
    case CustomDomain = 'custom_domain';

    /** Dedikált küldő IP + warmup. */
    case DedicatedIp = 'dedicated_ip';

    /** Bejövő routing (MX, route-motor). */
    case InboundRoutes = 'inbound_routes';

    /** Kötegelt címellenőrzés (az egyedi mindig elérhető). */
    case ValidationBulk = 'validation_bulk';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }
}
