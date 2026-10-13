<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

/**
 * Egy sor MINŐSÍTÉSÉNEK kódjai és a statisztikai vödrök, amikbe esnek.
 *
 * A kód a hibariportba és az előnézet mintájába is kerül; a vödör a felületen látható
 * számláló (`stats`). Egy sor pontosan EGY kódot kap: a sorrend (hibás → duplikátum →
 * tiltott → szerepcím → eldobható → létező → új) a prioritás.
 */
final class ImportRowVerdict
{
    public const INVALID_EMAIL = 'invalid_email';

    public const MISSING_EMAIL = 'missing_email';

    public const INVALID_FIELD = 'invalid_field';

    public const DUPLICATE = 'duplicate';

    /** Tiltólista-találat — globális és workspace-szintű EGYETLEN kód (tenantközi orákulum ellen). */
    public const SUPPRESSED = 'suppressed';

    public const ERASED = 'erased';

    public const REVOKED_CONSENT = 'revoked_consent';

    public const DELETED_CONTACT = 'deleted_contact';

    public const ROLE_ADDRESS = 'role_address';

    public const DISPOSABLE_DOMAIN = 'disposable_domain';

    public const EXISTING = 'existing_contact';

    /** @var array<string, string> kód → vödör */
    public const BUCKETS = [
        self::INVALID_EMAIL => 'invalid',
        self::MISSING_EMAIL => 'invalid',
        self::INVALID_FIELD => 'invalid',
        self::DUPLICATE => 'duplicate',
        self::SUPPRESSED => 'suppressed',
        self::ERASED => 'suppressed',
        self::REVOKED_CONSENT => 'suppressed',
        self::DELETED_CONTACT => 'suppressed',
        self::ROLE_ADDRESS => 'role',
        self::DISPOSABLE_DOMAIN => 'disposable',
        self::EXISTING => 'existing',
    ];

    /** Hibás sor (nem „kihagyott"): a felületen a „Hibás" számlálóba kerül. */
    public static function isError(string $code): bool
    {
        return self::BUCKETS[$code] === 'invalid';
    }
}
