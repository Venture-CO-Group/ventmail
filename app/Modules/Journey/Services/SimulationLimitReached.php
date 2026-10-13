<?php

declare(strict_types=1);

namespace App\Modules\Journey\Services;

use RuntimeException;

/** A teszt-futtatás elérte a kiértékelt feltételek plafonját (review SEC-S1 / SEC-S3). */
final class SimulationLimitReached extends RuntimeException {}
