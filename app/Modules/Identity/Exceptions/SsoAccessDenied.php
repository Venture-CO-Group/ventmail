<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * A ventSSO-alany érvényes, de a ventMailben nincs keresnivalója (nincs szerep, tagság, meghívó).
 *
 * KÜLÖN típus, mert csak ez kap „nincs hozzáférésed" oldalt: minden más hitelesítési hiba
 * (state, token, impersonáció) újrabejelentkezésre visz, és a belső okot nem mutatja.
 */
final class SsoAccessDenied extends AuthorizationException {}
