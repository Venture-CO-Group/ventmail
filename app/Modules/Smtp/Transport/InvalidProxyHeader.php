<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Transport;

use RuntimeException;

/** A PROXY protocol fejléc hiányzik vagy érvénytelen — a kapcsolatot bontjuk (fail-closed). */
final class InvalidProxyHeader extends RuntimeException {}
