<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use RuntimeException;

/**
 * Az aktív kampányhoz / journey-hez kötött sablon írása publikálási jog nélkül (review B1 → SEC4-S1).
 *
 * A mentő tranzakción BELÜL, a sablon-sor zárja alatt dobódik — a kontroller előellenőrzése csak
 * kényelem (gyors hibaüzenet), a határ ez. Az üzenet a felhasználó nyelvén, a használatok nevével.
 */
final class TemplatePublishLocked extends RuntimeException {}
