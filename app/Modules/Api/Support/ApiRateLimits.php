<?php

declare(strict_types=1);

namespace App\Modules\Api\Support;

/**
 * A `/v1` rate-limit keretei (percenként) — EGY helyen, mert a limiter (`AppServiceProvider`)
 * és a fejlesztői dokumentáció (`DeveloperGuides`) is ezt olvassa: a doksi nem írhat mást,
 * mint ami fut (UI-polírozás · 9).
 */
final class ApiRateLimits
{
    /** A kulcs kerete — a normál működésé. */
    public const PER_KEY = 300;

    /** A valódi TCP-partner plafonja a kulcs-forgató támadó ellen. */
    public const PER_PEER = 1200;

    /** Kulcs nélküli (hitelesítetlen) forgalom, partnerenként. */
    public const ANONYMOUS_PER_PEER = 60;
}
